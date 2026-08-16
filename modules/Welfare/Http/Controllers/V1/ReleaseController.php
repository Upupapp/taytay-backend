<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\IdempotencyService;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\ReleaseService;
use Modules\Welfare\Domain\ReleaseStatus;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use Modules\Welfare\Infrastructure\Eloquent\ReleaseBatch;
use Modules\Welfare\Infrastructure\Eloquent\ReleaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Release and distribution tracking, for staff (ADR 0023).
 *
 * TWO PERMISSIONS, AND THE SPLIT IS THE POINT:
 *
 *  * `request.schedule` — prepare a release, defer it, cancel it, run a batch. Scheduling
 *    decisions a caseworker makes.
 *  * `request.release` — **confirm that assistance was handed over.** Held by
 *    `disbursing_officer` and by nobody who can approve a case.
 *
 * And a third control that is not a permission at all: the person who *approved* the case may not
 * release its money, checked against the approver snapshot at confirmation time. Two roles is the
 * design; one person holding both is the failure, and it arrives the moment somebody is granted a
 * second role to cover a colleague's leave (ADR 0023 §3).
 */
final class ReleaseController
{
    public function __construct(
        private readonly ReleaseService $releases,
        private readonly ResidentDirectory $residents,
        private readonly IdempotencyService $idempotency,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->releases->query();

        foreach (['status', 'kind', 'release_mode', 'resident_id', 'program_id'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        foreach ([['from', '>='], ['to', '<=']] as [$param, $operator]) {
            $value = $request->query($param);

            if (is_string($value) && $value !== '') {
                $query->whereDate('scheduled_for', $operator, $value);
            }
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Release $release): array => $this->projection($release),
        );
    }

    public function show(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->releaseOrFail($actor, $release);

        return ApiResponse::item($this->projection($model) + [
            'transitions' => $model->transitions()->get()->map(fn (ReleaseTransition $t): array => [
                'from' => $t->from_status,
                'to' => $t->to_status,
                'reason' => $t->reason,
                'occurred_at' => $t->occurred_at?->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    /**
     * Prepares a release against an approved case.
     */
    public function store(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:cash,in-kind'],
            // INTEGER CENTAVOS. Never a decimal string, never a float — a peso figure that has
            // been through a float is a peso figure nobody can reconcile (ADR 0023 §1).
            'amount_centavos' => ['required_if:kind,cash', 'nullable', 'integer', 'min:1'],
            'in_kind_description' => ['required_if:kind,in-kind', 'nullable', 'string', 'max:255'],
            'release_mode' => ['required', 'string', 'max:32'],
            'program_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'funding_source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'approval_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'release_location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::created($this->projection(
            $this->releases->prepare($model, $validated, $actor),
        ));
    }

    /**
     * Confirms that assistance was handed over.
     *
     * IDEMPOTENT. `Idempotency-Key` is not optional here in practice: a payout table has a weak
     * connection and a queue behind it, and an unprotected retry records a second release for a
     * family that received once. The key replays the first answer; the row lock inside the
     * service handles the different failure of two staff clicking at the same moment.
     */
    public function confirm(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestRelease);

        $model = $this->releaseOrFail($actor, $release);

        $validated = $request->validate([
            'acknowledged_by_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            // Frequently not the beneficiary: an elderly person sends a daughter. Recording only
            // "released" loses the one fact a dispute turns on.
            'acknowledged_relationship' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The METHOD only. No signature image, no thumbprint — the mark stays on the paper.
            'acknowledgement_method' => ['sometimes', 'nullable', 'string', 'in:signature,thumbmark,digital-confirmation,witnessed'],
        ]);

        [$status, $body] = $this->idempotency->execute(
            $request->header('Idempotency-Key'),
            $actor->subjectId,
            'POST /api/v1/admin/releases/{release}/confirmation',
            ['release' => $release] + $validated,
            function () use ($model, $validated, $actor): array {
                return [200, $this->projection($this->releases->confirmRelease($model, $validated, $actor))];
            },
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * Completed, failed, deferred or cancelled.
     */
    public function transition(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $model = $this->releaseOrFail($actor, $release);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:completed,failed,deferred,cancelled,ready'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $target = ReleaseStatus::from($validated['status']);

        // The permission comes from the TARGET state, not from the route — `completed` closes out
        // a handover and belongs to whoever may release, while deferring is scheduling.
        $this->authorization->authorize($actor, $target->requiredPermission());

        return ApiResponse::item($this->projection($this->releases->transition(
            $model,
            $target,
            $validated['reason'] ?? null,
            $actor,
        )));
    }

    // ── batches and manifests ─────────────────────────────────────────────────────────

    public function storeBatch(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scheduled_for' => ['required', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /** @var ReleaseBatch $batch */
        $batch = ReleaseBatch::query()->create($validated + [
            'status' => 'open',
            'opened_by' => $actor->subjectId,
        ]);

        return ApiResponse::created($this->batchProjection($batch));
    }

    public function addToBatch(Request $request, ActorContext $actor, string $batch): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $model = $this->batchOrFail($batch);

        $validated = $request->validate([
            'release_id' => ['required', 'string', 'max:64'],
        ]);

        $release = $this->releaseOrFail($actor, $validated['release_id']);

        if ($release->status !== ReleaseStatus::Ready) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only a release that is ready can be added to a distribution run.',
            );
        }

        $release->forceFill(['release_batch_id' => $model->id])->save();

        return ApiResponse::item($this->projection($release->refresh()));
    }

    /**
     * The manifest for a distribution run.
     */
    public function manifest(Request $request, ActorContext $actor, string $batch): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->batchOrFail($batch);

        $rows = $this->releases->manifestQuery((string) $model->uuid)->get();

        return ApiResponse::item([
            'batch' => $this->batchProjection($model),
            /*
             * Ordered by reference, not by name. Two copies printed an hour apart then match line
             * for line, which is what makes a paper manifest checkable against a screen at a
             * table with a queue in front of it.
             */
            'lines' => $rows->map(fn (Release $release): array => $this->projection($release))->all(),
            'total_count' => $rows->count(),
            /*
             * A total in CENTAVOS, summed as integers. In-kind releases contribute nothing —
             * a relief pack has a notional value, and adding it here would produce a peso figure
             * that says cash was handed over when rice was.
             */
            'total_cash_centavos' => $rows->sum(
                static fn (Release $release): int => $release->amountCentavos() ?? 0,
            ),
            'currency' => 'PHP',
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function projection(Release $release): array
    {
        return [
            'id' => $release->uuid,
            'reference_number' => $release->reference_number,
            'resident_id' => $release->resident_id,
            'program_id' => $release->program_id,
            'program_code' => $release->program_code,
            'approval_reference' => $release->approval_reference,
            'sequence' => (int) $release->sequence,
            'kind' => $release->kind,
            // Integer centavos plus an explicit currency (conventions §6). Never a formatted
            // string — formatting is the client's, and a server that formats money has decided
            // a locale on somebody's behalf.
            'amount_centavos' => $release->amountCentavos(),
            'currency' => $release->currency,
            'in_kind_description' => $release->in_kind_description,
            'release_mode' => $release->release_mode,
            'funding_source' => $release->funding_source,
            'scheduled_for' => $release->scheduled_for?->toDateString(),
            'release_location' => $release->release_location,
            'status' => $release->status->value,
            'released_at' => $release->released_at?->toIso8601ZuluString(),
            'acknowledged_by_name' => $release->acknowledged_by_name,
            'acknowledged_relationship' => $release->acknowledged_relationship,
            'acknowledgement_method' => $release->acknowledgement_method,
            'outcome_reason' => $release->outcome_reason,
            'available_transitions' => array_map(
                static fn (ReleaseStatus $status): string => $status->value,
                $release->status->allowedNext(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchProjection(ReleaseBatch $batch): array
    {
        return [
            'id' => $batch->uuid,
            'reference_number' => $batch->reference_number,
            'name' => $batch->name,
            'scheduled_for' => $batch->scheduled_for?->toDateString(),
            'location' => $batch->location,
            'status' => $batch->status,
        ];
    }

    private function releaseOrFail(ActorContext $actor, string $uuid): Release
    {
        /** @var Release|null $release */
        $release = Release::query()->where('uuid', $uuid)->first();

        if ($release === null) {
            throw ResourceNotFoundException::make('That release was not found.');
        }

        $resident = $this->residents->summaryFor((string) $release->resident_id);

        // Out of scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay($actor, $resident?->barangayId, 'That release was not found.');

        return $release;
    }

    private function batchOrFail(string $uuid): ReleaseBatch
    {
        /** @var ReleaseBatch|null $batch */
        $batch = ReleaseBatch::query()->where('uuid', $uuid)->first();

        if ($batch === null) {
            throw ResourceNotFoundException::make('That distribution run was not found.');
        }

        return $batch;
    }

    private function caseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $case->barangay_id, 'That case was not found.');

        return $case;
    }
}
