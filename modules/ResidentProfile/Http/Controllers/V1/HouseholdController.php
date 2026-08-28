<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\HouseholdMembershipService;
use Modules\ResidentProfile\Application\HouseholdRegistry;
use Modules\ResidentProfile\Application\ResidentProfileAudit;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\BarangayCodes;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The staff-facing household and family registry (ADR 0014).
 *
 * Reads require `resident.view`, writes require `household.manage`. A household is a group of
 * residents and opening it reveals their data, so read access is deliberately not separable
 * from resident read access — a "household viewer" permission would be a way to enumerate
 * residents without holding the permission that guards them.
 *
 * Every route resolves the household through {@see householdOrFail()}, which enforces the
 * caller's barangay scope, so there is no verb a caller can switch to in order to reach a
 * household their scope excludes.
 */
final class HouseholdController
{
    public function __construct(
        private readonly HouseholdRegistry $households,
        private readonly HouseholdMembershipService $memberships,
        private readonly AuthorizationService $authorization,
        private readonly ResidentProfileAudit $audit,
        private readonly BarangayCodes $barangayCodes,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $pagination = PaginationParams::fromRequest($request);

        $query = $this->authorization->scopeToBarangays($actor, $this->households->query());

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.strtolower(trim($search)).'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->whereRaw('lower(code) like ?', [$term])
                    ->orWhereRaw('lower(street_address) like ?', [$term]);
            });
        }

        $barangayId = $request->query('barangay_id');

        if (is_numeric($barangayId)) {
            $query->where('barangay_id', (int) $barangayId);
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, ['active', 'dissolved', 'archived'], true)) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Household $household): array => $this->listProjection($household),
        );
    }

    public function show(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $model = $this->householdOrFail($actor, $household);

        // The member list is other people's personal data. Opening it is an audited read,
        // exactly as opening one of their records would be (Article 5.4).
        $this->audit->recordResidentRead($actor->subjectId, (string) $model->uuid);

        return ApiResponse::item($this->detailProjection($model));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $validated = $request->validate($this->householdRules() + [
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'street_address' => ['required', 'string', 'max:191'],
        ]);

        // A clerk must not enrol a household into a barangay they cannot serve — that is how
        // a record lands where its own office cannot see it.
        $this->authorization->authorizeBarangay($actor, (int) $validated['barangay_id'], 'That barangay was not found.');

        return ApiResponse::created(
            $this->detailProjection($this->households->create($validated, $actor)),
        );
    }

    public function update(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate($this->householdRules() + [
            'barangay_id' => ['sometimes', 'integer', 'exists:barangays,id'],
            'street_address' => ['sometimes', 'string', 'max:191'],
        ]);

        if (array_key_exists('barangay_id', $validated)) {
            // Moving a household out of the caller's scope would be a one-way trip: they
            // could not reopen it afterwards to undo the mistake.
            $this->authorization->authorizeBarangay($actor, (int) $validated['barangay_id'], 'That barangay was not found.');
        }

        return ApiResponse::item(
            $this->detailProjection($this->households->update($model, $validated, $actor)),
        );
    }

    public function changeHead(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'resident_id' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        $head = $validated['resident_id'] === null
            ? null
            : $this->residentOrFail($actor, (string) $validated['resident_id']);

        return ApiResponse::item(
            $this->detailProjection($this->households->changeHead($model, $head, $actor)),
        );
    }

    public function changeVerification(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:unverified,field-verified,rejected'],
        ]);

        return ApiResponse::item(
            $this->detailProjection($this->households->changeVerification($model, $validated['verification_status'], $actor)),
        );
    }

    public function changeStatus(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,dissolved,archived'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::item(
            $this->detailProjection($this->households->changeStatus($model, $validated['status'], $validated['reason'], $actor)),
        );
    }

    // ── membership ────────────────────────────────────────────────────────────────────

    public function addMember(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $resident = $this->residentOrFail($actor, $validated['resident_id']);

        $membership = $this->memberships->addMember(
            $model,
            $resident,
            $actor,
            $validated['effective_from'] ?? null,
        );

        return ApiResponse::created($this->membershipProjection($membership));
    }

    public function removeMember(Request $request, ActorContext $actor, string $household, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);
        $person = $this->residentOrFail($actor, $resident);

        $validated = $request->validate([
            'end_reason' => ['required', 'string', 'max:48'],
            'effective_to' => ['sometimes', 'date'],
        ]);

        $membership = $this->memberships->removeMember(
            $model,
            $person,
            $actor,
            $validated['end_reason'],
            $validated['effective_to'] ?? null,
        );

        return ApiResponse::item($this->membershipProjection($membership));
    }

    /**
     * Moves a resident into this household from wherever they are now.
     *
     * One call, one transaction. Exposing "remove" and "add" as the only route would let a
     * client perform half a transfer and leave a real person belonging to no household —
     * invisible to every household-based distribution until somebody noticed.
     */
    public function transferMember(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:48'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $person = $this->residentOrFail($actor, $validated['resident_id']);

        $membership = $this->memberships->transfer(
            $model,
            $person,
            $actor,
            $validated['reason'],
            $validated['effective_from'] ?? null,
        );

        return ApiResponse::item($this->membershipProjection($membership));
    }

    /**
     * A resident's full residence history.
     *
     * The acceptance criterion behind this endpoint: moving somebody must not erase where
     * they lived before.
     */
    public function memberHistory(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $person = $this->residentOrFail($actor, $resident);

        $this->audit->recordResidentRead($actor->subjectId, (string) $person->uuid);

        return ApiResponse::item([
            'history' => $this->memberships->historyFor($person)
                ->map(fn (HouseholdMembership $row): array => $this->membershipProjection($row))
                ->all(),
        ]);
    }

    // ── families ──────────────────────────────────────────────────────────────────────

    public function storeFamily(Request $request, ActorContext $actor, string $household): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->householdOrFail($actor, $household);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:96'],
        ]);

        return ApiResponse::created(
            $this->familyProjection($this->households->createFamily($model, $validated, $actor)),
        );
    }

    public function addFamilyMember(Request $request, ActorContext $actor, string $family): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->familyOrFail($actor, $family);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $person = $this->residentOrFail($actor, $validated['resident_id']);

        $this->memberships->addToFamily($model, $person, $actor, $validated['effective_from'] ?? null);

        return ApiResponse::created($this->familyProjection($model->refresh()));
    }

    public function removeFamilyMember(Request $request, ActorContext $actor, string $family, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->familyOrFail($actor, $family);
        $person = $this->residentOrFail($actor, $resident);

        $validated = $request->validate([
            'end_reason' => ['required', 'string', 'max:48'],
        ]);

        $this->memberships->removeFromFamily($model, $person, $actor, $validated['end_reason']);

        return ApiResponse::item($this->familyProjection($model->refresh()));
    }

    public function changeFamilyHead(Request $request, ActorContext $actor, string $family): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::HouseholdManage);

        $model = $this->familyOrFail($actor, $family);

        $validated = $request->validate([
            'resident_id' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        $head = $validated['resident_id'] === null
            ? null
            : $this->residentOrFail($actor, (string) $validated['resident_id']);

        return ApiResponse::item(
            $this->familyProjection($this->households->changeFamilyHead($model, $head, $actor)),
        );
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function listProjection(Household $household): array
    {
        return [
            'id' => $household->uuid,
            'code' => $household->code,
            'barangay_id' => $household->barangay_id,
            'barangay_code' => $this->barangayCodes->codeFor($household->barangay_id),
            'street_address' => $household->street_address,
            'purok_or_sitio' => $household->purok_or_sitio,
            // Derived from open memberships every time. There is no stored count to drift.
            'member_count' => $household->currentMemberCount(),
            'verification_status' => $household->verification_status,
            'status' => $household->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailProjection(Household $household): array
    {
        return $this->listProjection($household) + [
            'dwelling_type' => $household->dwelling_type,
            'tenure_status' => $household->tenure_status,
            'electricity_source' => $household->electricity_source,
            'water_source' => $household->water_source,
            'toilet_facility' => $household->toilet_facility,
            'profile_completeness' => $household->profile_completeness,
            'status_reason' => $household->status_reason,
            'verified_at' => $household->verified_at?->toIso8601ZuluString(),
            'head' => $this->residentBrief($household->head_resident_id),
            'members' => $this->memberships->currentMembers($household)
                ->map(fn (HouseholdMembership $row): array => [
                    'membership_id' => $row->uuid,
                    'resident' => $this->residentBrief($row->resident_id),
                    'effective_from' => $row->effective_from?->toDateString(),
                ])->all(),
            'families' => Family::query()
                ->where('household_id', $household->id)
                ->get()
                ->map(fn (Family $family): array => $this->familyProjection($family))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function familyProjection(Family $family): array
    {
        return [
            'id' => $family->uuid,
            'code' => $family->code,
            'label' => $family->label,
            'household_id' => Household::query()->where('id', $family->household_id)->value('uuid'),
            'head' => $this->residentBrief($family->head_resident_id),
            'member_count' => $family->currentMemberCount(),
            'verification_status' => $family->verification_status,
            'status' => $family->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipProjection(HouseholdMembership $membership): array
    {
        return [
            'id' => $membership->uuid,
            'household_id' => Household::query()->where('id', $membership->household_id)->value('uuid'),
            'resident' => $this->residentBrief($membership->resident_id),
            'effective_from' => $membership->effective_from?->toDateString(),
            'effective_to' => $membership->effective_to?->toDateString(),
            'end_reason' => $membership->end_reason,
        ];
    }

    /**
     * Enough of a resident to identify them in a household listing.
     *
     * Name, barangay and verification tier — not income, sectors or case history. A clerk
     * looking at who lives at an address has no business reading each member's welfare file
     * as a side effect (Article 5.2).
     *
     * @return array<string, mixed>|null
     */
    private function residentBrief(mixed $residentId): ?array
    {
        if ($residentId === null) {
            return null;
        }

        /** @var Resident|null $resident */
        $resident = Resident::query()->find($residentId);

        return $resident === null ? null : [
            'id' => $resident->uuid,
            'name' => $resident->fullName(),
            'birth_date' => $resident->birth_date?->toDateString(),
            'verification_tier' => $resident->verification_tier->value,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function householdRules(): array
    {
        return [
            'purok_or_sitio' => ['sometimes', 'nullable', 'string', 'max:96'],
            'dwelling_type' => ['sometimes', 'string', 'in:concrete,semi-concrete,light-materials,makeshift,institutional,other'],
            'tenure_status' => ['sometimes', 'string', 'in:owner,renter,sharer,caretaker,informal-settler,other'],
            'electricity_source' => ['sometimes', 'nullable', 'string', 'max:48'],
            'water_source' => ['sometimes', 'nullable', 'string', 'max:48'],
            'toilet_facility' => ['sometimes', 'nullable', 'string', 'max:48'],
        ];
    }

    private function householdOrFail(ActorContext $actor, string $uuid): Household
    {
        /** @var Household|null $household */
        $household = Household::query()->where('uuid', $uuid)->first();

        if ($household === null) {
            throw ResourceNotFoundException::make('That household was not found.');
        }

        // Out-of-scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay(
            $actor,
            $household->barangay_id === null ? null : (int) $household->barangay_id,
            'That household was not found.',
        );

        return $household;
    }

    private function familyOrFail(ActorContext $actor, string $uuid): Family
    {
        /** @var Family|null $family */
        $family = Family::query()->where('uuid', $uuid)->first();

        if ($family === null) {
            throw ResourceNotFoundException::make('That family was not found.');
        }

        /** @var Household|null $household */
        $household = Household::query()->find($family->household_id);

        $this->authorization->authorizeBarangay(
            $actor,
            $household?->barangay_id === null ? null : (int) $household->barangay_id,
            'That family was not found.',
        );

        return $family;
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
