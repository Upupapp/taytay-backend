<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Domain\IntakeSource;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceDraft;

/**
 * Work-in-progress intake forms (ADR 0017 §3).
 *
 * OWNERSHIP IS PART OF EVERY LOOKUP, NOT A CHECK AFTER ONE. A draft holds a narrative the
 * applicant has not yet chosen to submit — often the most revealing text they will ever type
 * into this system — and it is by definition unfinished, unverified and easy to misread. Every
 * query here is scoped by `owner_subject_id`, so another caller's draft id resolves to nothing
 * rather than to a `403` that confirms it exists.
 *
 * EXPIRY IS ENFORCED, NOT DECORATIVE. An expired draft is refused rather than silently
 * resurrected. The retention clock is a privacy commitment under RA 10173's storage-limitation
 * principle; quietly extending it whenever somebody returns would make the commitment
 * meaningless, and the row would live forever.
 */
final class DraftService
{
    public function __construct(private readonly WelfareAudit $audit) {}

    /**
     * Opens a draft, or returns the caller's existing open one.
     *
     * Idempotent by owner and source: a citizen who taps "apply" twice, or reopens the app,
     * resumes the form they were filling in rather than starting a second one. Two open drafts
     * are two half-finished stories about the same need, and the applicant submits whichever
     * one they happen to be looking at.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openOrResume(IntakeSource $source, array $attributes, ActorContext $actor): AssistanceDraft
    {
        return DB::transaction(function () use ($source, $attributes, $actor): AssistanceDraft {
            /** @var AssistanceDraft|null $existing */
            $existing = AssistanceDraft::query()
                ->where('owner_subject_id', $actor->subjectId)
                ->where('source', $source->value)
                ->whereNull('submitted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return AssistanceDraft::query()->create($attributes + [
                'owner_subject_id' => $actor->subjectId,
                'source' => $source->value,
                'expires_at' => now()->addDays((int) config('welfare.drafts.retention_days', 30)),
            ]);
        });
    }

    /**
     * Updates a draft in place.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(AssistanceDraft $draft, array $changes, ActorContext $actor): AssistanceDraft
    {
        return DB::transaction(function () use ($draft, $changes): AssistanceDraft {
            /** @var AssistanceDraft $draft */
            $draft = AssistanceDraft::query()->lockForUpdate()->findOrFail($draft->id);

            $this->assertEditable($draft);

            $draft->fill($changes);
            $draft->save();

            return $draft->refresh();
        });
    }

    /**
     * The caller's own draft, or nothing.
     *
     * Ownership is in the WHERE clause. A caller asking for somebody else's draft is answered
     * exactly as if it did not exist.
     */
    public function ownedOrFail(string $uuid, ActorContext $actor): AssistanceDraft
    {
        /** @var AssistanceDraft|null $draft */
        $draft = AssistanceDraft::query()
            ->where('uuid', $uuid)
            ->where('owner_subject_id', $actor->subjectId)
            ->first();

        if ($draft === null) {
            throw ResourceNotFoundException::make('That draft was not found.');
        }

        return $draft;
    }

    /**
     * @return Collection<int, AssistanceDraft>
     */
    public function openFor(ActorContext $actor): Collection
    {
        return AssistanceDraft::query()
            ->where('owner_subject_id', $actor->subjectId)
            ->whereNull('submitted_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Discards a draft the applicant no longer wants.
     *
     * A real delete, unusually for this codebase. An unsubmitted draft is not a record of
     * anything the office did — nobody has acted on it, no decision rests on it, and it holds
     * personal data the applicant has explicitly decided not to give. Keeping it "for the
     * audit trail" would be retaining data whose only justification was a request that was
     * never made (RA 10173 data minimisation).
     */
    public function discard(AssistanceDraft $draft, ActorContext $actor): void
    {
        $this->assertEditable($draft);

        $this->audit->record(
            $actor->subjectId,
            'intake.draft-discarded',
            'Assistance draft discarded before submission',
            null,
        );

        $draft->delete();
    }

    private function assertEditable(AssistanceDraft $draft): void
    {
        if ($draft->isSubmitted()) {
            throw new ApiException(ErrorCode::Conflict, 'That draft has already been submitted.');
        }

        if ($draft->isExpired()) {
            throw new ApiException(ErrorCode::Conflict, 'That draft has expired. Please start a new request.');
        }
    }
}
