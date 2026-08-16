<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Infrastructure\Eloquent\CaseAssignment;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Who holds a case, and who held it before (ADR 0016 §3).
 *
 * ROUTING IS ASSIGNMENT, NOT STATE (ADR 0007 §5). A case in `Assessment` routed to the health
 * office is one state and one assignee — not a state called "for health office review".
 * Encoding the destination in the state name multiplies states by offices and was the specific
 * defect that made the citizen app's 17-state lifecycle unusable.
 *
 * Effective-dated, like household membership. Reassignment closes one row and opens another,
 * so "who was responsible on the 12th" survives — a different question from "what state was
 * it in", and the one asked first when something has gone wrong.
 */
final class CaseAssignmentService
{
    public function __construct(private readonly WelfareAudit $audit) {}

    /**
     * Assigns a case to a person, optionally within a team.
     *
     * Idempotent: reassigning to the current holder returns the open row rather than churning
     * the history with a no-op handover.
     */
    public function assign(
        WelfareCase $case,
        string $assigneeSubjectId,
        ActorContext $actor,
        ?string $team = null,
    ): CaseAssignment {
        return DB::transaction(function () use ($case, $assigneeSubjectId, $actor, $team): CaseAssignment {
            /** @var WelfareCase $case */
            $case = WelfareCase::query()->lockForUpdate()->findOrFail($case->id);

            if ($case->status->isTerminal()) {
                // A closed file has nobody working it. Assigning one produces a queue entry
                // for work that will never happen, and it is how "my cases" fills with noise.
                throw new ApiException(ErrorCode::Conflict, 'A closed case cannot be assigned.');
            }

            /** @var CaseAssignment|null $open */
            $open = CaseAssignment::query()
                ->where('welfare_case_id', $case->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                if ((string) $open->assignee_subject_id === $assigneeSubjectId) {
                    return $open;
                }

                $open->forceFill([
                    'unassigned_at' => now(),
                    'unassigned_reason' => 'reassigned',
                ])->save();
            }

            $assignment = CaseAssignment::query()->create([
                'welfare_case_id' => $case->id,
                'assignee_subject_id' => $assigneeSubjectId,
                'team' => $team,
                'assigned_at' => now(),
                'assigned_by' => $actor->subjectId,
            ]);

            // The column on the case is the fast current answer; this table is the history
            // behind it. Written together, in one transaction, so they cannot disagree
            // (ADR 0008 §10).
            $case->forceFill([
                'assigned_to' => $assigneeSubjectId,
                'assigned_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'case.assigned',
                'Welfare case assigned',
                (string) $case->uuid,
            );

            return $assignment;
        });
    }

    /**
     * Returns a case to the unassigned queue.
     *
     * An unassigned open case is a normal and important state — it is the backlog — so this is
     * a first-class operation rather than something achieved by assigning to a placeholder.
     */
    public function unassign(WelfareCase $case, ActorContext $actor, string $reason): void
    {
        DB::transaction(function () use ($case, $actor, $reason): void {
            /** @var CaseAssignment|null $open */
            $open = CaseAssignment::query()
                ->where('welfare_case_id', $case->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                throw new ApiException(ErrorCode::Conflict, 'That case is not currently assigned.');
            }

            $open->forceFill(['unassigned_at' => now(), 'unassigned_reason' => $reason])->save();

            $case->forceFill([
                'assigned_to' => null,
                'assigned_at' => null,
                'last_activity_at' => now(),
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'case.unassigned',
                'Welfare case returned to the queue',
                (string) $case->uuid,
            );
        });
    }

    /**
     * @return Collection<int, CaseAssignment>
     */
    public function historyFor(WelfareCase $case): Collection
    {
        return CaseAssignment::query()
            ->where('welfare_case_id', $case->id)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();
    }
}
