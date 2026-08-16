<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\CaseType;
use Modules\Welfare\Domain\IntakeSource;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceDraft;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceIntake;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Turning a request for help into a case (ADR 0017).
 *
 * ONE SUBMISSION PATH FOR EVERY CHANNEL. A walk-in typed by a clerk, a form filed from the
 * citizen web portal and a retried mobile submission all arrive here and produce the same
 * case, the same opening transition and the same timeline entry. The channel is recorded as
 * provenance; it changes nothing about the rules (CLAUDE.md Article 3.1).
 *
 * That is the acceptance criterion for this TAB made structural rather than promised: there is
 * no second code path to drift, because there is no second code path.
 *
 * WHAT THIS SERVICE DELIBERATELY DOES NOT DO is decide anything. It opens a case at
 * `submitted` and stops. No eligibility, no priority, no routing — and specifically no reading
 * of the vulnerability score, which remains unapproved placeholder weights (gap G-20) and
 * touches nothing consequential here or in TAB 11 (ADR 0017 §6).
 */
final class IntakeService
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseTimeline $timeline,
        private readonly ResidentDirectory $residents,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Submits an intake and opens the case it describes.
     *
     * Runs in one transaction: a case with no intake would be a file the office cannot read,
     * and an intake with no case would be a request nobody is working.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function submit(array $attributes, IntakeSource $source, ActorContext $actor): AssistanceIntake
    {
        return DB::transaction(function () use ($attributes, $source, $actor): AssistanceIntake {
            $resident = $this->residents->summaryFor((string) $attributes['resident_id']);

            if ($resident === null) {
                throw ResourceNotFoundException::make('That resident was not found.');
            }

            /*
             * Self-service submissions must carry the applicant's own acknowledgement of the
             * privacy notice, and which version they saw.
             *
             * A counter intake is covered by the clerk's process — the applicant is standing
             * there and the notice is given verbally and on paper. An unattended submission has
             * no such witness, so the acknowledgement is the only evidence that RA 10173's
             * transparency obligation was met at all.
             */
            if ($source->isSelfService() && ($attributes['consent_reference'] ?? null) === null) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'A privacy notice acknowledgement is required before submitting online.',
                );
            }

            $case = $this->cases->open([
                'resident_id' => $resident->id,
                'household_id' => $attributes['household_id'] ?? null,
                'program_id' => $attributes['requested_service_id'] ?? null,
                'type' => $this->caseTypeFor((string) $attributes['category']),
                'barangay_id' => $resident->barangayId,
            ], $actor);

            $intake = AssistanceIntake::query()->create([
                'welfare_case_id' => $case->id,
                'resident_id' => $resident->id,
                'household_id' => $attributes['household_id'] ?? null,
                'source' => $source->value,
                'category' => $attributes['category'],
                'urgency' => $attributes['urgency'] ?? 'routine',
                'narrative' => $attributes['narrative'],
                'requested_service_id' => $attributes['requested_service_id'] ?? null,
                'consent_reference' => $attributes['consent_reference'] ?? null,
                'privacy_notice_version' => $attributes['privacy_notice_version'] ?? null,
                'draft_id' => $attributes['draft_id'] ?? null,
                'submitted_by' => $actor->subjectId,
                'submitted_at' => now(),
            ]);

            /*
             * Straight to `submitted`. A case opened by a real request is not a draft — the
             * office has been asked to do something, and leaving it in `draft` would hide it
             * from the queue that exists to make sure somebody picks it up.
             */
            $this->cases->transition(
                $case,
                CaseStatus::Submitted,
                $actor,
                // Submission authority came from the caller: a citizen submitting their own
                // request, or staff holding `request.create`. Both were established before
                // reaching here.
                static fn (string $permission): bool => true,
            );

            $this->timeline->record(
                $case,
                'intake.submitted',
                "Intake submitted via {$source->value}",
                'We have received your request.',
                true,
                $actor,
            );

            $this->audit->record(
                $actor->subjectId,
                'intake.submitted',
                // Names the event and the channel, never the narrative. A trail repeating the
                // applicant's account becomes a second, less-guarded copy of it (Article 5.5).
                "Assistance intake submitted ({$source->value})",
                (string) $case->uuid,
            );

            return $intake;
        });
    }

    /**
     * Submits a draft, and marks the draft as consumed.
     *
     * The draft is kept rather than deleted, so a client that retries after the response was
     * lost can be told "you already sent this" and shown the case — instead of being handed a
     * blank form and starting again.
     */
    public function submitDraft(AssistanceDraft $draft, ActorContext $actor): AssistanceIntake
    {
        return DB::transaction(function () use ($draft, $actor): AssistanceIntake {
            /** @var AssistanceDraft $draft */
            $draft = AssistanceDraft::query()->lockForUpdate()->findOrFail($draft->id);

            if ($draft->isSubmitted()) {
                throw new ApiException(ErrorCode::Conflict, 'That draft has already been submitted.');
            }

            if ($draft->isExpired()) {
                // The retention clock is a privacy commitment. Quietly extending it whenever
                // somebody returns would make the commitment meaningless.
                throw new ApiException(ErrorCode::Conflict, 'That draft has expired. Please start a new request.');
            }

            foreach (['resident_id', 'category', 'narrative'] as $required) {
                if (($draft->getAttribute($required) ?? '') === '') {
                    throw new ApiException(
                        ErrorCode::ValidationFailed,
                        "This request cannot be submitted yet: `{$required}` is missing.",
                    );
                }
            }

            $intake = $this->submit([
                'resident_id' => $draft->resident_id,
                'household_id' => $draft->household_id,
                'category' => $draft->category,
                'urgency' => $draft->urgency ?? 'routine',
                'narrative' => $draft->narrative,
                'requested_service_id' => $draft->requested_service_id,
                'consent_reference' => $draft->consent_reference,
                'privacy_notice_version' => $draft->privacy_notice_version,
                'draft_id' => $draft->uuid,
            ], IntakeSource::from((string) $draft->source), $actor);

            /** @var WelfareCase $case */
            $case = WelfareCase::query()->findOrFail($intake->welfare_case_id);

            $draft->forceFill([
                'submitted_at' => now(),
                'submitted_case_id' => $case->uuid,
            ])->save();

            return $intake;
        });
    }

    public function forCase(WelfareCase $case): ?AssistanceIntake
    {
        /** @var AssistanceIntake|null $intake */
        $intake = AssistanceIntake::query()->where('welfare_case_id', $case->id)->first();

        return $intake;
    }

    /**
     * Prior cases for the same resident, for an assessor's context.
     *
     * Deliberately narrow: identity, category, status and dates. Not the narratives, not the
     * assessments, not the amounts. An assessor needs to know that this person has come three
     * times this year; reading what they said each time is a separate decision with its own
     * audited endpoint (ADR 0017 §5).
     *
     * A citizen can never reach this — it is keyed by resident and gated by `request.view`,
     * and no citizen route calls it.
     *
     * @return Collection<int, WelfareCase>
     */
    public function priorCasesFor(string $residentUuid, ?int $excludeCaseId = null): Collection
    {
        return WelfareCase::query()
            ->where('resident_id', $residentUuid)
            ->when($excludeCaseId !== null, fn ($q) => $q->where('id', '!=', $excludeCaseId))
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get();
    }

    /**
     * Maps a presenting need to the case type that governs its handling.
     *
     * Unknown categories fall back to `assistance` rather than being refused: presenting needs
     * arrive by DSWD circular, and a clerk must not be blocked from recording what is in front
     * of them because a mapping is behind.
     */
    private function caseTypeFor(string $category): CaseType
    {
        return match ($category) {
            'medical', 'hospitalisation', 'burial' => CaseType::Medical,
            'educational' => CaseType::Educational,
            'relief', 'disaster' => CaseType::Relief,
            'livelihood' => CaseType::Livelihood,
            // Protective work is never opened by a self-service form. It is opened
            // deliberately, by somebody holding `request.view-sensitive` (ADR 0016 §5).
            default => CaseType::Assistance,
        };
    }
}
