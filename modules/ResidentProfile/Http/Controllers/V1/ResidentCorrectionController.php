<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentCorrectionService;
use Modules\ResidentProfile\Contracts\CorrectionStatus;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionField;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionRequest;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The reviewer's queue for resident correction requests (ADR 0013 §4).
 *
 * Only requests touching a reviewed field ever reach here — a resident changing their own
 * mobile number is applied immediately and recorded, not queued for somebody's approval.
 * Making staff rubber-stamp phone numbers would bury the requests that actually matter,
 * which are the ones proposing to change a verified name or birth date.
 */
final class ResidentCorrectionController
{
    public function __construct(
        private readonly ResidentCorrectionService $corrections,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        $pagination = PaginationParams::fromRequest($request);

        /*
         * Scoped through the resident's barangay with a join-free subquery: the correction
         * table has no barangay column of its own, and denormalising one onto it would
         * create a second copy of a fact that moves whenever a resident does (ADR 0008 §10).
         */
        $residentIds = $this->authorization->scopeToBarangays(
            $actor,
            Resident::query()->select('id'),
        );

        $query = ResidentCorrectionRequest::query()
            ->whereIn('resident_id', $residentIds)
            ->orderBy('status')
            ->orderByDesc('id');

        $status = $request->query('status');

        if (is_string($status) && CorrectionStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ResidentCorrectionRequest $row): array => $this->reviewerProjection($row),
        );
    }

    public function show(Request $request, ActorContext $actor, string $correction): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        return ApiResponse::item($this->reviewerProjection($this->correctionOrFail($actor, $correction)));
    }

    /**
     * Approves and applies. The changes land through the registry, so they produce the same
     * history, aliases and fingerprint rebuild as any other edit.
     */
    public function approve(Request $request, ActorContext $actor, string $correction): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->corrections->approve(
            $this->correctionOrFail($actor, $correction),
            $actor,
            $validated['review_note'] ?? null,
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    public function reject(Request $request, ActorContext $actor, string $correction): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        $validated = $request->validate([
            // Mandatory. A refusal a resident cannot understand is one they cannot act on
            // or appeal, and RA 10173 gives them the right to ask.
            'review_note' => ['required', 'string', 'max:255'],
        ]);

        $model = $this->corrections->reject(
            $this->correctionOrFail($actor, $correction),
            $actor,
            $validated['review_note'],
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewerProjection(ResidentCorrectionRequest $request): array
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->find($request->resident_id);

        return [
            'id' => $request->uuid,
            'status' => $request->status->value,
            'note' => $request->note,
            'review_note' => $request->review_note,
            'requested_by' => $request->requested_by,
            'reviewed_by' => $request->reviewed_by,
            'reviewed_at' => $request->reviewed_at?->toIso8601ZuluString(),
            'created_at' => $request->created_at?->toIso8601ZuluString(),
            'resident' => $resident === null ? null : [
                'id' => $resident->uuid,
                'name' => $resident->fullName(),
                'barangay_id' => $resident->barangay_id,
                'verification_tier' => $resident->verification_tier->value,
            ],
            'changes' => $request->fields()->get()
                ->map(fn (ResidentCorrectionField $field): array => [
                    'field' => $field->field,
                    // Both shown so the reviewer can see the record may have moved since
                    // the request was filed, rather than approving a stale proposal.
                    'current_value' => $field->current_value,
                    'proposed_value' => $field->proposed_value,
                ])->all(),
        ];
    }

    /**
     * Loads a request and enforces the caller's scope through its resident.
     *
     * Out-of-scope returns NOT FOUND, as everywhere else (OWASP API1).
     */
    private function correctionOrFail(ActorContext $actor, string $uuid): ResidentCorrectionRequest
    {
        /** @var ResidentCorrectionRequest|null $request */
        $request = ResidentCorrectionRequest::query()->where('uuid', $uuid)->first();

        if ($request === null) {
            throw ResourceNotFoundException::make('That correction request was not found.');
        }

        /** @var Resident|null $resident */
        $resident = Resident::query()->find($request->resident_id);

        $this->authorization->authorizeBarangay(
            $actor,
            $resident?->barangay_id === null ? null : (int) $resident->barangay_id,
            'That correction request was not found.',
        );

        return $request;
    }
}
