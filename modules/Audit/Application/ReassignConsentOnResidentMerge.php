<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Modules\Audit\Infrastructure\Eloquent\ConsentRecord;
use Modules\ResidentProfile\Contracts\ResidentMerged;

/**
 * A merge repoints consent records (ADR 0019 §4, ADR 0034 §4).
 *
 * CAUGHT BY `ResidentMergeCoverageTest` the moment `consent_records.resident_id` was added, which
 * is the third time that test has earned itself. Without this, a merge leaves a consent pointing
 * at a soft-deleted resident — so a photograph published under a consent the office can no longer
 * find is a photograph published under no consent at all, and nothing anywhere would fail.
 *
 * NO COLLISION IS POSSIBLE HERE, unlike the enrolment and event-registration cases. The uniqueness
 * constraint on a live consent is `(subject_id, active_key)` — keyed on the **account** that gave
 * it, not on the resident it concerns — so two records about the same person can be repointed to
 * one resident without ever colliding. That is a consequence of consent belonging to whoever gave
 * it: a guardian consenting for a minor is one account and one resident, and merging the minor's
 * duplicate records does not merge the guardians.
 *
 * A listener rather than a call, because `Audit` depends on `ResidentProfile\Contracts` and the
 * reverse call would close a cycle. Note that `ResidentProfile` does NOT depend on `Audit` — its
 * audit writer depends on the interface in `Shared` (ADR 0034 §1), which is what keeps this safe.
 */
final class ReassignConsentOnResidentMerge
{
    public function handle(ResidentMerged $event): int
    {
        return ConsentRecord::query()
            ->where('resident_id', $event->absorbedResidentUuid)
            ->update(['resident_id' => $event->survivorResidentUuid]);
    }
}
