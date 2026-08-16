<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\SafeguardingRegistry;
use Modules\Welfare\Infrastructure\Eloquent\SafeguardingConcern;

/**
 * Safeguarding concerns (ADR 0022 §4).
 *
 * THE NARROWEST SURFACE IN THIS SYSTEM, and the only one with **no list endpoint at all**.
 *
 * There is deliberately no `GET /admin/safeguarding-concerns`. A queue of safeguarding concerns
 * is a list of families under suspicion, and once it exists it will be filtered, sorted, exported
 * and eventually joined to something. Every read here is scoped to one named resident somebody
 * already had reason to open — which is what makes each read a decision rather than a browse.
 *
 * The acceptance criterion — *safeguarding detail is not returned to generic list endpoints* — is
 * therefore held by construction rather than by remembering to strip a field.
 */
final class SafeguardingController
{
    public function __construct(
        private readonly SafeguardingRegistry $safeguarding,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The full record for one named resident.
     */
    public function forResident(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::SafeguardingView);

        $summary = $this->residents->summaryFor($resident);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $summary->barangayId, 'That resident was not found.');

        return ApiResponse::item([
            'concerns' => $this->safeguarding->detailFor($summary->id)
                ->map(fn (SafeguardingConcern $concern): array => $this->projection($concern))->all(),
        ]);
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::SafeguardingManage);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'case_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'category' => ['required', 'string', 'max:48'],
            'detail' => ['required', 'string', 'max:5000'],
            // Optional and separate: not every concern means the household is a risk to a
            // visiting worker, and conflating the two would send an advisory about a family
            // being protected rather than a family to be careful of.
            'worker_safety_advisory' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $summary = $this->residents->summaryFor($validated['resident_id']);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $summary->barangayId, 'That resident was not found.');

        $concern = $this->safeguarding->raise([
            'resident_id' => $summary->id,
            'category' => $validated['category'],
            'detail' => $validated['detail'],
            'worker_safety_advisory' => $validated['worker_safety_advisory'] ?? null,
        ], $actor);

        return ApiResponse::created($this->projection($concern));
    }

    public function close(Request $request, ActorContext $actor, string $concern): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::SafeguardingManage);

        /** @var SafeguardingConcern|null $model */
        $model = SafeguardingConcern::query()->where('uuid', $concern)->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That concern was not found.');
        }

        $summary = $this->residents->summaryFor((string) $model->resident_id);
        $this->authorization->authorizeBarangay($actor, $summary?->barangayId, 'That concern was not found.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->projection(
            $this->safeguarding->close($model, $validated['reason'], $actor),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(SafeguardingConcern $concern): array
    {
        return [
            'id' => $concern->uuid,
            'category' => $concern->category,
            'status' => $concern->status,
            'detail' => $concern->detail,
            'worker_safety_advisory' => $concern->worker_safety_advisory,
            'raised_at' => $concern->raised_at?->toIso8601ZuluString(),
            'closed_at' => $concern->closed_at?->toIso8601ZuluString(),
            'closure_reason' => $concern->closure_reason,
        ];
    }
}
