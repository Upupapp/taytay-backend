<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ServiceCatalog\Application\ProviderDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Domain\ReferralStatus;
use Modules\Welfare\Domain\ReferralUrgency;
use Modules\Welfare\Infrastructure\Eloquent\Referral;
use Modules\Welfare\Infrastructure\Eloquent\ReferralNote;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Routing a person to another organisation, and chasing it (ADR 0021 §2, §4).
 *
 * THE SINGLE IRREVERSIBLE STEP IS `send()`. Everything before it is a draft this office can
 * revise; everything after it is a record of what another office reported. That line is why
 * sending carries its own permission, why the disclosure freezes at that moment, and why the
 * destination is snapshotted rather than read through.
 *
 * NOTHING PAST `Sent` IS INFERRED FROM ELAPSED TIME. The MSWDO does not know that a hospital has
 * started work; it knows that somebody there said so. A status that advanced on its own would be
 * this system inventing facts about another agency.
 */
final class ReferralService
{
    public function __construct(
        private readonly ResidentDirectory $residents,
        private readonly ProviderDirectory $providers,
        private readonly ReferralDisclosure $disclosure,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Drafts a referral. Nothing leaves the building here.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function draft(array $attributes, ActorContext $actor): Referral
    {
        $resident = $this->residents->summaryFor((string) $attributes['resident_id']);

        /*
         * A REFERRAL ALWAYS LINKS TO A CLIENT — the acceptance criterion, held at the only place
         * a referral can be created.
         *
         * A referral with no resident is a disclosure about nobody in particular: it cannot be
         * audited, cannot be answered to a subject-access request, and cannot be repointed when
         * two records turn out to be one person.
         */
        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $case = $this->resolveCase($attributes['case_id'] ?? null, (string) $resident->id);

        return DB::transaction(function () use ($attributes, $actor, $resident, $case): Referral {
            $urgency = ReferralUrgency::from((string) ($attributes['urgency'] ?? 'routine'));
            $destination = $this->resolveDestination($attributes);

            $referral = Referral::query()->create([
                'resident_id' => $resident->id,
                'welfare_case_id' => $case?->id,
                'provider_id' => $destination['provider_id'],
                'destination_type' => $destination['destination_type'],
                'destination_name' => $destination['destination_name'],
                'destination_contact' => $destination['destination_contact'],
                'status' => ReferralStatus::Draft,
                'urgency' => $urgency,
                'service_requested' => (string) $attributes['service_requested'],
                'reason' => (string) $attributes['reason'],
                'referred_by' => $actor->subjectId,
                'referred_at' => now(),
                // Left null until the referral is sent: a follow-up date on something that has
                // not gone anywhere would put a draft into the overdue queue.
                'follow_up_on' => null,
            ]);

            $this->audit->record(
                $actor->subjectId,
                'referral.drafted',
                'Referral drafted',
                (string) $referral->uuid,
            );

            return $referral;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Referral $referral, array $changes, ActorContext $actor): Referral
    {
        /*
         * A sent referral is not editable.
         *
         * The other office already has what it has. Editing the record afterwards would make it
         * describe a referral that was never sent, and the one that was would be unreconstructable.
         * Corrections after sending are notes, which is what notes are for.
         */
        if ($referral->status->hasLeftTheOffice()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That referral has already been sent. Add a note instead of editing it.',
            );
        }

        $writable = array_intersect_key($changes, array_flip([
            'service_requested', 'reason', 'urgency', 'destination_contact', 'follow_up_on',
        ]));

        $referral->forceFill($writable)->save();

        return $referral->refresh();
    }

    /**
     * Sends it. The one irreversible act.
     *
     * @throws ApiException when the disclosure record is incomplete.
     */
    public function send(Referral $referral, ActorContext $actor): Referral
    {
        return DB::transaction(function () use ($referral, $actor): Referral {
            /** @var Referral $referral */
            $referral = Referral::query()->lockForUpdate()->findOrFail($referral->id);

            if ($referral->status !== ReferralStatus::Draft) {
                throw InvalidStateTransitionException::between($referral->status->value, ReferralStatus::Sent->value);
            }

            /*
             * Re-checked inside the lock, not only at the controller.
             *
             * The lawful basis is what makes the disclosure lawful at all (RA 10173). A check
             * that lives only in a request validator is a check the next write path will not
             * have.
             */
            $blockers = $this->disclosure->blockersFor($referral);

            if ($blockers !== []) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'This referral is not ready to send.',
                    ['blockers' => $blockers],
                );
            }

            $sentAt = now();

            $referral->forceFill([
                'status' => ReferralStatus::Sent,
                'sent_at' => $sentAt,
                // Set now, from urgency, counted from the day it actually went. A default the
                // worker may change — a provider that answers in a day and one that answers in a
                // month are both real, and neither is described by a constant.
                'follow_up_on' => $referral->follow_up_on
                    ?? $sentAt->copy()->addDays($referral->urgency->followUpDays())->toDateString(),
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'referral.sent',
                'Referral sent to '.$referral->destination_name,
                (string) $referral->uuid,
            );

            return $referral->refresh();
        });
    }

    /**
     * Records what the receiving office reported.
     */
    public function recordStatus(
        Referral $referral,
        ReferralStatus $status,
        ActorContext $actor,
        ?string $outcome = null,
    ): Referral {
        return DB::transaction(function () use ($referral, $status, $actor, $outcome): Referral {
            /** @var Referral $referral */
            $referral = Referral::query()->lockForUpdate()->findOrFail($referral->id);

            if (! $referral->status->canMoveTo($status)) {
                throw InvalidStateTransitionException::between($referral->status->value, $status->value);
            }

            // Sending has its own method, because it is the disclosure and carries its own
            // permission and its own preconditions.
            if ($status === ReferralStatus::Sent) {
                throw new ApiException(ErrorCode::BadRequest, 'Use the send endpoint to transmit a referral.');
            }

            /*
             * A refusal, a completion and a closure all need a reason recorded.
             *
             * `declined` because the client has to be told what to do instead; `served` because
             * what they actually received is the only thing that makes the referral worth having
             * sent; `closed` because a referral that simply stops is indistinguishable from one
             * everybody forgot.
             */
            if ($status->requiresOutcome() && trim((string) $outcome) === '') {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'Record what happened before closing this referral.',
                );
            }

            $referral->forceFill([
                'status' => $status,
                'outcome' => $outcome ?? $referral->outcome,
                // Hearing anything at all discharges the follow-up commitment; that is what
                // takes it out of the overdue queue.
                'responded_at' => $referral->responded_at ?? now(),
                'closed_at' => $status->isOpen() ? $referral->closed_at : now(),
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'referral.'.$status->value,
                'Referral recorded as '.$status->value,
                (string) $referral->uuid,
            );

            return $referral->refresh();
        });
    }

    /**
     * Adds a note, to one audience or the other.
     */
    public function addNote(Referral $referral, string $audience, string $body, ActorContext $actor): ReferralNote
    {
        if (! in_array($audience, ['internal', 'receiving-office'], true)) {
            throw new ApiException(ErrorCode::BadRequest, 'That is not a note audience.');
        }

        if (trim($body) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'A note needs something in it.');
        }

        /** @var ReferralNote $note */
        $note = ReferralNote::query()->create([
            'referral_id' => $referral->id,
            'audience' => $audience,
            'body' => $body,
            'author_subject_id' => $actor->subjectId,
            'recorded_at' => now(),
        ]);

        return $note;
    }

    /**
     * The staff queue.
     *
     * @return Builder<Referral>
     */
    public function query(): Builder
    {
        return Referral::query();
    }

    /**
     * Referrals this office undertook to chase and has not heard about.
     *
     * The query behind both the overdue filter and the sweep job. Expressed once so the queue a
     * supervisor reads and the job that raises follow-up work can never disagree — a discrepancy
     * there would be read as the job being broken long before anybody suspected two definitions.
     *
     * @return Builder<Referral>
     */
    public function overdueQuery(?Carbon $on = null): Builder
    {
        $asOf = ($on ?? Carbon::now())->toDateString();

        return Referral::query()
            ->whereIn('status', array_map(
                static fn (ReferralStatus $status): string => $status->value,
                array_filter(ReferralStatus::cases(), static fn (ReferralStatus $s): bool => $s->isOpen()),
            ))
            ->whereNull('responded_at')
            ->whereNotNull('follow_up_on')
            ->whereDate('follow_up_on', '<', $asOf);
    }

    /**
     * Overdue first, then most urgent, then oldest — the order a queue is actually worked in.
     *
     * @param  Builder<Referral>  $query
     * @return Builder<Referral>
     */
    public function inWorkingOrder(Builder $query, ?Carbon $on = null): Builder
    {
        $asOf = ($on ?? Carbon::now())->toDateString();

        return $query
            ->orderByRaw(
                'CASE WHEN responded_at IS NULL AND follow_up_on IS NOT NULL AND follow_up_on < ? THEN 0 ELSE 1 END',
                [$asOf],
            )
            ->orderByRaw("CASE urgency WHEN 'urgent' THEN 0 WHEN 'priority' THEN 1 ELSE 2 END")
            ->orderBy('referred_at');
    }

    /**
     * Every referral for one resident, for the case file and the citizen view.
     *
     * @return Collection<int, Referral>
     */
    public function forResident(string $residentUuid): Collection
    {
        return Referral::query()
            ->where('resident_id', $residentUuid)
            ->orderByDesc('referred_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{provider_id: ?string, destination_type: string, destination_name: string, destination_contact: ?string}
     */
    private function resolveDestination(array $attributes): array
    {
        $providerId = $attributes['provider_id'] ?? null;

        if ($providerId === null) {
            /*
             * A free-text destination is allowed but deliberately awkward, because it is how
             * "PhilHealth Rizal", "Philhealth - Rizal" and "PHIC Rizal" become three offices.
             * It exists for the genuine case of a one-off destination nobody will use again.
             */
            return [
                'provider_id' => null,
                'destination_type' => (string) ($attributes['destination_type'] ?? 'other-lgu-office'),
                'destination_name' => (string) $attributes['destination_name'],
                'destination_contact' => $attributes['destination_contact'] ?? null,
            ];
        }

        $provider = $this->providers->summaryFor((string) $providerId);

        if ($provider === null) {
            throw ResourceNotFoundException::make('That service provider was not found.');
        }

        if (! $provider->isAcceptingReferrals()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That office is not currently accepting referrals.',
            );
        }

        /*
         * SNAPSHOTTED, NOT READ THROUGH.
         *
         * A referral is a record of what was sent, to whom, on a date. If the directory entry is
         * later renamed or retired, the referral must still say where it actually went — so this
         * copy is never refreshed, and that is the point rather than a limitation (ADR 0021 §2).
         */
        return [
            'provider_id' => $provider->id,
            'destination_type' => $provider->destinationType,
            'destination_name' => $provider->name,
            'destination_contact' => $attributes['destination_contact'] ?? $provider->contactLine(),
        ];
    }

    private function resolveCase(mixed $caseUuid, string $residentUuid): ?WelfareCase
    {
        if ($caseUuid === null || $caseUuid === '') {
            return null;
        }

        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', (string) $caseUuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        // A referral must belong to the case's own client. Attaching one family's referral to
        // another family's case would put a disclosure in a file it does not belong to, and
        // every count built on cases afterwards would be wrong in a way nobody could see.
        if ((string) $case->resident_id !== $residentUuid) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That case belongs to a different resident.',
            );
        }

        return $case;
    }
}
