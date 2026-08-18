<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\BeneficiaryProjection;

/**
 * The beneficiary registry (TAB 07), closing three no-counterpart rows.
 *
 * **A projection over the resident registry, keyed on the resident.** There is no beneficiary
 * table and no beneficiary identifier — see {@see BeneficiaryProjection} for why that is a
 * correctness rule rather than a modelling preference.
 *
 * ── `program.view`, NOT `resident.view` ──────────────────────────────────────────────
 *
 * This is the one read in the module that is **not** guarded by `resident.view`, and the reason is
 * what the rows say rather than who they are about. A resident row says a person exists here. A
 * beneficiary row says *this person has received public money, is on a programme roll, and has an
 * open request* — the office's dealings with them, which is a different disclosure and the one
 * `program.view` already guards for programme rolls.
 *
 * Guarding it as an ordinary resident read would have made every holder of the registry a reader
 * of everybody's assistance history, which is exactly the widening this integration keeps
 * catching elsewhere.
 */
final class BeneficiaryController
{
    public function __construct(
        private readonly BeneficiaryProjection $beneficiaries,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramView);

        $pagination = PaginationParams::fromRequest($request);

        $barangayId = $request->query('barangay_id');

        if (is_numeric($barangayId)) {
            $this->authorization->authorizeBarangay($actor, (int) $barangayId, 'That barangay was not found.');
        }

        $term = $request->query('q');

        $result = $this->beneficiaries->page(
            is_string($term) ? $term : null,
            $this->scopeFor($actor, is_numeric($barangayId) ? (int) $barangayId : null),
            $pagination->page,
            $pagination->perPage,
        );

        return ApiResponse::page(new Page($result['rows'], $result['total'], $pagination));
    }

    /**
     * The barangays this caller may read, narrowed further by an explicit filter.
     *
     * `null` means unrestricted and `[]` means nothing — deny-by-default rather than a filter that
     * quietly disappears, which is the same contract `AuthorizationService::scopeToBarangays()`
     * honours for queries this module is allowed to build itself.
     *
     * @return list<int>|null
     */
    private function scopeFor(ActorContext $actor, ?int $requested): ?array
    {
        $allowed = $actor->scope->isUnrestricted() ? null : $actor->scope->barangayIds;

        if ($requested === null) {
            return $allowed;
        }

        // The filter can only ever narrow. authorizeBarangay() above already refused a barangay
        // outside the caller's scope, so this is belt and braces rather than the control itself.
        return $allowed === null ? [$requested] : array_values(array_intersect($allowed, [$requested]));
    }

    public function show(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramView);

        return ApiResponse::item($this->beneficiaries->summarise([$this->residentOrFail($actor, $resident)])[0]);
    }

    private function residentOrFail(ActorContext $actor, string $uuid): ResidentSummary
    {
        $resident = $this->residents->summariesFor([$uuid])[$uuid] ?? null;

        // Scope is checked here rather than in the query, because this module asks the resident
        // registry for a summary and never builds a query against it.
        if ($resident !== null && ! $actor->scope->isUnrestricted()
            && ! in_array($resident->barangayId, $actor->scope->barangayIds, true)) {
            $resident = null;
        }

        if ($resident === null) {
            // Identical refusal for "does not exist" and "not yours". Two messages would confirm
            // the record is real, which is itself a disclosure about somebody.
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        return $resident;
    }
}
