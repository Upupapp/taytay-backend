<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
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
            'barangay' => $resident->barangay_id === null ? null : (string) $resident->barangay_id,
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
