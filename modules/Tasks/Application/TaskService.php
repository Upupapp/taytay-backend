<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Tasks\Domain\TaskPriority;
use Modules\Tasks\Domain\TaskStatus;
use Modules\Tasks\Domain\TaskType;
use Modules\Tasks\Infrastructure\Eloquent\Task;

/**
 * Work queues (ADR 0024).
 *
 * TWO RULES THIS CLASS EXISTS TO HOLD:
 *
 *  1. **A task never changes what it points at.** Completing "confirm the release" does not
 *     confirm the release; it records that somebody says they did. The master command asks for no
 *     hidden automation that changes case outcomes, and the strongest form of that is a service
 *     that structurally cannot: nothing here imports a case, a release or a referral, so there is
 *     no line to add one to.
 *
 *  2. **A task carries no detail about its subject.** Only a type, an identifier and a short
 *     instruction written by whoever raised it. A queue is read by everyone; a title copied from
 *     a case would disclose the case to all of them.
 */
final class TaskService
{
    public function __construct(private readonly TasksAudit $audit) {}

    /**
     * Raises a task somebody asked for.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes, ActorContext $actor): Task
    {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'A task needs to say what to do.');
        }

        $type = $attributes['type'] instanceof TaskType
            ? $attributes['type']
            : TaskType::from((string) $attributes['type']);

        /** @var Task $task */
        $task = Task::query()->create([
            'type' => $type,
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'title' => $title,
            'assigned_to' => $attributes['assigned_to'] ?? null,
            // So an automatically raised task lands in *a* queue rather than none: an unassigned
            // task with no team is visible only in the view nobody opens.
            'team' => $attributes['team'] ?? $type->defaultTeam(),
            'priority' => TaskPriority::from((string) ($attributes['priority'] ?? 'normal')),
            'due_on' => $attributes['due_on'] ?? null,
            'status' => TaskStatus::Open,
            'created_by' => $actor->subjectId,
            'raised_by_event' => $attributes['raised_by_event'] ?? null,
            'automation_key' => $attributes['automation_key'] ?? null,
        ]);

        return $task;
    }

    /**
     * Closes a task.
     *
     * RECORDS AN OUTCOME AND NOTHING ELSE. This is the acceptance criterion — completing a task
     * must not silently change unrelated case state — and it is held by the absence of any other
     * write in this method, not by a check.
     */
    public function close(Task $task, TaskStatus $status, ?string $outcome, ActorContext $actor): Task
    {
        if (! $task->status->isOpen()) {
            throw new ApiException(ErrorCode::Conflict, 'That task is already closed.');
        }

        if ($status->isOpen()) {
            throw new ApiException(ErrorCode::BadRequest, 'Closing a task needs a closed status.');
        }

        /*
         * `done` needs an outcome because "what happened" is the only thing a completed task
         * leaves behind. `cancelled` needs a reason because a task that silently disappears is
         * indistinguishable from one nobody did.
         */
        if (trim((string) $outcome) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                $status === TaskStatus::Done ? 'Record what happened.' : 'Say why this is no longer needed.',
            );
        }

        $task->forceFill([
            'status' => $status,
            'outcome' => $outcome,
            'completed_by' => $actor->subjectId,
            'completed_at' => now(),
            /*
             * The automation key is released when the task closes, which is what lets the next
             * sweep raise a fresh one if the underlying problem is still there. A key held
             * forever would mean a referral that went overdue, was chased, and went overdue
             * again never produced a second task.
             */
            'automation_key' => null,
        ])->save();

        $this->audit->record($actor->subjectId, 'task.'.$status->value, 'Task '.$status->value, (string) $task->uuid);

        return $task->refresh();
    }

    /**
     * Reassigns a task. Does not touch its subject.
     */
    public function assign(Task $task, ?string $assignee, ?string $team, ActorContext $actor): Task
    {
        if (! $task->status->isOpen()) {
            throw new ApiException(ErrorCode::Conflict, 'That task is closed.');
        }

        $task->forceFill([
            'assigned_to' => $assignee,
            'team' => $team ?? $task->team,
        ])->save();

        return $task->refresh();
    }

    // ── the queues ────────────────────────────────────────────────────────────────────

    /**
     * @return Builder<Task>
     */
    public function query(): Builder
    {
        return Task::query();
    }

    /**
     * Still owed and past its date.
     *
     * THE ACCEPTANCE CRITERION — "overdue tasks are queryable efficiently" — is an index question,
     * and it is answered by `idx_tasks_overdue`, `idx_tasks_mine` and `idx_tasks_team`, each
     * leading with the column that narrows hardest for the way that queue is actually opened.
     *
     * A task with no due date is never overdue: it was never a promise about a date.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function overdue(Builder $query, ?Carbon $on = null): Builder
    {
        return $query
            ->where('status', TaskStatus::Open->value)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', ($on ?? Carbon::now())->toDateString());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function dueOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('status', TaskStatus::Open->value)
            ->whereDate('due_on', $date->toDateString());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function upcoming(Builder $query, ?Carbon $on = null): Builder
    {
        return $query
            ->where('status', TaskStatus::Open->value)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>', ($on ?? Carbon::now())->toDateString());
    }

    /**
     * Overdue first, then most urgent, then soonest — the order a queue is worked in.
     *
     * Ties break on the identifier so two workers opening the same queue in the same second are
     * told to do the same thing.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function inWorkingOrder(Builder $query, ?Carbon $on = null): Builder
    {
        $today = ($on ?? Carbon::now())->toDateString();

        return $query
            ->orderByRaw('CASE WHEN due_on IS NOT NULL AND due_on < ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderBy('id');
    }
}
