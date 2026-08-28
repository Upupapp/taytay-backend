<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Modules\Shared\Application\BarangayCodes;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\ReleaseStatus;
use Modules\Welfare\Infrastructure\Eloquent\ProgramEnrollment;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * What a person has actually received, assembled at read time (ADR 0019 §3).
 *
 * A PROJECTION, NOT A STORE. There is no `assistance_events` table, and adding one would be the
 * mistake this class exists to avoid: it would be a second copy of facts that already live on
 * cases and enrolments, it would drift from them the first time a case was corrected, and
 * reconciling the two would become somebody's permanent job. The acceptance criterion says the
 * history must combine assistance events "without duplicating person rows" — the same argument
 * applies one level up, to the events themselves.
 *
 * WHAT COUNTS AS RECEIVED, TODAY. A case that reached `approved`, `scheduled`, `released` or
 * `completed`. Amounts and instrument references belong to the release ledger in TAB 18; this
 * projection is deliberately shaped so that TAB 18 contributes the money detail without this
 * class changing — {@see historyFor()} returns rows keyed by case, and the ledger joins onto
 * them rather than replacing them.
 *
 * That is also why "released" is derived from the case lifecycle rather than from a flag: the
 * lifecycle is the one place a release is recorded, and reading it means this projection cannot
 * disagree with the case file about whether somebody was paid.
 */
final class AssistanceHistory
{
    /**
     * Case statuses that mean a person has been granted something.
     *
     * `approved` is included on purpose: from the applicant's side, an approved case is a
     * commitment the office has made, and omitting it until release would show somebody a blank
     * history in the window where they are waiting for a payout they have been promised.
     */
    private const GRANTED = [
        CaseStatus::Approved,
        CaseStatus::Scheduled,
        CaseStatus::Released,
        CaseStatus::Completed,
    ];

    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly BarangayCodes $barangayCodes,
    ) {}

    /**
     * A resident's assistance history: what was granted, and which rolls they are on.
     *
     * @return array{granted: list<array<string, mixed>>, enrollments: list<array<string, mixed>>}
     */
    public function historyFor(string $residentUuid): array
    {
        return [
            'granted' => $this->grantedCases($residentUuid)
                ->map(fn (WelfareCase $case): array => $this->grantProjection($case))
                ->all(),
            'enrollments' => $this->enrollments->forResident($residentUuid)
                ->map(fn (ProgramEnrollment $enrollment): array => $this->enrollmentProjection($enrollment))
                ->all(),
        ];
    }

    /**
     * The citizen's own view of what they have received.
     *
     * Narrower than the staff view and additively built, like every other citizen projection
     * here (ADR 0016 §5): programme, date and outcome. No case worker, no internal reason, no
     * assessment, no barangay, no priority.
     *
     * Cases still open are absent entirely. A citizen tracks those through `me/cases`, where the
     * status vocabulary is designed for it; listing an in-flight case under "assistance
     * received" would tell somebody they have been given something they have not.
     *
     * @return list<array<string, mixed>>
     */
    public function citizenHistoryFor(string $residentUuid): array
    {
        return $this->grantedCases($residentUuid)
            ->map(fn (WelfareCase $case): array => [
                'reference' => $case->case_number,
                'type' => $case->type->value,
                'status' => $case->status->citizenStatus(),
                'status_message' => $case->status->citizenMessage(),
                'granted_at' => $case->last_activity_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Enrolments in force on a given date.
     *
     * The question a release audit asks — "who was on this roll when the October tranche went
     * out" — answered from the effective dates rather than from today's roll, which is the whole
     * reason enrolment is effective-dated (ADR 0019 §2).
     *
     * @return Collection<int, ProgramEnrollment>
     */
    public function rollFor(string $programUuid, \DateTimeInterface $asOf): Collection
    {
        return ProgramEnrollment::query()
            ->where('program_id', $programUuid)
            ->whereDate('effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf);
            })
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @return Collection<int, WelfareCase>
     */
    /**
     * The assistance facts for a **page** of residents, in a fixed number of queries.
     *
     * Published for TAB 07's beneficiary projection, which lives in `ResidentProfile` because it
     * is a projection over the resident registry — the registry is the spine, and this module owns
     * only what the office has done for those people. Article 2.1 forbids that module reading
     * `welfare_cases`, `releases` or `program_enrollments` directly, and it should: the question
     * *"has this person received assistance"* has one owner, and that owner is here.
     *
     * ── WHY BATCHED ──────────────────────────────────────────────────────────────────
     *
     * {@see historyFor()} answers one resident. A registry page of 100 asking it 100 times is the
     * N+1 the command singles out as *"a production incident on the first busy morning"*. This
     * takes the whole page in three grouped queries and returns a map, so the caller loops over
     * memory rather than over the database.
     *
     * ── WHAT EACH FIGURE MEANS, PRECISELY ────────────────────────────────────────────
     *
     * `open_request_count` counts cases **not** yet granted or closed — somebody waiting on us.
     * `granted_count` counts cases that reached approved or beyond, which is this module's
     * existing definition of received ({@see GRANTED}) and deliberately includes `approved`: a
     * commitment made is a commitment, and hiding it until payout shows a blank history to
     * somebody who has been promised money.
     *
     * `total_released_centavos` sums **cash releases that actually happened**, and nothing else.
     * In-kind releases carry no amount by design — nobody priced that sack of rice — so they are
     * counted separately rather than coerced to zero. A zero would read as "given, worth nothing".
     *
     * @param  list<string>  $residentUuids
     * @return array<string, array<string, mixed>> keyed by resident uuid
     */
    public function factsFor(array $residentUuids): array
    {
        if ($residentUuids === []) {
            return [];
        }

        $granted = array_map(static fn (CaseStatus $s): string => $s->value, self::GRANTED);

        /*
         * "Still waiting on us" = open, and not already granted. `openValues()` is the domain's own
         * answer to which states are non-terminal, so this cannot drift from the lifecycle the way
         * a hand-listed set of terminal statuses would the next time one is added.
         */
        $waiting = array_values(array_diff(CaseStatus::openValues(), $granted));

        $facts = [];

        foreach ($residentUuids as $uuid) {
            $facts[$uuid] = [
                'open_request_count' => 0,
                'granted_count' => 0,
                'last_assistance_at' => null,
                'total_released_centavos' => 0,
                'in_kind_release_count' => 0,
                'current_program_codes' => [],
            ];
        }

        $grantedPlaceholders = implode(',', array_fill(0, count($granted), '?'));
        $waitingPlaceholders = $waiting === [] ? null : implode(',', array_fill(0, count($waiting), '?'));

        $cases = WelfareCase::query()
            ->whereIn('resident_id', $residentUuids)
            ->selectRaw('resident_id')
            ->selectRaw("sum(case when status in ({$grantedPlaceholders}) then 1 else 0 end) as granted_count", $granted)
            ->selectRaw(
                $waitingPlaceholders === null
                    ? '0 as open_count'
                    : "sum(case when status in ({$waitingPlaceholders}) then 1 else 0 end) as open_count",
                $waiting,
            )
            ->selectRaw('max(last_activity_at) as last_activity_at')
            ->groupBy('resident_id')
            ->get();

        foreach ($cases as $row) {
            $facts[$row->resident_id]['granted_count'] = (int) $row->granted_count;
            $facts[$row->resident_id]['open_request_count'] = (int) $row->open_count;
            $facts[$row->resident_id]['last_assistance_at'] = $row->last_activity_at;
        }

        $releases = Release::query()
            ->whereIn('resident_id', $residentUuids)
            // Money that actually moved. A scheduled release is a plan, and counting it as
            // received would tell a screen somebody was paid on the day it was written down.
            ->whereIn('status', [ReleaseStatus::Released->value, ReleaseStatus::Completed->value])
            ->selectRaw('resident_id')
            ->selectRaw('sum(case when kind = ? then coalesce(amount_centavos, 0) else 0 end) as cash_total', ['cash'])
            ->selectRaw('sum(case when kind = ? then 1 else 0 end) as in_kind_count', ['in-kind'])
            ->groupBy('resident_id')
            ->get();

        foreach ($releases as $row) {
            $facts[$row->resident_id]['total_released_centavos'] = (int) $row->cash_total;
            $facts[$row->resident_id]['in_kind_release_count'] = (int) $row->in_kind_count;
        }

        $enrollments = ProgramEnrollment::query()
            ->whereIn('resident_id', $residentUuids)
            ->where('status', 'active')
            ->get(['resident_id', 'program_code']);

        foreach ($enrollments as $enrollment) {
            $facts[$enrollment->resident_id]['current_program_codes'][] = $enrollment->program_code;
        }

        return $facts;
    }

    private function grantedCases(string $residentUuid): Collection
    {
        return WelfareCase::query()
            ->where('resident_id', $residentUuid)
            ->whereIn('status', array_map(static fn (CaseStatus $s): string => $s->value, self::GRANTED))
            ->orderByDesc('last_activity_at')
            ->limit(200)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function grantProjection(WelfareCase $case): array
    {
        return [
            'case_id' => $case->uuid,
            'case_number' => $case->case_number,
            'type' => $case->type->value,
            'status' => $case->status->value,
            'program_id' => $case->program_id,
            'barangay_id' => $case->barangay_id,
            'barangay_code' => $this->barangayCodes->codeFor($case->barangay_id),
            'opened_at' => $case->opened_at?->toIso8601ZuluString(),
            'closed_at' => $case->closed_at?->toIso8601ZuluString(),
            /*
             * TAB 18 filled this in, and the shape TAB 14 published did not have to change —
             * which was the point of leaving it present and null.
             *
             * SUMMED FROM RELEASES THAT ACTUALLY HAPPENED, not from what was approved. A case
             * approved for ₱5,000 whose payout failed has received nothing, and reporting the
             * approved figure here would tell a family they were given money they never saw.
             *
             * Integer centavos, summed as integers. In-kind releases contribute nothing: a relief
             * pack has a notional value, and adding it would produce a peso total claiming cash
             * was handed over when rice was.
             */
            'released_amount_centavos' => (int) Release::query()
                ->where('welfare_case_id', $case->id)
                ->whereIn('status', [ReleaseStatus::Released->value, ReleaseStatus::Completed->value])
                ->where('kind', 'cash')
                ->sum('amount_centavos'),
            'currency' => 'PHP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentProjection(ProgramEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->uuid,
            'program_id' => $enrollment->program_id,
            'program_code' => $enrollment->program_code,
            'status' => $enrollment->status->value,
            'effective_from' => $enrollment->effective_from?->toDateString(),
            'effective_to' => $enrollment->effective_to?->toDateString(),
            'entry_reason' => $enrollment->entry_reason,
            'exit_reason' => $enrollment->exit_reason,
            'source_case_id' => $enrollment->source_case_id,
        ];
    }
}
