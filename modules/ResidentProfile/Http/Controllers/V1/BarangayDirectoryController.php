<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Http\ApiResponse;
use Modules\Shared\Http\Page;
use Modules\Shared\Http\PaginationParams;

/**
 * The municipality's barangays, for a resident filling in an address.
 *
 * PUBLIC, AND IT HAS TO BE — an affirmative choice, recorded here and in the
 * route file per Article 3.5. `POST me/kyc` requires a barangay and nothing
 * published the list, so an applicant was asked for an identifier they had no
 * way to obtain. That made the whole verification path unreachable from a
 * client, and with it the Verified tier, the digital ID and every service
 * resting on them. Requiring an account would not help: the first thing
 * onboarding asks for is an address.
 *
 * The list is public information. Barangay names and codes are printed on
 * municipal signage; there is nothing here about anybody, so there is nothing to
 * authorize.
 *
 * IDENTIFIED BY UUID AND CODE, NEVER BY THE AUTO-INCREMENT KEY. Article 4 and
 * `conventions.md` §6 both require it, and the evidence ledger already records
 * L-15, where `barangay_id` leaked as a raw `2` on residents and households.
 * Publishing the integer here to satisfy an existing validator would entrench
 * that defect in a brand-new endpoint. So this publishes `id` (the UUID) and
 * `code` (the stable slug).
 *
 * PAGINATED, because Article 4 says collections always are. An earlier draft of
 * this returned the whole list unpaginated on the reasoning that a fixed
 * reference list is not a growing collection and an address field should not
 * cost two round trips. That reasoning is not wrong, and it is not mine to act
 * on: the rule is stated without exception, and a client that wants the whole
 * list in one call can ask for `per_page=100`, which is comfortably above the
 * number of barangays in Taytay. If a genuine exception is wanted it belongs in
 * an ADR, not in a controller.
 */
final class BarangayDirectoryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $pagination = PaginationParams::fromRequest($request);

        $query = DB::table('barangays')->orderBy('name');

        $total = (clone $query)->count();
        $rows = $query
            ->forPage($pagination->page, $pagination->perPage)
            ->get(['uuid', 'code', 'name', 'psgc_code']);

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            static fn (object $row): array => [
                'id' => (string) $row->uuid,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                /*
                 * The national statistical code, when the municipality has
                 * recorded one. Published because a resident's barangay is the
                 * part of their address other agencies most often ask them to
                 * quote.
                 */
                'psgc_code' => $row->psgc_code === null ? null : (string) $row->psgc_code,
            ],
        );
    }
}
