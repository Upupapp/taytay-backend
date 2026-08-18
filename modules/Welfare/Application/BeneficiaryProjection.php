<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ResidentProfile\Contracts\ResidentSummary;

/**
 * The beneficiary registry — a **projection**, never an entity (TAB 07).
 *
 * The command could not be more explicit: *"A projection, never an entity. The console's domain
 * has no [beneficiary id] and must not acquire one. Derive from live requests, released payouts
 * and standing enrolments; store no flag."*
 *
 * So there is no `beneficiaries` table, no `beneficiary_id`, and nothing in this file writes.
 * Everything below is computed at read time from records that already have owners.
 *
 * ── WHY A STORED STANDING WOULD BE WRONG, NOT JUST REDUNDANT ─────────────────────────
 *
 * A standing is a **statement about a person's relationship with this office**, and it changes
 * when their case changes — not when a job runs. Stored as a flag it would be wrong in the window
 * between the two, and the window is exactly when it matters: the morning after a payout, when
 * somebody checks whether a family has already been helped this quarter.
 *
 * The same argument the console records as `DL-71`, and the same one this backend already applies
 * to {@see AssistanceHistory}: *"a second copy of facts that already live on cases and enrolments
 * would drift from them the first time a case was corrected."*
 *
 * ── THE FOUR STANDINGS ARE NOT EXCLUSIVE ─────────────────────────────────────────────
 *
 * A person can hold all four at once, and most active beneficiaries do. They are answers to four
 * different questions:
 *
 *   * **constituent** — on the registry. Everybody here holds it.
 *   * **applicant** — has a request with the office that is not settled.
 *   * **beneficiary** — has been granted assistance at least once.
 *   * **enrollee** — on the list of a continuing programme.
 *
 * A single `status` column could not express that, which is the second reason there is no table.
 *
 * ── THE MODULE BOUNDARY, AND WHY IT LANDS HERE ───────────────────────────────────────
 *
 * This was first written in `ResidentProfile`, on the reasoning that the registry is the spine.
 * `ModuleBoundaryTest` rejected it, correctly: `Welfare` already depends on `ResidentProfile`, so
 * importing back the other way made the dependency graph **cyclic**.
 *
 * The inversion is also the better answer on Article 6's terms. A beneficiary standing is a
 * *welfare* fact about a person — it says what this office has done for them — and each fact has
 * exactly one owning module. So the standings live here, and the base set of people comes from
 * {@see ResidentDirectory::searchSummaries()} as published summaries rather than as a query
 * builder. Handing a builder across would let this module filter residents on income or sectors,
 * and the boundary would exist only in the documentation.
 */
final class BeneficiaryProjection
{
    public function __construct(
        private readonly AssistanceHistory $assistance,
        private readonly ResidentDirectory $residents,
    ) {}

    /**
     * A page of the registry, projected.
     *
     * @param  list<int>|null  $barangayIds  the caller's scope; null unrestricted, [] nothing
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(?string $term, ?array $barangayIds, int $page, int $perPage): array
    {
        $result = $this->residents->searchSummaries($term, $barangayIds, $page, $perPage);

        return ['rows' => $this->summarise($result['summaries']), 'total' => $result['total']];
    }

    /**
     * Project residents into beneficiary summaries.
     *
     * **Three calls for the whole page, never per row.** Each summary needs assistance facts and a
     * duplicate-review flag from elsewhere; asking per row would be 100 round trips for a page of
     * 100, which is the N+1 the command names as a first-busy-morning incident.
     *
     * @param  list<ResidentSummary>  $residents
     * @return list<array<string, mixed>>
     */
    public function summarise(array $residents): array
    {
        $uuids = array_map(static fn (ResidentSummary $r): string => $r->id, $residents);

        $facts = $this->assistance->factsFor($uuids);
        $underReview = array_flip($this->residents->underDuplicateReview($uuids));

        return array_map(
            fn (ResidentSummary $resident): array => $this->summary($resident, $facts, $underReview),
            $residents,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<string, int>  $underReview
     * @return array<string, mixed>
     */
    public function summary(ResidentSummary $resident, array $facts, array $underReview): array
    {
        $fact = $facts[$resident->id] ?? [];

        return [
            // Keyed on the resident. There is no beneficiary identifier and there must not be —
            // a second identifier for one person is how two records of them come to exist.
            'resident_id' => $resident->id,
            'barangay_id' => $resident->barangayId,
            'standings' => $this->standingsFor($fact),
            'current_program_codes' => array_values(array_unique($fact['current_program_codes'] ?? [])),
            'open_request_count' => $fact['open_request_count'] ?? 0,
            'assistance_event_count' => $fact['granted_count'] ?? 0,
            'last_assistance_at' => $this->asIso($fact['last_assistance_at'] ?? null),
            'total_released_centavos' => $fact['total_released_centavos'] ?? 0,
            /*
             * Counted, never valued. Nobody at the MSWDO priced that sack of rice, so an in-kind
             * release carries no amount and is reported as a count beside the money rather than
             * summed into it as zero — a zero reads as "given, worth nothing".
             */
            'in_kind_release_count' => $fact['in_kind_release_count'] ?? 0,
            'has_open_duplicate_review' => isset($underReview[$resident->id]),
        ];
    }

    /**
     * The four standings, derived. Not exclusive — most active beneficiaries hold several.
     *
     * @param  array<string, mixed>  $fact
     * @return list<string>
     */
    private function standingsFor(array $fact): array
    {
        // Everybody on the registry holds this one. It is what the list is a list of.
        $standings = ['constituent'];

        if (($fact['open_request_count'] ?? 0) > 0) {
            $standings[] = 'applicant';
        }

        if (($fact['granted_count'] ?? 0) > 0) {
            $standings[] = 'beneficiary';
        }

        if (($fact['current_program_codes'] ?? []) !== []) {
            $standings[] = 'enrollee';
        }

        return $standings;
    }

    private function asIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(\DateTimeInterface::ATOM)
            : (string) $value;
    }
}
