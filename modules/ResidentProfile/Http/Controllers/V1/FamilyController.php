<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\FamilyDirectory;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The family read side (TAB 07).
 *
 * Writes already live on {@see HouseholdController} — `POST admin/households/{household}/families`
 * and the member and head routes under `admin/families/{family}`. This controller adds only the
 * reads that were missing, on the same aggregate, under the same permission.
 *
 * ── READS ARE `resident.view`, DELIBERATELY ──────────────────────────────────────────
 *
 * The same reasoning `HouseholdController` records: a family is a group of residents, and opening
 * one reveals their data. A separate "family viewer" permission would be a way to enumerate
 * residents without holding the permission that guards them — the control would look tighter and
 * be looser.
 *
 * ── SCOPE IS ENFORCED THROUGH THE HOUSEHOLD ──────────────────────────────────────────
 *
 * A family has no barangay of its own; it takes one from the household it sits in. So every
 * lookup resolves the family's household first and asks the same
 * {@see AuthorizationService::scopeToBarangays()} the household routes ask, which is why a
 * barangay-scoped caller cannot reach a family in a barangay they do not hold by switching from
 * the household URL to the family one.
 *
 * A family with **no** household — real, and the reason `households.id` is nullable on the
 * console side (`DL-47`) — is visible only to a caller with no barangay restriction. A record
 * nobody's scope covers must not fall through to everybody.
 */
final class FamilyController
{
    public function __construct(
        private readonly FamilyDirectory $families,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $pagination = PaginationParams::fromRequest($request);

        $query = $this->scoped($actor);

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.strtolower(trim($search)).'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->whereRaw('lower(code) like ?', [$term])
                    ->orWhereRaw('lower(label) like ?', [$term]);
            });
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, ['active', 'dissolved', 'archived'], true)) {
            $query->where('status', $status);
        }

        /*
         * A family between addresses. The console asks for this as `unhousedOnly`, and it is the
         * screen an office uses to find the families it has lost track of — which is exactly the
         * population most likely to be missed by a distribution.
         */
        if ($request->boolean('unhoused')) {
            $query->whereNull('household_id');
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('code')->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Family $family): array => $this->families->summary($family),
        );
    }

    public function show(Request $request, ActorContext $actor, string $family): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        return ApiResponse::item($this->families->detail($this->familyOrFail($actor, $family)));
    }

    /** Every family a resident currently belongs to. Plural — people overlap. */
    public function forResident(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        return ApiResponse::page(Page::fromArray(
            $this->families->familiesOf($this->residentOrFail($actor, $resident)),
            PaginationParams::fromRequest($request),
        ));
    }

    /**
     * The append-only kinship history for one resident, newest first.
     *
     * Paginated like every other collection (Article 4). It is tempting to argue that one person's
     * history is small enough to send whole, and the argument is wrong twice over: the bound is a
     * guess about the busiest record rather than a limit, and an endpoint that is unbounded *in
     * practice* is unbounded. `Page::fromArray` is the sanctioned path for a collection assembled
     * in memory from more than one table.
     */
    public function kinshipHistory(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        return ApiResponse::page(Page::fromArray(
            $this->families->kinshipHistory($this->residentOrFail($actor, $resident)),
            PaginationParams::fromRequest($request),
        ));
    }

    /**
     * @return Builder<Family>
     */
    private function scoped(ActorContext $actor): Builder
    {
        $query = $this->families->query();

        if ($actor->scope->isUnrestricted()) {
            return $query;
        }

        if ($actor->scope->isNone() || $actor->scope->barangayIds === []) {
            // Deny by default, matching AuthorizationService::scopeToBarangays: an unscoped actor
            // lists nothing rather than everything.
            return $query->whereRaw('1 = 0');
        }

        /*
         * A family has no barangay of its own, so the scope is applied through the household it
         * sits in — as a subquery on identifiers, which keeps this one round trip and keeps the
         * family query a family query. Households are the same module, so this is not a
         * cross-module join.
         *
         * `whereIn` on `household_id` also excludes families with NO household from a
         * barangay-scoped caller, which is the intended answer: a record nobody's scope covers
         * must not fall through to everybody.
         */
        return $query->whereIn(
            'household_id',
            Household::query()->whereIn('barangay_id', $actor->scope->barangayIds)->select('id'),
        );
    }

    private function familyOrFail(ActorContext $actor, string $uuid): Family
    {
        $family = $this->scoped($actor)->where('uuid', $uuid)->first();

        if ($family === null) {
            // The same refusal whether it does not exist or is outside the caller's barangay.
            // Two different messages would confirm the existence of a record they may not read.
            throw ResourceNotFoundException::make('That family was not found.');
        }

        return $family;
    }

    private function residentOrFail(ActorContext $actor, string $uuid): Resident
    {
        $resident = $this->authorization
            ->scopeToBarangays($actor, Resident::query())
            ->where('uuid', $uuid)
            ->first();

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        return $resident;
    }
}
