<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Infrastructure\Eloquent\ConsentRecord;
use Modules\Audit\Infrastructure\Eloquent\LegalHold;
use Modules\Audit\Infrastructure\Eloquent\PrivacyAcknowledgement;
use Modules\Audit\Infrastructure\Eloquent\PrivacyNotice;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Privacy notices, consent and legal holds (ADR 0034 §4–§6).
 *
 * THREE THINGS THAT LOOK SIMILAR AND ARE NOT, which is why they are three tables rather than one
 * `privacy_events` with a `type` column:
 *
 *  * an **acknowledgement** says a person was *shown* how their data will be used;
 *  * a **consent** says they *agreed* to something optional, and can take it back;
 *  * a **legal hold** says a record must not be destroyed, whatever the schedule says.
 *
 * Collapsing them would make the most important question in the file — "may this person withdraw?"
 * — depend on reading a type column correctly at every call site, and the wrong answer is a
 * promise the office cannot keep.
 */
final class GovernanceRegistry
{
    public function __construct(private readonly AuditTrail $audit) {}

    // ── privacy notices ───────────────────────────────────────────────────────────────

    /**
     * The version currently in force, or null if the LGU has published none.
     *
     * NULL IS A REAL ANSWER. A repository that shipped with a notice already published would be
     * putting words in the DPO's mouth, and the words in question are the LGU's statement of how
     * it handles residents' data.
     */
    public function currentNotice(): ?PrivacyNotice
    {
        /** @var PrivacyNotice|null $notice */
        $notice = PrivacyNotice::query()
            ->whereNull('superseded_at')
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $notice;
    }

    /**
     * Publishes a new version and supersedes the previous one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function publishNotice(array $attributes, ActorContext $actor): PrivacyNotice
    {
        return DB::transaction(function () use ($attributes, $actor): PrivacyNotice {
            $previous = $this->currentNotice();

            /** @var PrivacyNotice $notice */
            $notice = PrivacyNotice::query()->create([
                'version' => (string) $attributes['version'],
                'title' => (string) $attributes['title'],
                'document_url' => $attributes['document_url'] ?? null,
                'summary' => (string) $attributes['summary'],
                'effective_from' => $attributes['effective_from'] ?? now(),
            ]);

            /*
             * Superseded, never edited or removed. Acknowledgements of the old version must stay
             * explicable — "she accepted version 2026.1" is only meaningful while 2026.1 is still
             * readable exactly as she saw it.
             */
            if ($previous !== null) {
                $previous->forceFill(['superseded_at' => now()])->save();
            }

            $this->audit->record(
                $actor->subjectId,
                'privacy.notice-published',
                'Privacy notice published: '.$notice->version,
                'Audit.PrivacyNotice',
                (string) $notice->uuid,
            );

            return $notice;
        });
    }

    /**
     * Records that a person has seen the current notice. Idempotent.
     */
    public function acknowledge(string $subjectId, ActorContext $actor): ?PrivacyAcknowledgement
    {
        $notice = $this->currentNotice();

        if ($notice === null) {
            throw new ApiException(ErrorCode::Conflict, 'No privacy notice has been published yet.');
        }

        /** @var PrivacyAcknowledgement $acknowledgement */
        $acknowledgement = PrivacyAcknowledgement::query()->firstOrCreate(
            ['privacy_notice_id' => $notice->id, 'subject_id' => $subjectId],
            ['acknowledged_at' => now(), 'client_channel' => $actor->channel->value],
        );

        return $acknowledgement;
    }

    /**
     * Whether this person has seen the version currently in force.
     */
    public function hasAcknowledgedCurrent(string $subjectId): bool
    {
        $notice = $this->currentNotice();

        if ($notice === null) {
            // Nothing to acknowledge is not the same as failing to acknowledge, and a client that
            // treated it as an outstanding task would block every resident behind a modal.
            return true;
        }

        return PrivacyAcknowledgement::query()
            ->where('privacy_notice_id', $notice->id)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    // ── consent ───────────────────────────────────────────────────────────────────────

    /**
     * The purposes for which consent is genuinely the legal basis.
     *
     * DERIVED FROM `legal_bases`, never listed separately. Two lists would eventually disagree —
     * a purpose marked `consent` in one and `public-task` in the other — and that disagreement
     * decides whether a withdrawal is honoured.
     *
     * @return list<string>
     */
    public function consentPurposes(): array
    {
        $bases = (array) config('privacy.legal_bases', []);

        return array_values(array_keys(array_filter(
            $bases,
            static fn (mixed $basis): bool => $basis === 'consent',
        )));
    }

    /**
     * Records a consent.
     *
     * REFUSES A PURPOSE WHOSE BASIS IS NOT CONSENT, and that refusal is the most useful thing in
     * this class. Recording statutory processing as "consent" is not a labelling error — it is a
     * promise, because consent implies a right to withdraw. An office that offers withdrawal for
     * processing it is legally obliged to perform must then either break the promise or break the
     * law, and the person the promise is broken to is a resident who asked for their data to stop
     * being processed (ADR 0034 §4).
     */
    public function grant(
        string $subjectId,
        string $purpose,
        ActorContext $actor,
        ?string $residentId = null,
        ?string $evidence = null,
    ): ConsentRecord {
        if (! in_array($purpose, $this->consentPurposes(), true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Consent is not the legal basis for that purpose, so it cannot be consented to.',
            );
        }

        return DB::transaction(function () use ($subjectId, $purpose, $actor, $residentId, $evidence): ConsentRecord {
            /** @var ConsentRecord|null $live */
            $live = ConsentRecord::query()
                ->where('subject_id', $subjectId)
                ->where('active_key', $purpose)
                ->first();

            // Already given. Idempotent rather than a conflict — granting twice is a double tap.
            if ($live !== null) {
                return $live;
            }

            /** @var ConsentRecord $record */
            $record = ConsentRecord::query()->create([
                'subject_id' => $subjectId,
                'resident_id' => $residentId,
                'purpose' => $purpose,
                'granted_at' => now(),
                'notice_version' => $this->currentNotice()?->version,
                'evidence' => $evidence,
                'client_channel' => $actor->channel->value,
                // Live, so it collides. Withdrawn rows carry NULL and accumulate freely.
                'active_key' => $purpose,
            ]);

            $this->audit->record(
                $actor->subjectId,
                'privacy.consent-granted',
                'Consent granted: '.$purpose,
                'Audit.Consent',
                (string) $record->uuid,
            );

            return $record;
        });
    }

    /**
     * Withdraws a live consent.
     *
     * The record survives with a timestamp. A withdrawal that deleted the row would leave the
     * office unable to answer "was this photograph published with permission at the time?".
     */
    public function withdraw(string $subjectId, string $purpose, ActorContext $actor, ?string $reason = null): ConsentRecord
    {
        /** @var ConsentRecord|null $record */
        $record = ConsentRecord::query()
            ->where('subject_id', $subjectId)
            ->where('active_key', $purpose)
            ->first();

        if ($record === null) {
            throw new ApiException(ErrorCode::NotFound, 'There is no live consent for that purpose.');
        }

        $record->forceFill([
            'withdrawn_at' => now(),
            'withdrawal_reason' => $reason,
            // NULLed, which frees the person to consent again later.
            'active_key' => null,
        ])->save();

        $this->audit->record(
            $actor->subjectId,
            'privacy.consent-withdrawn',
            'Consent withdrawn: '.$purpose,
            'Audit.Consent',
            (string) $record->uuid,
            reason: $reason,
        );

        return $record->refresh();
    }

    /**
     * @return Builder<ConsentRecord>
     */
    public function consentsFor(string $subjectId): Builder
    {
        return ConsentRecord::query()->where('subject_id', $subjectId)->orderByDesc('id');
    }

    // ── legal holds ───────────────────────────────────────────────────────────────────

    public function placeHold(
        string $entityType,
        ?string $entityId,
        string $reference,
        string $reason,
        ActorContext $actor,
        ?string $subjectId = null,
    ): LegalHold {
        if (trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Record why this hold is being placed.');
        }

        /** @var LegalHold $hold */
        $hold = LegalHold::query()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'subject_id' => $subjectId,
            'reference' => $reference,
            'reason' => $reason,
            'placed_by' => $actor->subjectId,
            'placed_at' => now(),
        ]);

        $this->audit->record(
            $actor->subjectId,
            'privacy.legal-hold-placed',
            'Legal hold placed: '.$reference,
            'Audit.LegalHold',
            (string) $hold->uuid,
            reason: $reason,
        );

        return $hold;
    }

    public function liftHold(LegalHold $hold, string $reason, ActorContext $actor): LegalHold
    {
        if (! $hold->isActive()) {
            throw new ApiException(ErrorCode::Conflict, 'That hold has already been lifted.');
        }

        if (trim($reason) === '') {
            // Lifting a hold is what allows a record to be destroyed. It must say why.
            throw new ApiException(ErrorCode::ValidationFailed, 'Record why this hold is being lifted.');
        }

        $hold->forceFill([
            'lifted_by' => $actor->subjectId,
            'lifted_at' => now(),
            'lift_reason' => $reason,
        ])->save();

        $this->audit->record(
            $actor->subjectId,
            'privacy.legal-hold-lifted',
            'Legal hold lifted: '.$hold->reference,
            'Audit.LegalHold',
            (string) $hold->uuid,
            reason: $reason,
        );

        return $hold->refresh();
    }

    /**
     * Whether anything currently prevents this record being destroyed.
     *
     * **THE ONE METHOD EVERY DELETION PATH MUST CALL.** A hold on the whole subject covers every
     * record about them, because an investigation into a household's assistance does not know in
     * advance which document will matter.
     */
    public function isUnderHold(string $entityType, ?string $entityId = null, ?string $subjectId = null): bool
    {
        return LegalHold::query()
            ->whereNull('lifted_at')
            ->where(function (Builder $where) use ($entityType, $entityId, $subjectId): void {
                $where->where(function (Builder $entity) use ($entityType, $entityId): void {
                    $entity->where('entity_type', $entityType)
                        // A hold with no entity id covers the whole type.
                        ->where(function (Builder $scope) use ($entityId): void {
                            $scope->whereNull('entity_id');

                            if ($entityId !== null) {
                                $scope->orWhere('entity_id', $entityId);
                            }
                        });
                });

                if ($subjectId !== null) {
                    $where->orWhere('subject_id', $subjectId);
                }
            })
            ->exists();
    }

    /**
     * @return Builder<LegalHold>
     */
    public function holds(): Builder
    {
        return LegalHold::query()->orderByDesc('placed_at')->orderByDesc('id');
    }
}
