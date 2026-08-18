<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Carbon\CarbonImmutable;
use Modules\Welfare\Domain\ReleaseStatus;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The intake advisory — evidence for an encoder, computed here rather than in a browser.
 *
 * ── WHY IT MOVED ─────────────────────────────────────────────────────────────────────
 *
 * TAB 07's triage: the console computed this client-side, which is the same family of defect as
 * G-16 and the console's own `DL-42`. A rule that shapes how an applicant is treated, living where
 * the client can see it, change it, or simply fail to run it, is not a rule the office can say it
 * applied. Moving it here does not weaken what it is — it makes it enforceable, because the
 * console can no longer be the only thing that knows the rule.
 *
 * ── IT ADVISES AND NEVER DECIDES ─────────────────────────────────────────────────────
 *
 * There is **no score, no total, no `eligible`, and no recommendation** anywhere in this file, and
 * the response shape has nowhere to put one. The console's `DL-60` says the same, and the reason
 * is that a duplicate check with a verdict attached stops being evidence and becomes an
 * eligibility engine — one that nobody voted for and that an encoder cannot argue with.
 *
 * Each signal states **the rule it applied**, **what it found** as counts and dates, and **the
 * records it read**. All three are rendered, because a finding an encoder cannot check is one they
 * learn to click past.
 *
 * Two tones, `note` and `caution`, and **neither blocks**. A caution asks for a sentence before
 * filing and the sentence is kept; it does not refuse anybody. A family in crisis who has been
 * helped before is the ordinary case for crisis assistance, not a fraud signal.
 *
 * ── THE WINDOWS ARE THE CONSOLE'S, AND THEY ARE NOT LAW ──────────────────────────────
 *
 * 90 days for the same programme, 12 months for any assistance. They match the console's constants
 * exactly, which is the point of moving the computation rather than reinventing it — but neither
 * came from a DSWD issuance or an MSWDO policy. They are conventions, they are named here as
 * constants so a reviewer can find them, and they are recorded as G-29 for the office to confirm.
 */
final class IntakeAdvisory
{
    /**
     * A second grant under the same programme inside this window is worth a sentence.
     *
     * Convention, not law — see the class docblock and G-29.
     */
    public const SAME_PROGRAMME_WINDOW_DAYS = 90;

    /** Any assistance inside this window is worth showing. Also convention. */
    public const ASSISTANCE_LOOKBACK_MONTHS = 12;

    /**
     * @return array<string, mixed>
     */
    public function forResident(string $residentUuid, ?string $programUuid = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $cases = WelfareCase::query()->where('resident_id', $residentUuid)->get();
        $releases = Release::query()
            ->where('resident_id', $residentUuid)
            ->whereIn('status', [ReleaseStatus::Released->value, ReleaseStatus::Completed->value])
            ->get();

        $signals = [];

        /*
         * `status` is cast to the CaseStatus enum on the model, so "is this case still open" is
         * asked of the domain rather than by matching strings. An earlier draft compared the enum
         * against CaseStatus::openValues() with a strict in_array and silently matched nothing —
         * an advisory that finds no signals looks exactly like a clean record, which is the worst
         * possible failure mode for this endpoint.
         */
        $isOpen = static fn ($case): bool => $case->status->isOpen();

        $openSame = $cases->filter(fn ($case): bool => $isOpen($case)
            && $programUuid !== null && $case->program_id === $programUuid);

        if ($openSame->isNotEmpty()) {
            $signals[] = [
                'code' => 'open-request-same-programme',
                'tone' => 'caution',
                'rule' => 'An unfinished request already exists for this person under this programme.',
                'finding' => $this->countPhrase($openSame->count(), 'open request').' under the same programme.',
                'references' => $openSame->pluck('case_number')->all(),
            ];
        }

        $openOther = $cases->filter(fn ($case): bool => $isOpen($case)
            && ($programUuid === null || $case->program_id !== $programUuid));

        if ($openOther->isNotEmpty()) {
            $signals[] = [
                'code' => 'open-request-other-programme',
                'tone' => 'note',
                'rule' => 'Unfinished requests exist for this person under other programmes.',
                'finding' => $this->countPhrase($openOther->count(), 'open request').' elsewhere in the office.',
                'references' => $openOther->pluck('case_number')->all(),
            ];
        }

        $window = $now->subDays(self::SAME_PROGRAMME_WINDOW_DAYS);

        $grantedSame = $releases->filter(fn ($release): bool => $programUuid !== null
            && $release->program_id === $programUuid
            && $release->released_at !== null
            && $release->released_at->greaterThanOrEqualTo($window));

        if ($grantedSame->isNotEmpty()) {
            $signals[] = [
                'code' => 'granted-same-programme-recently',
                'tone' => 'caution',
                'rule' => 'Assistance under this programme was already granted within '
                    .self::SAME_PROGRAMME_WINDOW_DAYS.' days.',
                'finding' => $this->countPhrase($grantedSame->count(), 'earlier grant').' inside the review window.',
                'references' => $grantedSame->pluck('reference_number')->all(),
            ];
        }

        $lookback = $now->subMonths(self::ASSISTANCE_LOOKBACK_MONTHS);

        $recent = $releases->filter(fn ($release): bool => $release->released_at !== null
            && $release->released_at->greaterThanOrEqualTo($lookback));

        if ($recent->isNotEmpty()) {
            /*
             * Cash only in the total. An in-kind release has no amount — nobody priced that sack
             * of rice — so it is counted separately rather than added in as zero, which would put
             * "received, worth nothing" in front of the encoder.
             */
            $centavos = $recent->sum(fn ($release): int => $release->kind === 'cash'
                ? (int) ($release->amount_centavos ?? 0)
                : 0);

            $inKind = $recent->filter(fn ($release): bool => $release->kind === 'in-kind')->count();

            $signals[] = [
                'code' => 'assistance-within-lookback',
                'tone' => 'note',
                'rule' => 'Assistance handed over to this person in the last '
                    .self::ASSISTANCE_LOOKBACK_MONTHS.' months.',
                'finding' => $this->countPhrase($recent->count(), 'payout')
                    .' totalling '.$this->pesos($centavos)
                    .($inKind > 0 ? ', plus '.$this->countPhrase($inKind, 'in-kind release').' with no recorded value' : '')
                    .'.',
                'references' => $recent->pluck('reference_number')->all(),
            ];
        }

        /*
         * Somebody else at the same address, inside the lookback.
         *
         * Resolved through the **case's** household rather than by asking the resident registry
         * who lives there: a case records the household it was opened against, so this stays
         * inside the module that owns it and needs no cross-module call. It is also the more
         * honest question — "did this address receive assistance" is answered by what the office
         * actually did, not by who happens to be registered there today.
         *
         * A note, never a caution. Several families share one address in Taytay routinely
         * (`DL-47`), and treating a neighbour's payout as a mark against this applicant is how an
         * advisory becomes an accusation.
         */
        $householdIds = $cases->pluck('household_id')->filter()->unique();

        if ($householdIds->isNotEmpty()) {
            $householdCaseIds = WelfareCase::query()
                ->whereIn('household_id', $householdIds->all())
                ->where('resident_id', '!=', $residentUuid)
                ->pluck('id');

            $householdReleases = $householdCaseIds->isEmpty() ? collect() : Release::query()
                ->whereIn('welfare_case_id', $householdCaseIds->all())
                ->whereIn('status', [ReleaseStatus::Released->value, ReleaseStatus::Completed->value])
                ->where('released_at', '>=', $lookback)
                ->get();

            if ($householdReleases->isNotEmpty()) {
                $signals[] = [
                    'code' => 'household-assisted-recently',
                    'tone' => 'note',
                    'rule' => 'Someone else at this address received assistance in the last '
                        .self::ASSISTANCE_LOOKBACK_MONTHS.' months.',
                    'finding' => $this->countPhrase($householdReleases->count(), 'payout')
                        .' to '.$this->countPhrase($householdReleases->pluck('resident_id')->unique()->count(), 'other person')
                        .' at the same address.',
                    'references' => $householdReleases->pluck('reference_number')->all(),
                ];
            }
        }

        $openCases = $cases->filter($isOpen);

        if ($openCases->isNotEmpty()) {
            $signals[] = [
                'code' => 'open-case',
                'tone' => 'note',
                'rule' => 'The office already has an open case about this person.',
                'finding' => $this->countPhrase($openCases->count(), 'open case').'. This request may belong inside it.',
                'references' => $openCases->pluck('case_number')->all(),
            ];
        }

        return [
            'signals' => $signals,
            'computed_at' => $now->toIso8601ZuluString(),
            /*
             * How much was read to produce this. Published so an encoder can tell "nothing found"
             * from "nothing looked at" — an advisory computed over an empty registry is not
             * reassurance, and the difference is invisible without this number.
             */
            'records_read' => $cases->count() + $releases->count(),
            'windows' => [
                'same_programme_days' => self::SAME_PROGRAMME_WINDOW_DAYS,
                'assistance_lookback_months' => self::ASSISTANCE_LOOKBACK_MONTHS,
                // Named in the payload, so a screen can say so rather than presenting a convention
                // as policy the office adopted.
                'basis' => 'convention-pending-confirmation',
            ],
        ];
    }

    private function countPhrase(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }

    private function pesos(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }
}
