<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\RelationshipService;
use Modules\ResidentProfile\Contracts\RelationshipType;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentRelationship;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Kinship between residents (ADR 0014 §4).
 *
 * The listing returns both stored rows and derived inverses, each flagged. A client that
 * wants to end a relationship must act on the stored direction — which is why `derived` is in
 * the payload rather than left for the client to infer.
 */
final class RelationshipController
{
    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $person = $this->residentOrFail($actor, $resident);

        $includeEnded = $request->boolean('include_ended');

        return ApiResponse::item([
            'relationships' => array_map(
                // `related_resident_pk` is an internal primary key used by the service to
                // resolve care responsibility. It must not cross the API boundary —
                // conventions §6 forbids exposing auto-increment ids.
                static fn (array $row): array => array_diff_key($row, ['related_resident_pk' => null]),
                $this->relationships->forResident($person, ! $includeEnded),
            ),
        ]);
    }

    public function store(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $person = $this->residentOrFail($actor, $resident);

        $validated = $request->validate([
            'related_resident_id' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:'.implode(',', RelationshipType::values())],
            'note' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $related = $this->residentOrFail($actor, $validated['related_resident_id']);

        $relationship = $this->relationships->relate(
            $person,
            $related,
            RelationshipType::from($validated['type']),
            $actor,
            $validated['note'] ?? null,
            $validated['effective_from'] ?? null,
        );

        return ApiResponse::created($this->projection($relationship));
    }

    /**
     * Ends a relationship. Never deletes it — a separation and "this never happened" are
     * different claims, and only one of them is true.
     */
    public function destroy(Request $request, ActorContext $actor, string $resident, string $relationship): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $person = $this->residentOrFail($actor, $resident);

        /** @var ResidentRelationship|null $row */
        $row = ResidentRelationship::query()
            ->where('uuid', $relationship)
            // Reachable from either end: staff open the relationship from whichever
            // resident's screen they happen to be on, and which side stored the row is an
            // implementation detail they should not have to know.
            ->where(function ($query) use ($person): void {
                $query->where('resident_id', $person->id)->orWhere('related_resident_id', $person->id);
            })
            ->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That relationship was not found.');
        }

        $validated = $request->validate([
            'end_reason' => ['required', 'string', 'max:48'],
        ]);

        return ApiResponse::item(
            $this->projection($this->relationships->end($row, $actor, $validated['end_reason'])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(ResidentRelationship $relationship): array
    {
        return [
            'id' => $relationship->uuid,
            'type' => $relationship->type->value,
            'derived' => false,
            'resident_id' => Resident::query()->where('id', $relationship->resident_id)->value('uuid'),
            'related_resident_id' => Resident::query()->where('id', $relationship->related_resident_id)->value('uuid'),
            'effective_from' => $relationship->effective_from?->toDateString(),
            'effective_to' => $relationship->effective_to?->toDateString(),
            'end_reason' => $relationship->end_reason,
            'note' => $relationship->note,
        ];
    }

    private function residentOrFail(ActorContext $actor, string $uuid): Resident
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $uuid)->first();

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay(
            $actor,
            $resident->barangay_id === null ? null : (int) $resident->barangay_id,
            'That resident was not found.',
        );

        return $resident;
    }
}
