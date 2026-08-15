<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Collection;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMatchCandidate;

/**
 * Finds residents who might already be the person a KYC case describes (ADR 0010 §3).
 *
 * IT DECIDES NOTHING. It produces candidates with the rule that found them and a coarse
 * confidence band, and hands them to a reviewer. There is no threshold at which this class
 * links, merges or approves anything.
 *
 * That restraint is the design. A wrong automatic merge in a welfare registry means one
 * person collecting another person's assistance while the second becomes invisible to the
 * LGU — and it is close to unrecoverable, because the evidence of two people has already
 * been collapsed into one row. Making a reviewer look is cheap by comparison.
 */
final class ResidentMatcher
{
    /**
     * Re-runs matching for a case, refreshing candidates in place.
     *
     * Idempotent: running it twice produces the same set rather than stacking duplicates
     * in front of the reviewer, and a decision a reviewer has already made is preserved.
     *
     * @return Collection<int, ResidentMatchCandidate>
     */
    public function screen(KycCase $case): Collection
    {
        $found = [];

        foreach ($this->exactIdentityMatches($case) as $resident) {
            $found[$resident->id] = ['rule' => 'name-and-birth-date', 'confidence' => 'exact'];
        }

        foreach ($this->sameNameDifferentBirthDate($case) as $resident) {
            // Weaker, and deliberately still surfaced: a transposed birth date is one of
            // the commonest ways the same person appears twice in a registry.
            $found[$resident->id] ??= ['rule' => 'name-only', 'confidence' => 'partial'];
        }

        foreach ($this->sameBirthDateAndFamilyName($case) as $resident) {
            $found[$resident->id] ??= ['rule' => 'family-name-and-birth-date', 'confidence' => 'strong'];
        }

        foreach ($found as $residentId => $match) {
            ResidentMatchCandidate::query()->updateOrCreate(
                ['kyc_case_id' => $case->id, 'resident_id' => $residentId],
                $match,
            );
        }

        // Candidates that no longer match are dropped, unless a reviewer has already
        // ruled on them — their judgement is evidence and outlives the rule that raised it.
        ResidentMatchCandidate::query()
            ->where('kyc_case_id', $case->id)
            ->whereNotIn('resident_id', array_keys($found) ?: [0])
            ->where('decision', 'undecided')
            ->delete();

        return $case->candidates()->with([])->get();
    }

    /**
     * Exactly the same normalised name and birth date.
     *
     * Still only a candidate. Two people genuinely do share a name and a birthday, and a
     * registry that assumes otherwise merges siblings and cousins.
     *
     * @return Collection<int, Resident>
     */
    private function exactIdentityMatches(KycCase $case): Collection
    {
        return Resident::query()
            ->where('identity_fingerprint', $case->identity_fingerprint)
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, Resident> */
    private function sameNameDifferentBirthDate(KycCase $case): Collection
    {
        return Resident::query()
            ->whereRaw('lower(last_name) = ?', [IdentityFingerprint::normalise($case->claimed_last_name)])
            ->whereRaw('lower(first_name) = ?', [IdentityFingerprint::normalise($case->claimed_first_name)])
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, Resident> */
    private function sameBirthDateAndFamilyName(KycCase $case): Collection
    {
        return Resident::query()
            ->whereRaw('lower(last_name) = ?', [IdentityFingerprint::normalise($case->claimed_last_name)])
            ->whereDate('birth_date', $case->claimed_birth_date)
            ->limit(25)
            ->get();
    }
}
