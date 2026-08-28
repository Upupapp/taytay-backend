<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentDuplicatePair;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentSector;

/**
 * The published way for other modules to ask about a resident.
 *
 * Returns a {@see ResidentSummary}, never the Eloquent model: a module that receives the
 * model receives the whole record, and the boundary in ADR 0001 becomes advisory. This is
 * the only entry point other modules may use (CLAUDE.md Article 2.1).
 */
final class ResidentDirectory
{
    public function summaryFor(string $residentUuid): ?ResidentSummary
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->first();

        if ($resident === null) {
            return null;
        }

        return new ResidentSummary(
            id: $resident->uuid,
            displayName: $resident->displayName(),
            verificationTier: $resident->verification_tier,
            barangayId: $resident->barangay_id === null ? null : (int) $resident->barangay_id,
        );
    }

    /**
     * Summaries for many residents at once, keyed by UUID.
     *
     * **ADDED BECAUSE `summaryFor()` IN A LOOP IS AN N+1**, and the measurement said so: the staff
     * registrant list ran 11 queries for one row and 18 for eight — one extra per registrant. At a
     * feeding programme with two hundred registrants that is two hundred round trips to render one
     * page, and it degrades exactly when the office is busiest.
     *
     * A caller with one identifier should still use `summaryFor()`. A caller with a list must use
     * this, and `QueryBudgetTest` fails the build if the list endpoints start growing again.
     *
     * @param  list<string>  $residentUuids
     * @return array<string, ResidentSummary>
     */
    public function summariesFor(array $residentUuids): array
    {
        $uuids = array_values(array_unique(array_filter($residentUuids)));

        if ($uuids === []) {
            return [];
        }

        $summaries = [];

        foreach (Resident::query()->whereIn('uuid', $uuids)->get() as $resident) {
            $summaries[(string) $resident->uuid] = new ResidentSummary(
                id: $resident->uuid,
                displayName: $resident->displayName(),
                verificationTier: $resident->verification_tier,
                barangayId: $resident->barangay_id === null ? null : (int) $resident->barangay_id,
            );
        }

        return $summaries;
    }

    /**
     * A **page** of the registry, as summaries, for a module that needs to list residents.
     *
     * Published for TAB 07's beneficiary registry, which lives in `Welfare` because a beneficiary
     * standing is a welfare fact about a person — and because `Welfare` already depends on this
     * module, so putting the projection the other way round would have made the dependency graph
     * cyclic. `ModuleBoundaryTest` caught exactly that on the first attempt.
     *
     * Returns {@see ResidentSummary} objects and a total, never a query builder and never the
     * Eloquent model. Handing back a builder would let the caller filter on income or sectors and
     * the boundary would exist only in the documentation.
     *
     * Barangay scoping is the **caller's** job: it holds the actor. This returns what it is asked
     * for, and `$barangayIds` is how the caller says what its actor may reach — `null` means
     * unrestricted, `[]` means nothing, which is deny-by-default rather than a missing filter.
     *
     * @param  list<int>|null  $barangayIds
     * @return array{summaries: list<ResidentSummary>, total: int}
     */
    public function searchSummaries(?string $term, ?array $barangayIds, int $page, int $perPage): array
    {
        $query = Resident::query();

        if ($barangayIds !== null) {
            $barangayIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('barangay_id', $barangayIds);
        }

        if ($term !== null && trim($term) !== '') {
            $like = '%'.strtolower(trim($term)).'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like]);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('last_name')->orderBy('id')->forPage($page, $perPage)->get();

        return [
            'summaries' => $rows->map(fn (Resident $resident): ResidentSummary => new ResidentSummary(
                id: $resident->uuid,
                displayName: $resident->displayName(),
                verificationTier: $resident->verification_tier,
                barangayId: $resident->barangay_id === null ? null : (int) $resident->barangay_id,
            ))->all(),
            'total' => $total,
        ];
    }

    /**
     * Which of these residents sit in an **undecided** duplicate pair.
     *
     * A set of identifiers and nothing else. The pair, the rule that matched and the resemblance
     * band belong to the duplicate-review queue behind `resident.merge` — *"possible duplicate of
     * somebody"* is a claim about a person, and a module asking "should this row show a flag" has
     * no need to substantiate it.
     *
     * @param  list<string>  $residentUuids
     * @return list<string> the subset under review
     */
    public function underDuplicateReview(array $residentUuids): array
    {
        $uuids = array_values(array_unique(array_filter($residentUuids)));

        if ($uuids === []) {
            return [];
        }

        $ids = Resident::query()->whereIn('uuid', $uuids)->pluck('id', 'uuid')->all();

        $pairs = ResidentDuplicatePair::query()
            ->where('decision', 'undecided')
            ->where(function ($query) use ($ids): void {
                $query->whereIn('lower_resident_id', array_values($ids))
                    ->orWhereIn('higher_resident_id', array_values($ids));
            })
            ->get(['lower_resident_id', 'higher_resident_id']);

        $flagged = [];

        foreach ($pairs as $pair) {
            $flagged[(int) $pair->lower_resident_id] = true;
            $flagged[(int) $pair->higher_resident_id] = true;
        }

        return array_values(array_keys(array_filter(
            $ids,
            static fn (int $id): bool => isset($flagged[$id]),
        )));
    }

    /**
     * The facts a programme's eligibility guidance is allowed to read (ADR 0018 §3).
     *
     * THIS MODULE DECIDES WHAT IT DISCLOSES. The alternative — letting ServiceCatalog or
     * Welfare reach into residents, households and sectors to assemble facts themselves —
     * would put the decision about what may be used for eligibility in three places, and the
     * one that drifts is always the one nobody reviewed.
     *
     * A plain keyed array rather than a typed object on purpose: the keys are ServiceCatalog's
     * `EligibilityFact` vocabulary, and importing that enum here would make ResidentProfile
     * depend on a module that does not depend on it — a downward reach the boundary map
     * forbids (§2).
     *
     * ABSENT FACTS ARE ABSENT, NOT ZERO. A resident with no recorded income yields no
     * `monthly-income` key, and the guidance engine reads that as `unknown` and sends the case
     * to a human. Substituting 0 would silently satisfy every income ceiling in the system.
     *
     * NOT INCLUDED, AND NOT AN OVERSIGHT: the vulnerability score. It is unapproved placeholder
     * weighting (gap G-20) and declares itself decision-support-only; exposing it here would be
     * the shortest path to it deciding who gets help.
     *
     * @return array<string, mixed>
     */
    public function eligibilityFactsFor(string $residentUuid): array
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->first();

        if ($resident === null) {
            return [];
        }

        $facts = [
            /*
             * THE BARANGAY'S CODE, NEVER ITS AUTO-INCREMENT KEY.
             *
             * This fact is compared against a value an administrator typed into a criterion, so
             * whatever it carries is what somebody has to author policy in. It used to carry
             * `(string) $resident->barangay_id` — the surrogate key — which made a criterion read
             * `barangay is 2`, and that is wrong three times over.
             *
             * It is unexplainable: {@see EligibilityFact} requires every fact be something a clerk
             * can point at and explain to the person in front of them, and nobody at the MSWDO
             * knows which barangay is 2.
             *
             * It is not stable across environments. Auto-increment keys are assigned by insertion
             * order, so the same criterion authored against staging targets a DIFFERENT BARANGAY in
             * production — silently, with no error anywhere, deciding who gets assistance. This
             * system also imports legacy records and merges duplicates, both of which reorder
             * insertions.
             *
             * And it is unpublishable: `GET /api/v1/barangays` deliberately publishes `uuid` and
             * `code` and refuses to publish the integer (L-15, and that controller's own docblock),
             * so no client could offer a picker for a value only the database knows.
             *
             * `code` rather than `uuid` because the explainability rule decides it: "brgy-san-juan"
             * is legible on the criterion, in the audit trail, and at a counter. It is unique, it
             * is what `POST me/kyc` already accepts, and it is what the public directory publishes.
             */
            'barangay' => $this->barangayCodeFor($resident->barangay_id),
            'verification-tier' => $resident->verification_tier->value,
        ];

        if ($resident->birth_date !== null) {
            $facts['age'] = $resident->birth_date->age;
        }

        if ($resident->monthly_income_centavos !== null) {
            $facts['monthly-income'] = (int) $resident->monthly_income_centavos;
        }

        $sectors = ResidentSector::query()
            ->where('resident_id', $resident->id)
            /*
             * Sensitive sectors are excluded from eligibility facts entirely.
             *
             * A criterion that read `vawc-survivor` would leak protection status to everyone
             * who can see a guidance result — the same disclosure ADR 0015 §4 keeps out of the
             * vulnerability score, arriving by a different route. Protection cases are served
             * by referral and field response, not by qualifying for a grant automatically.
             */
            ->whereNotIn('sector', ResidentSector::SENSITIVE)
            ->pluck('sector')
            ->map(static fn (mixed $sector): string => (string) $sector)
            ->values()
            ->all();

        if ($sectors !== []) {
            $facts['sector'] = $sectors;
        }

        $householdId = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->value('household_id');

        if ($householdId !== null) {
            $facts['household-size'] = HouseholdMembership::query()
                ->where('household_id', $householdId)
                ->whereNull('effective_to')
                ->count();
        }

        // Absent keys are dropped rather than sent as null: the guidance engine treats a
        // missing key and a null value identically, and dropping them keeps the payload honest
        // about what is actually known.
        return array_filter($facts, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The stable code for a barangay, for use as an eligibility fact.
     *
     * Reads the `barangays` table directly, as {@see BarangayDirectoryController} in this module
     * already does. That table is municipal reference data rather than another module's record,
     * and this module publishes the directory that serves it.
     *
     * AN UNRESOLVABLE BARANGAY YIELDS null, WHICH BECOMES AN ABSENT FACT AND THEREFORE `unknown`.
     * That is the correct failure and it is the one this file already applies to income: a
     * barangay row that has gone (a merge, a re-import) means nobody can currently say where the
     * applicant lives, not that they live nowhere. `unknown` sends the case to a human, where a
     * question about somebody's address belongs. Returning the integer as a fallback would put
     * the defect back on exactly the records least likely to be correct.
     */
    private function barangayCodeFor(mixed $barangayId): ?string
    {
        if ($barangayId === null) {
            return null;
        }

        $code = DB::table('barangays')->where('id', (int) $barangayId)->value('code');

        return $code === null ? null : (string) $code;
    }

    /**
     * Values for the fields a referral summary may release, as printable strings.
     *
     * SEPARATE FROM {@see eligibilityFactsFor()} AND DELIBERATELY SO. They answer different
     * questions and the difference is not cosmetic:
     *
     *  * eligibility facts feed an automated comparison, so sensitive sectors are excluded
     *    outright — a criterion reading `vawc-survivor` would leak protection status to everyone
     *    who can see a guidance result (ADR 0015 §4);
     *  * disclosure values are read by a **named human** who has stated a reason and holds
     *    `referral.disclose-protected`, and sector membership is frequently the entire point of
     *    the referral. Withholding it from a referral to the Women and Children Protection Desk
     *    would produce a sheet that cannot be acted on.
     *
     * The protection is therefore the permission and the recorded reason, not the absence of the
     * data. Nothing here is released without both.
     *
     * Keys match `Welfare\Domain\SharedField`. A key that is absent means the office does not
     * hold that fact, and the caller omits the line rather than printing it empty.
     *
     * @return array<string, string>
     */
    public function disclosureFactsFor(string $residentUuid): array
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->first();

        if ($resident === null) {
            return [];
        }

        $values = [
            'birth-date' => $resident->birth_date?->toDateString(),
            'address' => $resident->street_address,
            'contact-number' => $resident->mobile_number,
        ];

        if ($resident->monthly_income_centavos !== null) {
            $values['income'] = 'PHP '.number_format(((int) $resident->monthly_income_centavos) / 100, 2).' per month';
        }

        $sectors = ResidentSector::query()
            ->where('resident_id', $resident->id)
            ->pluck('sector')
            ->map(static fn (mixed $sector): string => (string) $sector)
            ->values()
            ->all();

        if ($sectors !== []) {
            $values['vulnerability-sectors'] = implode(', ', $sectors);
        }

        $householdId = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->value('household_id');

        if ($householdId !== null) {
            $size = HouseholdMembership::query()
                ->where('household_id', $householdId)
                ->whereNull('effective_to')
                ->count();

            // A count, not a roster. The receiving office needs to know the family's size to plan
            // for it; naming the other members would disclose people who are not being referred
            // and were never asked.
            $values['household-composition'] = $size.' '.($size === 1 ? 'member' : 'members');
        }

        return array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
