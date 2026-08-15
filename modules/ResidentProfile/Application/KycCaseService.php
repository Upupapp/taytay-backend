<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ResidentProfile\Contracts\KycStatus;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCaseTransition;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;

/**
 * The onboarding lifecycle: register, submit, screen, review, approve or refuse.
 *
 * THE INVARIANT THIS CLASS EXISTS TO HOLD: registration never produces a verified
 * resident. `register()` and `submit()` touch only the case; the canonical `residents`
 * table is written exclusively by `approve()`, which requires a reviewer's decision about
 * every candidate the matcher found.
 *
 * Every state change goes through `transition()`, which validates against the state
 * machine and writes an append-only transition row. There is no other way to move a case.
 */
final class KycCaseService
{
    public function __construct(
        private readonly ResidentMatcher $matcher,
        private readonly ResidentProfileAudit $audit,
    ) {}

    /**
     * Opens a KYC case for an account.
     *
     * Idempotent by account: a citizen who taps "register" twice, or retries on a dropped
     * connection, gets their existing open case back rather than a second one. Two open
     * cases would be worked by two reviewers independently, and the second approval is
     * exactly the duplicate resident this design forbids.
     *
     * @param  array<string, mixed>  $claims
     */
    public function register(string $accountId, array $claims): KycCase
    {
        return DB::transaction(function () use ($accountId, $claims): KycCase {
            /** @var KycCase|null $existing */
            $existing = KycCase::query()
                ->where('account_id', $accountId)
                ->whereIn('status', KycStatus::openValues())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // An approved case means this account already has a canonical resident. A
            // second registration would be a request for a duplicate, so it is refused
            // rather than quietly opened.
            $approved = KycCase::query()
                ->where('account_id', $accountId)
                ->where('status', KycStatus::Approved->value)
                ->exists();

            if ($approved) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'This account is already linked to a verified resident record.',
                );
            }

            $case = KycCase::query()->create([
                'account_id' => $accountId,
                'status' => KycStatus::Draft,
                'claimed_first_name' => $claims['first_name'],
                'claimed_middle_name' => $claims['middle_name'] ?? null,
                'claimed_last_name' => $claims['last_name'],
                'claimed_suffix' => $claims['suffix'] ?? null,
                'claimed_birth_date' => $claims['birth_date'],
                'claimed_sex' => $claims['sex'],
                'claimed_barangay_id' => $claims['barangay_id'],
                'claimed_street_address' => $claims['street_address'],
                'identity_fingerprint' => IdentityFingerprint::forName(
                    $claims['first_name'],
                    $claims['last_name'],
                    $claims['birth_date'],
                ),
            ]);

            $this->recordTransition($case, null, KycStatus::Draft, null, $accountId);
            $this->audit->record($accountId, 'kyc.case-opened', 'KYC case opened', $case->uuid);

            return $case;
        });
    }

    /**
     * The applicant files the case. Screening runs immediately and the case lands in front
     * of a reviewer — never in `approved`.
     */
    public function submit(KycCase $case, string $actorSubjectId): KycCase
    {
        return DB::transaction(function () use ($case, $actorSubjectId): KycCase {
            $case = KycCase::query()->lockForUpdate()->findOrFail($case->id);

            $this->transition($case, KycStatus::Submitted, null, $actorSubjectId);

            $case->forceFill([
                'submitted_at' => now(),
                // Retention starts at submission: the documents exist to answer one
                // question, and the clock on keeping them starts when it is asked.
                'purge_after' => now()->addDays((int) config('resident_profile.kyc.retention_days')),
            ])->save();

            $this->transition($case, KycStatus::Screening, null, $actorSubjectId);

            $candidates = $this->matcher->screen($case);

            /*
             * Screening always hands over to a person. Even zero candidates goes to
             * manual review, because "no existing resident matches" is precisely the
             * moment a new canonical record would be created — the highest-consequence
             * decision in the whole flow.
             */
            $this->transition(
                $case,
                KycStatus::ManualReview,
                $candidates->isEmpty()
                    ? 'No existing resident matched; awaiting decision to create a new record'
                    : "Screening found {$candidates->count()} candidate(s)",
                $actorSubjectId,
            );

            $this->audit->record($actorSubjectId, 'kyc.case-submitted', 'KYC case submitted for review', $case->uuid);

            return $case->refresh();
        });
    }

    /**
     * A reviewer approves the case, either linking an existing resident or creating one.
     *
     * This is the only path to a canonical resident, and the only path to a verified tier.
     *
     * @param  string|null  $linkResidentUuid  link to this resident, or null to create a new one
     */
    public function approve(KycCase $case, ActorContext $actor, ?string $linkResidentUuid, ?string $message = null): KycCase
    {
        return DB::transaction(function () use ($case, $actor, $linkResidentUuid, $message): KycCase {
            $case = KycCase::query()->lockForUpdate()->findOrFail($case->id);

            $this->assertEveryCandidateDecided($case);

            $resident = $linkResidentUuid === null
                ? $this->createResidentFrom($case)
                : $this->linkExistingResident($case, $linkResidentUuid);

            $this->transition($case, KycStatus::Approved, 'Approved by reviewer', $actor->subjectId);

            $case->forceFill([
                'resolved_resident_id' => $resident->uuid,
                'reviewed_by' => $actor->subjectId,
                'reviewed_at' => now(),
                'applicant_message' => $message,
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'kyc.case-approved',
                $linkResidentUuid === null
                    ? 'KYC approved; new resident record created'
                    : 'KYC approved; linked to an existing resident record',
                $case->uuid,
            );

            return $case->refresh();
        });
    }

    public function reject(KycCase $case, ActorContext $actor, string $reason, ?string $message = null): KycCase
    {
        return DB::transaction(function () use ($case, $actor, $reason, $message): KycCase {
            $case = KycCase::query()->lockForUpdate()->findOrFail($case->id);

            $this->transition($case, KycStatus::Rejected, $reason, $actor->subjectId);

            $case->forceFill([
                'reviewed_by' => $actor->subjectId,
                'reviewed_at' => now(),
                // The applicant gets a decision, not the internal reasoning.
                'applicant_message' => $message,
            ])->save();

            $this->audit->record($actor->subjectId, 'kyc.case-rejected', 'KYC case rejected', $case->uuid);

            return $case->refresh();
        });
    }

    public function requestMoreInformation(KycCase $case, ActorContext $actor, string $message): KycCase
    {
        return DB::transaction(function () use ($case, $actor, $message): KycCase {
            $case = KycCase::query()->lockForUpdate()->findOrFail($case->id);

            $this->transition($case, KycStatus::NeedsMoreInformation, 'Returned to applicant', $actor->subjectId);

            $case->forceFill(['applicant_message' => $message])->save();

            return $case->refresh();
        });
    }

    /**
     * Moves a case, or refuses to.
     *
     * The state machine is the authority (ADR 0010 §2); nothing assigns `status` directly.
     */
    public function transition(KycCase $case, KycStatus $to, ?string $reason, ?string $actorSubjectId): void
    {
        $from = $case->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStateTransitionException::between($from->value, $to->value);
        }

        $case->forceFill(['status' => $to])->save();

        $this->recordTransition($case, $from, $to, $reason, $actorSubjectId);
    }

    /**
     * A reviewer must have ruled on every candidate before the case can be approved.
     *
     * Without this, a reviewer could approve a case with an unexamined exact match sitting
     * on it and create the duplicate the matcher had already spotted.
     */
    private function assertEveryCandidateDecided(KycCase $case): void
    {
        $undecided = $case->candidates()->where('decision', 'undecided')->count();

        if ($undecided > 0) {
            throw new ApiException(
                ErrorCode::Conflict,
                "There are {$undecided} possible duplicate record(s) still to be reviewed.",
            );
        }
    }

    private function createResidentFrom(KycCase $case): Resident
    {
        /*
         * Refuse to create a new resident when the reviewer has affirmatively marked a
         * candidate as the same person. That combination is a contradiction, and the
         * expensive half of it — a duplicate canonical record — is the one this whole TAB
         * exists to prevent.
         */
        $sameAsExisting = $case->candidates()->where('decision', 'same-person')->exists();

        if ($sameAsExisting) {
            throw new ApiException(
                ErrorCode::Conflict,
                'A candidate was marked as the same person; link to that record instead of creating a new one.',
            );
        }

        return Resident::query()->create([
            'first_name' => $case->claimed_first_name,
            'middle_name' => $case->claimed_middle_name,
            'last_name' => $case->claimed_last_name,
            'suffix' => $case->claimed_suffix,
            'sex' => $case->claimed_sex,
            'birth_date' => $case->claimed_birth_date,
            'civil_status' => 'single',
            'barangay_id' => $case->claimed_barangay_id,
            'street_address' => $case->claimed_street_address,
            'identity_fingerprint' => $case->identity_fingerprint,
            // The reviewer checked documents, so this record is verified — by a person,
            // which is the only way any record reaches this tier.
            'verification_tier' => VerificationTier::Verified,
            'verified_at' => now(),
        ]);
    }

    private function linkExistingResident(KycCase $case, string $residentUuid): Resident
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->lockForUpdate()->first();

        if ($resident === null) {
            throw new ApiException(ErrorCode::NotFound, 'That resident record was not found.');
        }

        // The reviewer must have looked at this specific record and said it is the same
        // person. Linking to an arbitrary resident id would be a way to attach an account
        // to somebody else's record entirely.
        $confirmed = $case->candidates()
            ->where('resident_id', $resident->id)
            ->where('decision', 'same-person')
            ->exists();

        if (! $confirmed) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That record has not been confirmed as the same person.',
            );
        }

        if (! $resident->isVerified()) {
            $resident->forceFill([
                'verification_tier' => VerificationTier::Verified,
                'verified_at' => now(),
            ])->save();
        }

        return $resident;
    }

    private function recordTransition(KycCase $case, ?KycStatus $from, KycStatus $to, ?string $reason, ?string $actorSubjectId): void
    {
        KycCaseTransition::query()->create([
            'uuid' => (string) Str::uuid7(),
            'kyc_case_id' => $case->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor_subject_id' => $actorSubjectId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
