<?php

declare(strict_types=1);

namespace Modules\Tasks\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Tasks\Application\TaskService;
use Modules\Tasks\Domain\TaskStatus;
use Modules\Tasks\Domain\TaskType;
use Modules\Tasks\Infrastructure\Eloquent\Task;

/**
 * Work queues (ADR 0024).
 *
 * THE ACCEPTANCE CRITERION THIS CONTROLLER IS SHAPED BY: *linked entity access is still policy
 * checked.* The master command puts it precisely — team membership alone must not grant access to
 * a linked sensitive entity.
 *
 * It is held two ways, and the first matters more:
 *
 *  1. **A task payload carries nothing worth reading.** A type, an opaque identifier, and a short
 *     instruction written by whoever raised it. There is no case title, no beneficiary name, no
 *     narrative — so seeing a task discloses nothing about its subject, and the question "may
 *     this person see the case behind it" never arises here.
 *  2. **The subject is opened through its own module's endpoint**, which does its own check. A
 *     task holds a pointer, not a key.
 *
 * A design that put a case summary on the queue row would need a permission check per row, and
 * the first time somebody added a field it would be forgotten. This one has nothing to forget.
 */
final class TaskController
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The queues: mine, my team's, due today, overdue, upcoming.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->tasks->query();

        // "My tasks" resolves from the token, never from a parameter: a queue filtered by an
        // account id in the query string is a queue anybody can point at anybody.
        if ($request->boolean('mine')) {
            $query->where('assigned_to', $actor->subjectId);
        }

        foreach (['status', 'type', 'priority', 'team', 'subject_type'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $today = Carbon::now();

        $query = match (true) {
            $request->boolean('overdue') => $this->tasks->overdue($query, $today),
            $request->boolean('due_today') => $this->tasks->dueOn($query, $today),
            $request->boolean('upcoming') => $this->tasks->upcoming($query, $today),
            default => $query,
        };

        $query = $this->tasks->inWorkingOrder($query, $today);

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Task $task): array => $this->projection($task),
        );
    }

    public function show(Request $request, ActorContext $actor, string $task): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskView);

        return ApiResponse::item($this->projection($this->taskOrFail($task)));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskManage);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', TaskType::values())],
            'title' => ['required', 'string', 'max:160'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:64'],
            'team' => ['sometimes', 'nullable', 'string', 'max:48'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ]);

        return ApiResponse::created($this->projection($this->tasks->open($validated, $actor)));
    }

    /**
     * Closes a task.
     *
     * RECORDS AN OUTCOME AND NOTHING ELSE. Completing "confirm the release" does not confirm the
     * release — it records that somebody says they did. The case, the referral and the release
     * behind a task are untouched by this endpoint, which is the acceptance criterion
     * "completing a task can record outcome without silently changing unrelated case state".
     */
    public function close(Request $request, ActorContext $actor, string $task): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskManage);

        $model = $this->taskOrFail($task);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:done,cancelled'],
            'outcome' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->projection($this->tasks->close(
            $model,
            TaskStatus::from($validated['status']),
            $validated['outcome'],
            $actor,
        )));
    }

    public function assign(Request $request, ActorContext $actor, string $task): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskManage);

        $model = $this->taskOrFail($task);

        $validated = $request->validate([
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:64'],
            'team' => ['sometimes', 'nullable', 'string', 'max:48'],
        ]);

        return ApiResponse::item($this->projection($this->tasks->assign(
            $model,
            $validated['assigned_to'] ?? null,
            $validated['team'] ?? null,
            $actor,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Task $task): array
    {
        return [
            'id' => $task->uuid,
            'type' => $task->type->value,
            'title' => $task->title,
            /*
             * A TYPE AND AN OPAQUE IDENTIFIER. Deliberately nothing else — no case number, no
             * beneficiary name, no status of the thing behind it. The client follows the pointer
             * to that module's own endpoint, which does its own authorization; a summary here
             * would disclose the subject to everyone who can see the queue (ADR 0024 §2).
             */
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'assigned_to' => $task->assigned_to,
            'team' => $task->team,
            'priority' => $task->priority->value,
            'status' => $task->status->value,
            'due_on' => $task->due_on?->toDateString(),
            'is_overdue' => $task->isOverdue(),
            'outcome' => $task->outcome,
            // So a worker can tell "the system noticed this" from "a colleague asked me to do
            // this" — they carry different weight, and hiding the difference trains people to
            // ignore the automatic ones.
            'raised_by_event' => $task->raised_by_event,
            'completed_at' => $task->completed_at?->toIso8601ZuluString(),
        ];
    }

    private function taskOrFail(string $uuid): Task
    {
        /** @var Task|null $task */
        $task = Task::query()->where('uuid', $uuid)->first();

        if ($task === null) {
            throw ResourceNotFoundException::make('That task was not found.');
        }

        return $task;
    }
}
