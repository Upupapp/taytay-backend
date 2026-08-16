<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceDraft;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceIntake;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Moves a merged resident's welfare records onto the surviving record (ADR 0019 §4).
 *
 * CLOSES A DEFECT INTRODUCED IN TAB 11. `ResidentMergeService` repoints accounts, credentials,
 * KYC cases, sectors and corrections — the consumers that existed when it was written. Welfare
 * arrived afterwards and nothing connected it, so a merge left `welfare_cases.resident_id`
 * pointing at a soft-deleted resident: the case survived, the person it was about did not, and
 * the applicant's own `me/cases` would have gone empty while staff continued working the file.
 *
 * Nothing failed loudly, which is why it needed looking for rather than waiting for.
 *
 * The inversion is the same one Credential uses: ResidentProfile announces the merge and knows
 * nothing about who cares; this listener is registered in **Welfare's own** provider, which is
 * what keeps the dependency one-directional and the graph acyclic (ADR 0013 §6).
 *
 * Runs synchronously inside the merge transaction. A queued handler would let the merge commit
 * while cases still pointed at a soft-deleted resident — the exact window this exists to close.
 */
final class ReassignWelfareRecordsOnResidentMerge
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * Returns how many welfare cases moved, for the merge record's reassignment counts.
     */
    public function handle(ResidentMerged $event): int
    {
        if ($event->absorbedResidentUuid === $event->survivorResidentUuid) {
            return 0;
        }

        return DB::transaction(function () use ($event): int {
            /*
             * Enrolments first, and through the service rather than a bare UPDATE.
             *
             * If both records were enrolled on the same programme, moving both would leave the
             * survivor with two open enrolments — counted twice on every roll and every payment
             * run. The service collapses the overlap; a blanket update would create it.
             */
            $this->enrollments->reassignOnMerge($event->absorbedResidentUuid, $event->survivorResidentUuid);

            AssistanceIntake::query()
                ->where('resident_id', $event->absorbedResidentUuid)
                ->update(['resident_id' => $event->survivorResidentUuid]);

            // Unsubmitted drafts follow the person too: an applicant mid-form whose record was
            // merged should find their work where they left it, not gone.
            AssistanceDraft::query()
                ->where('resident_id', $event->absorbedResidentUuid)
                ->whereNull('submitted_at')
                ->update(['resident_id' => $event->survivorResidentUuid]);

            return WelfareCase::query()
                ->where('resident_id', $event->absorbedResidentUuid)
                ->update(['resident_id' => $event->survivorResidentUuid]);
        });
    }
}
