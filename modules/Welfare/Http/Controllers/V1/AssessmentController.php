<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\IdempotencyService;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\AssessmentService;
use Modules\Welfare\Application\IntakeService;
use Modules\Welfare\Domain\AssessmentTemplates;
use Modules\Welfare\Domain\IntakeSource;
use Modules\Welfare\Domain\Recommendation;
use Modules\Welfare\Infrastructure\Eloquent\Assessment;
use Modules\Welfare\Infrastructure\Eloquent\AssessmentAnswer;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceIntake;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Staff intake and assessment (ADR 0017).
 *
 * Counter intake and assessment share this controller because they share an audience and a
 * permission boundary. They do **not** share a code path with the citizen submission: both go
 * through {@see IntakeService::submit()}, which is the point — one domain operation, two
 * adapters (CLAUDE.md Article 3.1).
 *
 * COMPLETING AN ASSESSMENT APPROVES NOTHING. The endpoint returns a *suggested* next status;
 * moving the case still goes through the transition endpoint, still needs that target's
 * permission, and still faces separation of duties. That is enforced in the service, not here.
 */
final class AssessmentController
{
    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly IntakeService $intakes,
        private readonly AssessmentTemplates $templates,
        private readonly AuthorizationService $authorization,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * Counter intake: a clerk takes a request with the applicant in front of them.
     */
    public function storeIntake(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestCreate);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'category' => ['required', 'string', 'max:48'],
            'narrative' => ['required', 'string', 'max:5000'],
            'urgency' => ['sometimes', 'string', 'in:routine,priority,emergency'],
            'household_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'requested_service_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Staff may assert provenance, within the set a counter can legitimately claim.
            'source' => ['sometimes', 'string', 'in:walk-in,barangay-referral,legacy-import'],
        ]);

        [$status, $body] = $this->idempotency->execute(
            $request->header('Idempotency-Key'),
            $actor->subjectId,
            'POST /api/v1/admin/assistance-intakes',
            $validated,
            function () use ($validated, $actor): array {
                $intake = $this->intakes->submit(
                    $validated,
                    IntakeSource::from($validated['source'] ?? 'walk-in'),
                    $actor,
                );

                return [201, $this->intakeProjection($intake)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * The published assessment templates, with their versions.
     */
    public function templates(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssess);

        return ApiResponse::item(['templates' => $this->templates->all()]);
    }

    public function show(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        $intake = $this->intakes->forCase($model);
        $assessment = $this->assessments->currentFor($model);

        return ApiResponse::item([
            'intake' => $intake === null ? null : $this->intakeDetail($intake),
            'assessment' => $assessment === null ? null : $this->assessmentProjection($assessment),
        ]);
    }

    public function open(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssess);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'template_code' => ['required', 'string', 'max:48'],
        ]);

        return ApiResponse::created($this->assessmentProjection(
            $this->assessments->open($model, $validated['template_code'], $actor),
        ));
    }

    public function answer(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssess);

        $model = $this->caseOrFail($actor, $case);
        $assessment = $this->openAssessmentOrFail($model);

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var array<string, string|null> $answers */
        $answers = $validated['answers'];

        return ApiResponse::item($this->assessmentProjection(
            $this->assessments->answer($assessment, $answers, $actor),
        ));
    }

    /**
     * Signs the findings with a recommendation.
     *
     * Returns `suggested_next_status` — a default for a human to act on, never an instruction.
     * The case does not move here.
     */
    public function complete(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssess);

        $model = $this->caseOrFail($actor, $case);
        $assessment = $this->openAssessmentOrFail($model);

        $validated = $request->validate([
            'recommendation' => ['required', 'string', 'in:'.implode(',', Recommendation::values())],
            'reason' => ['nullable', 'string', 'max:500'],
            'findings' => ['nullable', 'string', 'max:5000'],
        ]);

        $completed = $this->assessments->complete(
            $assessment,
            Recommendation::from($validated['recommendation']),
            $actor,
            $validated['reason'] ?? null,
            $validated['findings'] ?? null,
        );

        return ApiResponse::item($this->assessmentProjection($completed));
    }

    /**
     * Prior cases for the same resident, for context.
     *
     * Identity, category, status and dates — not the narratives, not the assessments, not the
     * amounts. An assessor needs to know this person has come three times this year; reading
     * what they said each time is a separate, separately audited decision (ADR 0017 §5).
     */
    public function history(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssess);

        $model = $this->caseOrFail($actor, $case);

        $prior = $this->intakes->priorCasesFor((string) $model->resident_id, (int) $model->id);

        return ApiResponse::item([
            'prior_cases' => $prior->map(fn (WelfareCase $row): array => [
                'id' => $row->uuid,
                'case_number' => $row->case_number,
                'type' => $row->type->value,
                'status' => $row->status->value,
                'opened_at' => $row->opened_at?->toIso8601ZuluString(),
                'closed_at' => $row->closed_at?->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function intakeProjection(AssistanceIntake $intake): array
    {
        /** @var WelfareCase $case */
        $case = WelfareCase::query()->findOrFail($intake->welfare_case_id);

        return [
            'id' => $intake->uuid,
            'case_id' => $case->uuid,
            'case_number' => $case->case_number,
            'status' => $case->status->value,
            'source' => $intake->source,
            'category' => $intake->category,
            'urgency' => $intake->urgency,
            'submitted_at' => $intake->submitted_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function intakeDetail(AssistanceIntake $intake): array
    {
        return $this->intakeProjection($intake) + [
            // The applicant's own account. Staff-facing: this endpoint is behind
            // `request.view` and every read of it is on the audited case file.
            'narrative' => $intake->narrative,
            'resident_id' => $intake->resident_id,
            'household_id' => $intake->household_id,
            'requested_service_id' => $intake->requested_service_id,
            'consent_reference' => $intake->consent_reference,
            'privacy_notice_version' => $intake->privacy_notice_version,
            'submitted_by' => $intake->submitted_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentProjection(Assessment $assessment): array
    {
        return [
            'id' => $assessment->uuid,
            'template_code' => $assessment->template_code,
            // Pinned at open. A later config change must not appear to have altered what this
            // assessment asked.
            'template_version' => $assessment->template_version,
            'status' => $assessment->status,
            'recommendation' => $assessment->recommendation?->value,
            'recommendation_reason' => $assessment->recommendation_reason,
            'findings' => $assessment->findings,
            'assessor_subject_id' => $assessment->assessor_subject_id,
            'completed_at' => $assessment->completed_at?->toIso8601ZuluString(),
            /*
             * A suggestion, and named as one. Acting on it still goes through the state
             * machine with that target's permission, and the approver still may not be this
             * assessor (ADR 0016 §6).
             */
            'suggested_next_status' => $assessment->recommendation?->suggestedNextStatus()?->value,
            'answers' => $assessment->answers()->get()
                ->map(fn (AssessmentAnswer $answer): array => [
                    'question_code' => $answer->question_code,
                    'answer_value' => $answer->answer_value,
                ])->all(),
        ];
    }

    private function openAssessmentOrFail(WelfareCase $case): Assessment
    {
        $assessment = $this->assessments->currentFor($case);

        if ($assessment === null || $assessment->isCompleted()) {
            throw ResourceNotFoundException::make('No assessment is in progress for that case.');
        }

        return $assessment;
    }

    /**
     * Loads a case and enforces scope, restriction and assignment — the same loader the case
     * controller uses, for the same reason: there must be no verb a caller can switch to in
     * order to reach a case their scope excludes.
     */
    private function caseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        if ($case->isRestricted() && $this->authorization->denies($actor, Permission::RequestViewSensitive)) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        $this->authorization->authorizeBarangay(
            $actor,
            $case->barangay_id === null ? null : (int) $case->barangay_id,
            'That case was not found.',
        );

        if ($actor->scope->requiresCaseAssignment() && $case->assigned_to !== $actor->subjectId) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        return $case;
    }
}
