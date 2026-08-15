<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\KycCaseService;
use Modules\ResidentProfile\Application\ResidentMatcher;
use Modules\ResidentProfile\Contracts\KycStatus;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMatchCandidate;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * KYC: the applicant's own case, and the reviewer's queue.
 *
 * Two audiences, one service. The citizen routes resolve the case from the authenticated
 * account — there is no identifier to tamper with — and the staff routes require an
 * explicit permission and return a wider projection. Same lifecycle, same rules, different
 * authorization (ADR 0002).
 */
final class KycController
{
    public function __construct(
        private readonly KycCaseService $cases,
        private readonly ResidentMatcher $matcher,
        private readonly AuthorizationService $authorization,
    ) {}

    // ── applicant ─────────────────────────────────────────────────────────────────────

    /**
     * Opens or returns the caller's KYC case.
     *
     * Idempotent by account: tapping "register" twice returns the same case. It does NOT
     * create a resident — nothing here touches the canonical registry.
     */
    public function register(Request $request, ActorContext $actor): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:96'],
            'middle_name' => ['nullable', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'birth_date' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', 'in:female,male'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'street_address' => ['required', 'string', 'max:191'],
        ]);

        $case = $this->cases->register((string) $actor->subjectId, $validated);

        return ApiResponse::item($this->applicantProjection($case), 201);
    }

    public function showOwn(Request $request, ActorContext $actor): JsonResponse
    {
        return ApiResponse::item($this->applicantProjection($this->ownCaseOrFail($actor)));
    }

    public function submitOwn(Request $request, ActorContext $actor): JsonResponse
    {
        $case = $this->ownCaseOrFail($actor);

        if (! $case->status->isEditableByApplicant()) {
            throw new ApiException(ErrorCode::Conflict, 'This application is not awaiting your input.');
        }

        return ApiResponse::item(
            $this->applicantProjection($this->cases->submit($case, (string) $actor->subjectId)),
        );
    }

    // ── reviewer ──────────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $pagination = PaginationParams::fromRequest($request);
        $status = $request->query('status');

        $query = KycCase::query()->orderBy('submitted_at');

        if (is_string($status) && KycStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $cases = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($cases->all(), $total, $pagination),
            fn (KycCase $case): array => $this->reviewerProjection($case),
        );
    }

    public function show(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $model = $this->caseOrFail($case);

        return ApiResponse::item(
            $this->reviewerProjection($model) + ['candidates' => $this->candidateProjection($model)],
        );
    }

    /**
     * Re-runs deterministic matching. Idempotent — decisions already made are preserved.
     */
    public function rescreen(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $model = $this->caseOrFail($case);
        $this->matcher->screen($model);

        return ApiResponse::item(['candidates' => $this->candidateProjection($model->refresh())]);
    }

    /**
     * The reviewer rules on one possible duplicate.
     *
     * Every candidate must be decided before the case can be approved, so this is the step
     * that actually prevents duplicate residents.
     */
    public function decideCandidate(Request $request, ActorContext $actor, string $case, string $candidate): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:same-person,different-person'],
        ]);

        $model = $this->caseOrFail($case);

        /** @var ResidentMatchCandidate|null $row */
        $row = $model->candidates()->where('uuid', $candidate)->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That candidate was not found.');
        }

        $row->forceFill([
            'decision' => $validated['decision'],
            'decided_by' => $actor->subjectId,
            'decided_at' => now(),
        ])->save();

        return ApiResponse::item(['candidates' => $this->candidateProjection($model->refresh())]);
    }

    /**
     * Approve — the only path to a canonical resident record.
     */
    public function approve(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycApprove);

        $validated = $request->validate([
            'link_resident_id' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->cases->approve(
            $this->caseOrFail($case),
            $actor,
            $validated['link_resident_id'] ?? null,
            $validated['message'] ?? null,
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    public function reject(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycApprove);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->cases->reject(
            $this->caseOrFail($case),
            $actor,
            $validated['reason'],
            $validated['message'] ?? null,
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    public function requestMoreInformation(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $validated = $request->validate(['message' => ['required', 'string', 'max:255']]);

        $model = $this->cases->requestMoreInformation($this->caseOrFail($case), $actor, $validated['message']);

        return ApiResponse::item($this->reviewerProjection($model));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * What the applicant sees.
     *
     * Their own claims, the status, and the message a reviewer chose to send them.
     * Deliberately absent: match candidates, internal transition reasons, reviewer
     * identities and any other resident's details — an applicant must never learn that
     * their record resembles somebody else's (visibility matrix §3).
     *
     * @return array<string, mixed>
     */
    private function applicantProjection(KycCase $case): array
    {
        return [
            'id' => $case->uuid,
            'status' => $case->status->value,
            'can_edit' => $case->status->isEditableByApplicant(),
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'message' => $case->applicant_message,
            'claimed' => [
                'first_name' => $case->claimed_first_name,
                'middle_name' => $case->claimed_middle_name,
                'last_name' => $case->claimed_last_name,
                'suffix' => $case->claimed_suffix,
                'birth_date' => $case->claimed_birth_date?->toDateString(),
            ],
            'resident_id' => $case->resolved_resident_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewerProjection(KycCase $case): array
    {
        return [
            'id' => $case->uuid,
            'status' => $case->status->value,
            'account_id' => $case->account_id,
            'claimed_name' => $case->claimedFullName(),
            'claimed_birth_date' => $case->claimed_birth_date?->toDateString(),
            'claimed_barangay_id' => $case->claimed_barangay_id,
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'reviewed_at' => $case->reviewed_at?->toIso8601ZuluString(),
            'resident_id' => $case->resolved_resident_id,
            'undecided_candidates' => $case->candidates()->where('decision', 'undecided')->count(),
        ];
    }

    /**
     * Candidates carry only enough of the existing resident for a reviewer to tell whether
     * it is the same person — name, birth date, barangay. Not their income, sectors or
     * case history: deciding a duplicate does not require reading somebody's welfare file.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateProjection(KycCase $case): array
    {
        return $case->candidates()->get()->map(function (ResidentMatchCandidate $candidate): array {
            /** @var Resident|null $resident */
            $resident = Resident::query()->find($candidate->resident_id);

            return [
                'id' => $candidate->uuid,
                'rule' => $candidate->rule,
                'confidence' => $candidate->confidence,
                'decision' => $candidate->decision,
                'resident' => $resident === null ? null : [
                    'id' => $resident->uuid,
                    'name' => $resident->fullName(),
                    'birth_date' => $resident->birth_date?->toDateString(),
                    'barangay_id' => $resident->barangay_id,
                    'verification_tier' => $resident->verification_tier->value,
                ],
            ];
        })->all();
    }

    private function ownCaseOrFail(ActorContext $actor): KycCase
    {
        /** @var KycCase|null $case */
        $case = KycCase::query()
            ->where('account_id', $actor->subjectId)
            ->orderByDesc('id')
            ->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('You have no application in progress.');
        }

        return $case;
    }

    private function caseOrFail(string $uuid): KycCase
    {
        /** @var KycCase|null $case */
        $case = KycCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That application was not found.');
        }

        return $case;
    }
}
