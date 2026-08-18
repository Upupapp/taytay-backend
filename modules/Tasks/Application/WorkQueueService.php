<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tasks\Domain\TaskStatus;
use Modules\Tasks\Infrastructure\Eloquent\Task;

/**
 * Work queues, derived on every read (TAB 07).
 *
 * The command's constraint, in full: *"Read-only and derived server-side. An item is a view of a
 * record; acting on it goes to that record's own endpoint. Overdue is derived from the follow-up
 * date, never stored — a stored flag needs a nightly job and is wrong every morning until it
 * runs."*
 *
 * ── THERE IS NO SECOND TASK SYSTEM ───────────────────────────────────────────────────
 *
 * Every item here is a row in `tasks`. Nothing in this file writes, and there is no endpoint that
 * closes or reassigns work through the queue — `POST tasks/{task}/closure` and
 * `.../assignment` already exist and are where those acts belong. A queue that could also mutate
 * would be a second write path to the same record, and the two disagree the first time one of
 * them forgets to append an audit entry.
 *
 * That also answers why the aggregation point is `tasks` rather than a union over five modules:
 * {@see RaiseTaskOnReferralOverdue} and {@see RaiseTaskOnVisitFollowUp} already funnel other
 * modules' obligations into this table. Querying those modules directly would be a second
 * aggregation of the same facts, and it would need cross-module joins that Article 2.2 forbids.
 *
 * ── WHAT THE QUEUE REFUSES TO SAY ────────────────────────────────────────────────────
 *
 * A task carries a `subject_type` and an opaque `subject_id` and **no summary of the subject** —
 * no resident name, no case number, no status of the thing behind it. That is ADR 0024 §2 and it
 * is kept here deliberately: a queue is the one screen designed to be scanned by somebody
 * reviewing other people's work, and a preview line on every row would disclose the subject to
 * everyone who can see the queue.
 *
 * The console's `WorkItem` has `subject` and `preview` fields expecting exactly that. They are
 * left unfilled rather than populated, and the divergence is recorded as G-25 — the console's own
 * `DL-109` reaches the same conclusion about search snippets, so this is likely to resolve in the
 * API's favour.
 */
final class WorkQueueService
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * What one person owes.
     *
     * @return Builder<Task>
     */
    public function forAssignee(string $subjectId): Builder
    {
        return $this->openWork()->where('assigned_to', $subjectId);
    }

    /**
     * What the office owes, grouped by who is carrying it.
     *
     * **Unassigned work is its own group, never an omission.** A queue that silently dropped it
     * would report an office as fully allocated while nobody had picked up the oldest item on it —
     * which is the exact failure a supervision screen exists to catch.
     *
     * @return list<array<string, mixed>>
     */
    public function byAssignee(?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();

        $groups = [];

        /*
         * One query, grouped in the database. Loading every open task and grouping in PHP would be
         * the N+1's larger cousin: correct on a demo dataset and an incident on a busy morning.
         */
        $rows = $this->openWork()
            ->selectRaw('assigned_to, count(*) as total')
            ->selectRaw('sum(case when due_on is not null and due_on < ? then 1 else 0 end) as overdue', [$on->toDateString()])
            ->groupBy('assigned_to')
            ->get();

        foreach ($rows as $row) {
            $groups[] = [
                'assigned_to' => $row->assigned_to,
                'total' => (int) $row->total,
                // Derived from the date against the clock, every read. No stored flag to go stale
                // overnight, which is the same rule DL-83 applies to referral lateness.
                'overdue_count' => (int) $row->overdue,
            ];
        }

        // Unassigned first: it is the group with nobody watching it.
        usort($groups, static fn (array $a, array $b): int => [$a['assigned_to'] !== null, $a['assigned_to'] ?? '']
            <=> [$b['assigned_to'] !== null, $b['assigned_to'] ?? '']);

        return $groups;
    }

    /**
     * Conditions of the data worth somebody's attention.
     *
     * **An alert is not a task.** Nobody completes one — somebody fixes the record and it stops
     * being true on the next read. So none of these is stored, and there is no acknowledge or
     * dismiss endpoint: an alert you can dismiss without fixing anything is a checkbox that trains
     * an office to ignore the real ones.
     *
     * Each states the rule that produced it and what it counted, for the reason every advisory in
     * this system does: an alert nobody can check is one an office learns to dismiss.
     *
     * ── WHY ONLY TWO ─────────────────────────────────────────────────────────────────
     *
     * These are the conditions **this module can see**. A "possible duplicate residents awaiting
     * review" alert belongs here too and is not built, because Tasks may not read
     * `ResidentProfile`'s tables (Article 2.1) and ResidentProfile publishes no contract for
     * pending duplicate pairs. Adding one is a small change in that module; inventing a
     * cross-module join here to avoid asking would be the boundary violation the architecture
     * tests exist to catch. Recorded as G-26.
     *
     * @return list<array<string, mixed>>
     */
    public function alerts(?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();

        $alerts = [];

        $unassigned = (clone $this->openWork())->whereNull('assigned_to')->count();

        if ($unassigned > 0) {
            $alerts[] = [
                'kind' => 'unassigned-work',
                'severity' => 'warning',
                'summary' => "{$unassigned} open ".($unassigned === 1 ? 'task has' : 'tasks have').' nobody assigned.',
                'basis' => 'Counted open tasks with no assignee at the moment of this request.',
                'permission' => 'task.view',
                'detected_from' => $unassigned,
            ];
        }

        $overdue = (clone $this->openWork())
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', $on->toDateString())
            ->count();

        if ($overdue > 0) {
            $alerts[] = [
                'kind' => 'overdue-work',
                'severity' => 'warning',
                'summary' => "{$overdue} open ".($overdue === 1 ? 'task is' : 'tasks are').' past the date somebody set for it.',
                // Named precisely: this is lateness against a date a person chose, not against a
                // service standard. No service standard has been supplied, and reporting one would
                // be fabricating policy the office never adopted.
                'basis' => "Counted open tasks whose due_on is earlier than {$on->toDateString()}.",
                'permission' => 'task.view',
                'detected_from' => $overdue,
            ];
        }

        return $alerts;
    }

    /** @return Builder<Task> */
    private function openWork(): Builder
    {
        return $this->tasks->query()->where('status', TaskStatus::Open->value);
    }
}
