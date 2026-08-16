<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseEligibilityService;
use Modules\Welfare\Infrastructure\Eloquent\CaseEligibilityCheck;
use Modules\Welfare\Infrastructure\Eloquent\CaseEligibilityResult;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Eligibility guidance against a case (ADR 0018 §6).
 *
 * STAFF ONLY. There is no citizen route here, for the same reason there is none for the
 * vulnerability score: "you are likely ineligible" reads as a refusal however it is worded, and
 * it is not one — nobody has decided anything. An applicant hears an outcome when a person with
 * authority makes it, together with the reason and the route to appeal.
 *
 * The response is deliberately verbose: every criterion, its outcome, the observed value and
 * the explanation an applicant would be given. A caseworker must be able to see *why* the
 * guidance said what it said and disagree with it — which is the difference between guidance
 * and an oracle.
 */
final class CaseEligibilityController
{
    public function __construct(
        private readonly CaseEligibilityService $eligibility,
        private readonly AuthorizationService $authorization,
    ) {}

    public function store(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        // Assessment permission, not approval: running guidance is part of working a case, and
        // deliberately not part of deciding one.
        $this->authorization->authorize($actor, Permission::RequestAssess);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'program_id' => ['required', 'string', 'max:64'],
        ]);

        return ApiResponse::created($this->checkProjection(
            $this->eligibility->check($model, $validated['program_id'], $actor),
        ));
    }

    public function index(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        return ApiResponse::item([
            'checks' => $this->eligibility->historyFor($model)
                ->map(fn (CaseEligibilityCheck $check): array => $this->checkProjection($check))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkProjection(CaseEligibilityCheck $check): array
    {
        return [
            'id' => $check->uuid,
            'program_id' => $check->program_id,
            'program_code' => $check->program_code,
            // Pinned at evaluation. The audit requirement, visible in the payload.
            'guidance_version' => $check->guidance_version,
            'outcome' => $check->outcome,
            /*
             * Stated in the response, not merely documented. A client rendering this must not
             * be able to present it as a determination without contradicting the payload it was
             * handed.
             */
            'is_advisory' => true,
            'evaluated_by' => $check->evaluated_by,
            'evaluated_at' => $check->evaluated_at?->toIso8601ZuluString(),
            'results' => $check->results()->get()
                ->map(fn (CaseEligibilityResult $result): array => [
                    'criterion_code' => $result->criterion_code,
                    'fact' => $result->fact,
                    'result' => $result->result,
                    'explanation' => $result->explanation,
                    // So a caseworker can check the outcome rather than trust it.
                    'observed_value' => $result->observed_value,
                    'is_blocking' => (bool) $result->is_blocking,
                ])->all(),
        ];
    }

    /**
     * The same loader the case controller uses — scope, restriction and assignment — so there
     * is no verb a caller can switch to in order to reach a case their scope excludes.
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
