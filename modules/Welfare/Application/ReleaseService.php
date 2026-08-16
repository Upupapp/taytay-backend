<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ServiceCatalog\Application\ProgramCatalog;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\ReleaseStatus;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use Modules\Welfare\Infrastructure\Eloquent\ReleaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Handing approved assistance over, safely (ADR 0023).
 *
 * THREE CONTROLS, AND THEY GUARD DIFFERENT FAILURES:
 *
 *  1. **Segregation of duties** — the person who approved the case may not release its money.
 *     Guards deliberate misuse.
 *  2. **A row lock and a status re-check inside the transaction** — two staff at two tables at
 *     the same distribution cannot both mark one release handed over. Guards a race.
 *  3. **An idempotency key at the controller** — a retry over a weak connection replays the first
 *     answer instead of recording a second release. Guards an accident.
 *
 * None substitutes for another. The lock does not stop a retry (each request is a separate
 * transaction and each would find `ready` if the first had rolled back); the key does not stop a
 * race (two different clients hold two different keys); and neither stops one person doing both
 * halves of a payment on purpose.
 *
 * THIS IS NOT A LEDGER. No journal entry, no posting, no reconciliation. The question here is
 * whether a family received what was approved for them.
 */
final class ReleaseService
{
    public function __construct(
        private readonly ResidentDirectory $residents,
        private readonly ProgramCatalog $programs,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Prepares a release against an approved case.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function prepare(WelfareCase $case, array $attributes, ActorContext $actor): Release
    {
        /*
         * ONLY AN APPROVED CASE PRODUCES A RELEASE.
         *
         * The acceptance criterion — a released record traces to an approved case, programme and
         * beneficiary — is held here, at the only place a release can be created. Allowing one
         * against a case still under assessment would let money be scheduled before anybody
         * decided it should be.
         */
        if (! in_array($case->status, [CaseStatus::Approved, CaseStatus::Scheduled], true)) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only an approved case can have assistance released against it.',
            );
        }

        $kind = (string) $attributes['kind'];
        $this->assertAmountMatchesKind($kind, $attributes);

        return DB::transaction(function () use ($case, $attributes, $actor, $kind): Release {
            /*
             * The sequence is read inside a lock on the case.
             *
             * Two clerks preparing an instalment at the same moment would otherwise both read
             * sequence 2 and both write it, and the unique key would reject the second — turning
             * a routine collision into an error somebody has to retry.
             */
            WelfareCase::query()->lockForUpdate()->find($case->id);

            $sequence = (int) Release::query()->where('welfare_case_id', $case->id)->max('sequence') + 1;

            $program = isset($attributes['program_id'])
                ? $this->programs->summaryFor((string) $attributes['program_id'])
                : null;

            /** @var Release $release */
            $release = Release::query()->create([
                'welfare_case_id' => $case->id,
                'resident_id' => $case->resident_id,
                'program_id' => $program?->id,
                'program_code' => $program?->code,
                /*
                 * Snapshotted, not read through. The record must say who authorised THIS payment;
                 * a later reassignment on the case must not rewrite it, and it is what
                 * segregation of duties is checked against.
                 */
                'approved_by' => $this->approverOf($case),
                'approval_reference' => $attributes['approval_reference'] ?? (string) $case->case_number,
                'sequence' => $sequence,
                'kind' => $kind,
                'amount_centavos' => $kind === 'cash' ? (int) $attributes['amount_centavos'] : null,
                'currency' => 'PHP',
                'in_kind_description' => $kind === 'in-kind' ? (string) $attributes['in_kind_description'] : null,
                'release_mode' => (string) $attributes['release_mode'],
                // A label for grouping a report. Never a chart-of-accounts reference.
                'funding_source' => $attributes['funding_source'] ?? null,
                'scheduled_for' => $attributes['scheduled_for'] ?? null,
                'release_location' => $attributes['release_location'] ?? null,
                'status' => ReleaseStatus::Ready,
                'created_by' => $actor->subjectId,
            ]);

            $this->recordTransition($release, null, ReleaseStatus::Ready, null, $actor);

            $this->audit->record(
                $actor->subjectId,
                'release.prepared',
                'Release prepared for '.$case->case_number,
                (string) $case->uuid,
            );

            return $release;
        });
    }

    /**
     * Confirms that assistance was handed over.
     *
     * THE ONE OPERATION THAT MOVES MONEY, and the one this whole class exists to make safe.
     *
     * @param  array<string, mixed>  $acknowledgement
     */
    public function confirmRelease(Release $release, array $acknowledgement, ActorContext $actor): Release
    {
        return DB::transaction(function () use ($release, $acknowledgement, $actor): Release {
            /** @var Release $release */
            $release = Release::query()->lockForUpdate()->findOrFail($release->id);

            /*
             * Re-checked INSIDE the lock, not before it.
             *
             * Two staff at two tables at the same distribution: both load the record showing
             * `ready`, both click. Without the lock and this re-read, both writes succeed and the
             * family is recorded as having received twice — which is exactly the state a payout
             * audit is looking for.
             */
            if ($release->status !== ReleaseStatus::Ready) {
                throw InvalidStateTransitionException::between(
                    $release->status->value,
                    ReleaseStatus::Released->value,
                );
            }

            $this->assertSegregationOfDuties($release, $actor);

            $release->forceFill([
                'status' => ReleaseStatus::Released,
                'released_by' => $actor->subjectId,
                'released_at' => now(),
                /*
                 * WHO ACTUALLY TOOK IT, which is frequently not the beneficiary: an elderly
                 * person sends a daughter, a bedridden patient sends a neighbour. Recording only
                 * "released" loses the one fact a dispute turns on.
                 */
                'acknowledged_by_name' => $acknowledgement['acknowledged_by_name'] ?? null,
                'acknowledged_relationship' => $acknowledgement['acknowledged_relationship'] ?? null,
                // The METHOD only. No signature image, no thumbprint — the mark stays on the
                // paper manifest, because a biometric held for this purpose is one held for no
                // reason (RA 10173, Article 5.2).
                'acknowledgement_method' => $acknowledgement['acknowledgement_method'] ?? null,
                'acknowledged_at' => isset($acknowledgement['acknowledgement_method']) ? now() : null,
            ])->save();

            $this->recordTransition($release, ReleaseStatus::Ready, ReleaseStatus::Released, null, $actor);

            $this->audit->record(
                $actor->subjectId,
                'release.released',
                'Assistance released ('.$release->reference_number.')',
                $this->caseUuid($release),
            );

            return $release->refresh();
        });
    }

    /**
     * Any other movement: completed, failed, deferred, cancelled.
     */
    public function transition(
        Release $release,
        ReleaseStatus $status,
        ?string $reason,
        ActorContext $actor,
    ): Release {
        return DB::transaction(function () use ($release, $status, $reason, $actor): Release {
            /** @var Release $release */
            $release = Release::query()->lockForUpdate()->findOrFail($release->id);

            if ($status === ReleaseStatus::Released) {
                // Releasing has its own method: it carries the acknowledgement, the segregation
                // check and the idempotency contract.
                throw new ApiException(ErrorCode::BadRequest, 'Use the release confirmation endpoint.');
            }

            if (! $release->status->canMoveTo($status)) {
                throw InvalidStateTransitionException::between($release->status->value, $status->value);
            }

            // Every outcome that is not the happy path must say why. A failed release with no
            // reason is indistinguishable from one nobody attempted.
            if ($status->requiresReason() && trim((string) $reason) === '') {
                throw new ApiException(ErrorCode::ValidationFailed, 'Record why.');
            }

            $from = $release->status;

            $release->forceFill([
                'status' => $status,
                'outcome_reason' => $reason ?? $release->outcome_reason,
            ])->save();

            $this->recordTransition($release, $from, $status, $reason, $actor);

            $this->audit->record(
                $actor->subjectId,
                'release.'.$status->value,
                'Release '.$status->value.' ('.$release->reference_number.')',
                $this->caseUuid($release),
            );

            return $release->refresh();
        });
    }

    /**
     * @return Builder<Release>
     */
    public function query(): Builder
    {
        return Release::query();
    }

    /**
     * The manifest for a distribution run.
     *
     * Ordered by beneficiary name is deliberately NOT done here — the manifest is ordered by
     * reference so two copies printed an hour apart match line for line, which is what makes a
     * paper manifest checkable against a screen at a table with a queue in front of it.
     *
     * @return Builder<Release>
     */
    public function manifestQuery(string $batchUuid): Builder
    {
        return Release::query()
            ->whereIn('release_batch_id', function ($query) use ($batchUuid): void {
                $query->select('id')->from('release_batches')->where('uuid', $batchUuid);
            })
            ->orderBy('reference_number');
    }

    /**
     * What one beneficiary has actually received. Feeds TAB 14's assistance history.
     *
     * @return Builder<Release>
     */
    public function forResident(string $residentUuid): Builder
    {
        return Release::query()
            ->where('resident_id', $residentUuid)
            ->orderByDesc('created_at');
    }

    /**
     * The approver may not be the releaser.
     *
     * SEGREGATION OF DUTIES, ENFORCED ON THE PERSON AND NOT ONLY ON THE PERMISSION. Two roles is
     * the design; one person holding both is the failure, and it happens the moment somebody is
     * granted a second role to cover a colleague's leave.
     *
     * Checked against the snapshot taken when the release was prepared, so a later change to the
     * case cannot retroactively make a past release compliant or non-compliant.
     */
    private function assertSegregationOfDuties(Release $release, ActorContext $actor): void
    {
        if ($release->approved_by === null || $actor->subjectId === null) {
            return;
        }

        if ((string) $release->approved_by === (string) $actor->subjectId) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'The person who approved this assistance cannot also release it.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertAmountMatchesKind(string $kind, array $attributes): void
    {
        if ($kind === 'cash') {
            $amount = $attributes['amount_centavos'] ?? null;

            if (! is_int($amount) && ! ctype_digit((string) $amount)) {
                throw new ApiException(ErrorCode::ValidationFailed, 'A cash release needs an amount in centavos.');
            }

            if ((int) $amount <= 0) {
                throw new ApiException(ErrorCode::ValidationFailed, 'An amount must be greater than zero.');
            }

            return;
        }

        if (trim((string) ($attributes['in_kind_description'] ?? '')) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'An in-kind release needs a description.');
        }

        /*
         * An in-kind release carries no amount, deliberately.
         *
         * A relief pack has a notional value, and recording it here would put a peso figure
         * against a family that received rice — which then appears in every total as though cash
         * had been handed over.
         */
        if (($attributes['amount_centavos'] ?? null) !== null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'An in-kind release records what was given, not an amount.',
            );
        }
    }

    private function approverOf(WelfareCase $case): ?string
    {
        // The most recent approval transition on the case. Read once, at preparation, and stored.
        return DB::table('welfare_case_transitions')
            ->where('welfare_case_id', $case->id)
            ->where('to_status', CaseStatus::Approved->value)
            ->orderByDesc('id')
            ->value('actor_subject_id');
    }

    private function caseUuid(Release $release): ?string
    {
        $uuid = WelfareCase::query()->whereKey($release->welfare_case_id)->value('uuid');

        return $uuid === null ? null : (string) $uuid;
    }

    private function recordTransition(
        Release $release,
        ?ReleaseStatus $from,
        ReleaseStatus $to,
        ?string $reason,
        ActorContext $actor,
    ): void {
        ReleaseTransition::query()->create([
            'release_id' => $release->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor_subject_id' => $actor->subjectId,
            'occurred_at' => now(),
        ]);
    }
}
