<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentDuplicatePair;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Duplicate findings already recorded about one resident (TAB 07).
 *
 * Stays in this module because the records are here. The beneficiary registry moved to `Welfare` —
 * a standing is a welfare fact — but who was judged the same person as whom is identity
 * administration, and identity belongs to the registry.
 */
final class ResidentDuplicateFindingController
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * Findings already recorded about one record, newest first.
     *
     * ── WHAT A FINDING CARRIES, AND WHAT IT REFUSES TO ───────────────────────────────
     *
     * The rule that matched, the resemblance band, the verdict, who decided and why. **No field
     * values from either record.** `DL-73` is the console's statement of the same rule: a review
     * reports *agreement between fields*, never the values, so a queue can be worked without
     * disclosing one person's details to somebody who came to look at another's.
     *
     * Behind `resident.merge` rather than `program.view`, because reading who was judged the same
     * person as whom is identity administration, not registry browsing.
     */
    public function index(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $model = $this->residentOrFail($actor, $resident);

        $pairs = ResidentDuplicatePair::query()
            ->where('decision', '!=', 'undecided')
            ->where(function ($query) use ($model): void {
                $query->where('lower_resident_id', $model->id)->orWhere('higher_resident_id', $model->id);
            })
            ->orderByDesc('decided_at')
            ->get();

        $others = Resident::query()
            ->whereIn('id', $pairs->flatMap(fn (ResidentDuplicatePair $p): array => [
                $p->lower_resident_id,
                $p->higher_resident_id,
            ])->unique()->all())
            ->pluck('uuid', 'id')
            ->all();

        $findings = $pairs->map(function (ResidentDuplicatePair $pair) use ($model, $others): array {
            $otherId = (int) $pair->lower_resident_id === (int) $model->id
                ? (int) $pair->higher_resident_id
                : (int) $pair->lower_resident_id;

            return [
                'id' => $pair->uuid,
                'other_resident_id' => $others[$otherId] ?? null,
                // The rule and the band. Never the values that matched — see the docblock.
                'rule' => $pair->rule,
                'confidence' => $pair->confidence,
                'decision' => $pair->decision,
                'decided_by' => $pair->decided_by,
                'decided_at' => $pair->decided_at?->toIso8601ZuluString(),
                // Required at the point of decision, so this is never null on a decided pair.
                // An unexplained finding is indistinguishable afterwards from an arbitrary one.
                'reason' => $pair->decision_note,
            ];
        })->all();

        return ApiResponse::page(Page::fromArray($findings, PaginationParams::fromRequest($request)));
    }

    private function residentOrFail(ActorContext $actor, string $uuid): Resident
    {
        $resident = $this->authorization
            ->scopeToBarangays($actor, Resident::query())
            ->where('uuid', $uuid)
            ->first();

        if ($resident === null) {
            // Identical refusal for "does not exist" and "not yours". Two messages would confirm
            // the record is real, which is itself a disclosure about somebody.
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        return $resident;
    }
}
