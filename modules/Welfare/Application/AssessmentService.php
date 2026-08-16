<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Facades\DB;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Domain\AssessmentTemplates;
use Modules\Welfare\Domain\Recommendation;
use Modules\Welfare\Infrastructure\Eloquent\Assessment;
use Modules\Welfare\Infrastructure\Eloquent\AssessmentAnswer;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The social worker's structured findings, and the recommendation that follows (ADR 0017 §4).
 *
 * THE INVARIANT THIS CLASS EXISTS TO HOLD: **completing an assessment never approves anything.**
 *
 * A recommendation is a professional opinion. An approval commits public money. Collapsing them
 * would mean a social worker's judgement silently became a decision nobody with approval
 * authority ever made — and because the two look similar in a database, it would be invisible
 * until an audit asked who authorised a payment.
 *
 * The furthest a completed assessment moves a case is `endorsed`, and even that goes through
 * the state machine, needs `request.endorse`, and leaves the approver bound by separation of
 * duties (ADR 0016 §6). The master command permits an automatic eligibility path only behind
 * "an explicit LGU-approved deterministic rule"; none has been supplied, so none exists here
 * (gap G-21).
 */
final class AssessmentService
{
    public function __construct(
        private readonly AssessmentTemplates $templates,
        private readonly CaseTimeline $timeline,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Opens an assessment against a case, pinning the template version.
     *
     * Idempotent per case: an in-progress assessment is returned rather than a second one
     * opened. Two open assessments on one case are two competing sets of findings, and nothing
     * says which the approver should read.
     */
    public function open(WelfareCase $case, string $templateCode, ActorContext $actor): Assessment
    {
        return DB::transaction(function () use ($case, $templateCode, $actor): Assessment {
            if (! $this->templates->exists($templateCode)) {
                throw new ApiException(ErrorCode::BadRequest, 'Unknown assessment template.');
            }

            /** @var Assessment|null $existing */
            $existing = Assessment::query()
                ->where('welfare_case_id', $case->id)
                ->where('status', 'in-progress')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $assessment = Assessment::query()->create([
                'welfare_case_id' => $case->id,
                'template_code' => $templateCode,
                /*
                 * Read once, now, and stored. Reading it again at completion would let a
                 * mid-assessment config deploy change the version an in-progress form claims
                 * to be — and the answers would then be attributed to questions that were not
                 * the ones asked.
                 */
                'template_version' => $this->templates->currentVersion($templateCode),
                'status' => 'in-progress',
                'assessor_subject_id' => $actor->subjectId,
            ]);

            $this->timeline->record(
                $case,
                'assessment.opened',
                "Assessment opened ({$templateCode})",
                // Staff-only. Telling an applicant that an assessment has begun invites them
                // to ask what it says, and the answer is not theirs to have mid-flight.
                null,
                false,
                $actor,
            );

            return $assessment;
        });
    }

    /**
     * Records answers. Repeatable while the assessment is in progress.
     *
     * @param  array<string, string|null>  $answers  keyed by question code
     */
    public function answer(Assessment $assessment, array $answers, ActorContext $actor): Assessment
    {
        return DB::transaction(function () use ($assessment, $answers): Assessment {
            /** @var Assessment $assessment */
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessment->id);

            if ($assessment->isCompleted()) {
                // A completed assessment is evidence. Editing it after the fact would change
                // what an approver was shown when they decided.
                throw new ApiException(ErrorCode::Conflict, 'That assessment has already been completed.');
            }

            $template = (string) $assessment->template_code;

            foreach ($answers as $question => $value) {
                if (! $this->templates->accepts($template, (string) $question, $value === null ? null : (string) $value)) {
                    throw new ApiException(
                        ErrorCode::ValidationFailed,
                        "`{$question}` is not a valid answer for this assessment template.",
                    );
                }

                AssessmentAnswer::query()->updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $question],
                    ['answer_value' => $value === null ? null : (string) $value],
                );
            }

            return $assessment->refresh();
        });
    }

    /**
     * Completes the assessment with a recommendation.
     *
     * Returns the assessment. It does **not** move the case: the suggested next status is
     * offered to the caller, who must go through the state machine with the right permission
     * like anybody else.
     */
    public function complete(
        Assessment $assessment,
        Recommendation $recommendation,
        ActorContext $actor,
        ?string $reason,
        ?string $findings,
    ): Assessment {
        return DB::transaction(function () use ($assessment, $recommendation, $actor, $reason, $findings): Assessment {
            /** @var Assessment $assessment */
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessment->id);

            if ($assessment->isCompleted()) {
                throw new ApiException(ErrorCode::Conflict, 'That assessment has already been completed.');
            }

            $this->assertRequiredAnswersPresent($assessment);

            /*
             * A recommendation to deny must say why.
             *
             * The applicant will be told a decision followed from this, and "the assessor
             * recommended refusal" with no stated basis is not something anybody can appeal or
             * a supervisor can review.
             */
            if ($recommendation === Recommendation::Deny && ($reason === null || trim($reason) === '')) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'A recommendation to deny requires a stated reason.',
                );
            }

            $assessment->forceFill([
                'status' => 'completed',
                'recommendation' => $recommendation,
                'recommendation_reason' => $reason,
                'findings' => $findings,
                'completed_at' => now(),
                // The assessor of record is whoever completed it, not whoever opened it — the
                // person who signs the findings is the one accountable for them.
                'assessor_subject_id' => $actor->subjectId,
            ])->save();

            /** @var WelfareCase $case */
            $case = WelfareCase::query()->findOrFail($assessment->welfare_case_id);

            $this->timeline->record(
                $case,
                'assessment.completed',
                "Assessment completed ({$recommendation->value})",
                // Staff-only, deliberately. The applicant learns the outcome when a decision
                // is made, from the person authorised to make it — not the recommendation that
                // preceded it, which they would reasonably read as the answer.
                null,
                false,
                $actor,
            );

            $this->audit->record(
                $actor->subjectId,
                'assessment.completed',
                // Names the recommendation, never the findings. The findings are the assessor's
                // professional narrative about somebody's circumstances.
                "Assessment completed with {$recommendation->value}",
                (string) $case->uuid,
            );

            return $assessment->refresh();
        });
    }

    public function currentFor(WelfareCase $case): ?Assessment
    {
        /** @var Assessment|null $assessment */
        $assessment = Assessment::query()
            ->where('welfare_case_id', $case->id)
            ->orderByDesc('id')
            ->first();

        return $assessment;
    }

    /**
     * Every required question must have an answer before findings can be signed.
     *
     * Not to be pedantic — because an assessment missing its required answers reads, months
     * later, exactly like one where the assessor concluded "none" or "no risk". The difference
     * matters when somebody is asking why a case was refused.
     */
    private function assertRequiredAnswersPresent(Assessment $assessment): void
    {
        $required = $this->templates->requiredQuestionCodes((string) $assessment->template_code);

        if ($required === []) {
            return;
        }

        $answered = AssessmentAnswer::query()
            ->where('assessment_id', $assessment->id)
            ->whereNotNull('answer_value')
            ->where('answer_value', '!=', '')
            ->pluck('question_code')
            ->all();

        $missing = array_values(array_diff($required, $answered));

        if ($missing !== []) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This assessment is missing required answers: '.implode(', ', $missing).'.',
            );
        }
    }
}
