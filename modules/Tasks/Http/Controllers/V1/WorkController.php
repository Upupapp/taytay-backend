<?php

declare(strict_types=1);

namespace Modules\Tasks\Http\Controllers\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Http\ApiResponse;
use Modules\Tasks\Application\WorkQueueService;
use Modules\Tasks\Infrastructure\Eloquent\Task;

/**
 * The three work-queue reads (TAB 07), closing `myQueue`, `teamQueue` and `alerts`.
 *
 * All three are **read-only**. Acting on an item goes to the record's own endpoint —
 * `POST tasks/{task}/closure`, `POST tasks/{task}/assignment` — which already exist and already
 * audit. A queue that could also mutate would be a second write path to the same row.
 *
 * ── `as_of` IS ECHOED, AND THAT IS NOT DECORATION ────────────────────────────────────
 *
 * Every response carries the date the urgencies were computed against. Lateness here is derived
 * from a stored date against the clock at the moment of the request, so two screens open at
 * midnight can legitimately disagree — and a client that renders "3 overdue" with no idea when
 * that was true has no way to notice.
 */
final class WorkController
{
    public function __construct(
        private readonly WorkQueueService $work,
        private readonly AuthorizationService $authorization,
    ) {}

    /** What the signed-in user owes. */
    public function mine(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskView);

        $asOf = CarbonImmutable::now();

        /*
         * Resolved from the token, never from a parameter. There is deliberately no `?assignee=`
         * on this route: "mine" that can be pointed at a colleague is not "mine", and the
         * supervision view below is where reading somebody else's load belongs — behind a
         * different permission, on purpose.
         *
         * An actor with no subject id (a machine context) gets an empty queue rather than
         * everybody's.
         */
        $query = $actor->subjectId === null
            ? $this->work->forAssignee('')->whereRaw('1 = 0')
            : $this->work->forAssignee($actor->subjectId);

        $pagination = PaginationParams::fromRequest($request);
        $total = (clone $query)->count();

        $rows = $query->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderBy('id')
            ->forPage($pagination->page, $pagination->perPage)
            ->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Task $task): array => $this->itemProjection($task, $asOf),
            ['as_of' => $asOf->toDateString(), 'owner_id' => $actor->subjectId],
        );
    }

    /**
     * What the office owes, grouped by who is carrying it.
     *
     * **`staff.view`, not `task.view`.** Seeing another officer's caseload is supervision rather
     * than a default, and the two permissions separate exactly there: a caseworker reads their own
     * round, a supervisor reads the office's. Granting this under `task.view` would have made
     * every holder of a work queue a reader of everybody's.
     */
    public function team(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffView);

        $asOf = CarbonImmutable::now();

        $groups = $this->work->byAssignee($asOf);

        return ApiResponse::page(
            Page::fromArray($groups, PaginationParams::fromRequest($request)),
            null,
            [
                'as_of' => $asOf->toDateString(),
                // Reported as its own figure, not left to be inferred from a row a client might
                // page past. Unassigned work is the thing this screen exists to surface.
                'unassigned_count' => $this->unassignedTotal($groups),
            ],
        );
    }

    /** Conditions of the data. Nobody completes one; somebody fixes the record and it goes. */
    public function alerts(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::TaskView);

        $asOf = CarbonImmutable::now();

        return ApiResponse::page(
            Page::fromArray($this->work->alerts($asOf), PaginationParams::fromRequest($request)),
            null,
            ['as_of' => $asOf->toDateString()],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function unassignedTotal(array $groups): int
    {
        foreach ($groups as $group) {
            if ($group['assigned_to'] === null) {
                return (int) $group['total'];
            }
        }

        return 0;
    }

    /**
     * One item.
     *
     * A **type and an opaque identifier**, and no summary of the subject behind it — ADR 0024 §2,
     * kept because a queue is the screen designed to be scanned by somebody reviewing other
     * people's work. A preview line on every row would disclose the subject to everyone who can
     * see the queue.
     *
     * @return array<string, mixed>
     */
    private function itemProjection(Task $task, CarbonImmutable $asOf): array
    {
        return [
            'id' => $task->uuid,
            'source' => 'task',
            'type' => $task->type->value,
            'title' => $task->title,
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'assigned_to' => $task->assigned_to,
            'team' => $task->team,
            'priority' => $task->priority->value,
            'due_on' => $task->due_on?->toDateString(),
            // Derived against `as_of`, never stored. A flag written by a nightly job is wrong
            // every morning until it runs (DL-83).
            'is_overdue' => $task->due_on !== null && $task->due_on->lt($asOf->startOfDay()),
            /*
             * When the clock started, for an item with no date. The console renders "Waiting 9
             * days" rather than "3 days overdue" for exactly these: no service standard has been
             * supplied, and reporting lateness against a target nobody set would be fabricating
             * policy (DL-101).
             */
            'waiting_since' => $task->due_on === null ? $task->created_at?->toDateString() : null,
            'raised_by_event' => $task->raised_by_event,
        ];
    }
}
