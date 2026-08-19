<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentMergeService;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentDuplicatePair;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMerge;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Duplicate resident review and merge (ADR 0013 §3).
 *
 * The workflow is deliberately four separate calls — detect, read the pair, preview,
 * merge — rather than one convenient "resolve duplicates" button. Each step is a place a
 * reviewer can stop, and the last one is irreversible in practice: it collapses two people
 * into one row and repoints their accounts, credentials and history.
 *
 * SCOPE IS ENFORCED ON BOTH SIDES OF EVERY PAIR. A clerk scoped to one barangay must not be
 * able to merge a record they can see into one they cannot — that would let them move a
 * resident out of their own reach, or quietly rewrite a record in a barangay they were
 * never granted (ADR 0012).
 */
final class ResidentDuplicateController
{
    public function __construct(
        private readonly ResidentMergeService $merges,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The review queue.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $pagination = PaginationParams::fromRequest($request);

        $query = ResidentDuplicatePair::query()->orderBy('decision')->orderByDesc('id');

        $decision = $request->query('decision');

        /*
         * A QUEUE SHOWS WHAT IS STILL OWED. Undecided by default (TAB 17, journey 4).
         *
         * This listed every pair, decided ones included, so a reviewer who had settled a pair was
         * asked about it again on the next visit — and re-running detection put it back in front of
         * them a third time. `DL-74` is explicit that recording `different-person` exists *"so the
         * pair stops resurfacing"*, and a reviewer asked the same question repeatedly learns to
         * dismiss without reading, which is the behaviour the whole review exists to prevent.
         *
         * The decided pairs are not hidden — `?decision=same-person`, `?decision=different-person`
         * and `?decision=all` all still reach them, and `admin/residents/{resident}/duplicate-findings`
         * is the per-record history. What changed is only what a caller gets when it asks for the
         * queue without saying which part.
         */
        if ($decision === 'all') {
            // Everything, for somebody reviewing the review.
        } elseif (is_string($decision) && in_array($decision, ['undecided', 'same-person', 'different-person'], true)) {
            $query->where('decision', $decision);
        } else {
            $query->where('decision', 'undecided');
        }

        $total = (clone $query)->count();
        $pairs = $query->forPage($pagination->page, $pagination->perPage)->get();

        /*
         * Scope is applied after fetching here, unusually — and only because a pair spans
         * two residents, so it cannot be expressed as one indexed column comparison. The
         * page is bounded to at most 100 rows by PaginationParams, so this reads a bounded
         * set rather than the table. The listed total is the unfiltered count and is
         * labelled as such below.
         */
        $visible = $pairs
            ->map(fn (ResidentDuplicatePair $pair): ?array => $this->pairProjection($actor, $pair))
            ->filter()
            ->values()
            ->all();

        return ApiResponse::page(
            new Page($visible, $total, $pagination),
            null,
            ['note' => 'Pairs outside your barangay scope are omitted from this page.'],
        );
    }

    /**
     * Runs deterministic duplicate detection across the registry.
     *
     * Idempotent — re-running does not put the same question in front of a reviewer twice,
     * and decisions already recorded are preserved.
     */
    public function detect(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $barangayId = $request->query('barangay_id');
        $barangayId = is_numeric($barangayId) ? (int) $barangayId : null;

        if ($barangayId !== null) {
            $this->authorization->authorizeBarangay($actor, $barangayId, 'That barangay was not found.');
        }

        $pairs = $this->merges->detectDuplicates($barangayId);

        return ApiResponse::item([
            'pairs_found' => $pairs->count(),
            'undecided' => ResidentDuplicatePair::query()->where('decision', 'undecided')->count(),
        ]);
    }

    /**
     * A reviewer rules on a pair. This does NOT merge anything.
     */
    public function decide(Request $request, ActorContext $actor, string $pair): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:same-person,different-person'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->pairOrFail($actor, $pair);

        $decided = $this->merges->decide($model, $validated['decision'], $actor, $validated['note'] ?? null);

        return ApiResponse::item($this->pairProjection($actor, $decided));
    }

    /**
     * What a merge would do, field by field, without doing it.
     *
     * The survivor is chosen by the caller, because it is a judgement: usually the verified
     * record, sometimes the one with the live credential, occasionally neither.
     */
    public function preview(Request $request, ActorContext $actor, string $pair): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $model = $this->pairOrFail($actor, $pair);

        $validated = $request->validate([
            'survivor_resident_id' => ['required', 'string', 'max:64'],
        ]);

        [$survivor, $absorbed] = $this->resolveSides($model, $validated['survivor_resident_id']);

        return ApiResponse::item($this->merges->preview($survivor, $absorbed));
    }

    /**
     * Executes the merge. One transaction, or nothing.
     */
    public function merge(Request $request, ActorContext $actor, string $pair): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentMerge);

        $validated = $request->validate([
            'survivor_resident_id' => ['required', 'string', 'max:64'],
            // Always required. A merge with no recorded reason is indistinguishable after
            // the fact from an unauthorised one.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $model = $this->pairOrFail($actor, $pair);

        [$survivor, $absorbed] = $this->resolveSides($model, $validated['survivor_resident_id']);

        $merge = $this->merges->merge($survivor, $absorbed, $actor, $validated['reason'], $model);

        return ApiResponse::item($this->mergeProjection($merge, $survivor, $absorbed));
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────

    /**
     * Splits a pair into survivor and absorbed, refusing anything that is not one of its
     * two members.
     *
     * Accepting an arbitrary resident id here would make the pair review decorative: a
     * caller could pass a confirmed pair and then merge two completely different records.
     *
     * @return array{Resident, Resident}
     */
    private function resolveSides(ResidentDuplicatePair $pair, string $survivorUuid): array
    {
        /*
         * `withTrashed()` matters here. After a merge the absorbed row is soft-deleted, so
         * a default query stops finding it and a retried merge would 404 — an answer that
         * says "no such record" when the truth is "already merged". Loading both sides
         * regardless lets the merge service reach its own state check and reply 409 with
         * the real reason.
         */
        /** @var Resident $lower */
        $lower = Resident::withTrashed()->findOrFail($pair->lower_resident_id);
        /** @var Resident $higher */
        $higher = Resident::withTrashed()->findOrFail($pair->higher_resident_id);

        if ((string) $lower->uuid === $survivorUuid) {
            return [$lower, $higher];
        }

        if ((string) $higher->uuid === $survivorUuid) {
            return [$higher, $lower];
        }

        throw new ApiException(
            ErrorCode::BadRequest,
            'The surviving record must be one of the two records in this pair.',
        );
    }

    /**
     * Loads a pair and enforces scope on BOTH residents.
     */
    private function pairOrFail(ActorContext $actor, string $uuid): ResidentDuplicatePair
    {
        /** @var ResidentDuplicatePair|null $pair */
        $pair = ResidentDuplicatePair::query()->where('uuid', $uuid)->first();

        if ($pair === null) {
            throw ResourceNotFoundException::make('That duplicate pair was not found.');
        }

        foreach ([$pair->lower_resident_id, $pair->higher_resident_id] as $residentId) {
            // withTrashed: an already-merged pair must still resolve, so the caller is told
            // it was merged rather than that it never existed.
            /** @var Resident|null $resident */
            $resident = Resident::withTrashed()->find($residentId);

            $this->authorization->authorizeBarangay(
                $actor,
                $resident?->barangay_id === null ? null : (int) $resident->barangay_id,
                'That duplicate pair was not found.',
            );
        }

        return $pair;
    }

    /**
     * Null when either side of the pair is outside the caller's scope.
     *
     * @return array<string, mixed>|null
     */
    private function pairProjection(ActorContext $actor, ResidentDuplicatePair $pair): ?array
    {
        // withTrashed so a resolved pair still renders its two sides in the queue history.
        /** @var Resident|null $lower */
        $lower = Resident::withTrashed()->find($pair->lower_resident_id);
        /** @var Resident|null $higher */
        $higher = Resident::withTrashed()->find($pair->higher_resident_id);

        foreach ([$lower, $higher] as $resident) {
            $barangayId = $resident?->barangay_id === null ? null : (int) $resident->barangay_id;

            if (! $this->authorization->allowsBarangay($actor, $barangayId)) {
                return null;
            }
        }

        return [
            'id' => $pair->uuid,
            'rule' => $pair->rule,
            'confidence' => $pair->confidence,
            'decision' => $pair->decision,
            'decision_note' => $pair->decision_note,
            'decided_at' => $pair->decided_at?->toIso8601ZuluString(),
            'residents' => array_values(array_filter([
                $lower === null ? null : $this->sideProjection($lower),
                $higher === null ? null : $this->sideProjection($higher),
            ])),
        ];
    }

    /**
     * Enough of a resident for a reviewer to judge whether it is the same person — and
     * nothing about their welfare file. Deciding a duplicate does not require reading
     * somebody's case history.
     *
     * @return array<string, mixed>
     */
    private function sideProjection(Resident $resident): array
    {
        return [
            'id' => $resident->uuid,
            'name' => $resident->fullName(),
            'birth_date' => $resident->birth_date?->toDateString(),
            'barangay_id' => $resident->barangay_id,
            'verification_tier' => $resident->verification_tier->value,
            'is_active' => (bool) $resident->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeProjection(ResidentMerge $merge, Resident $survivor, Resident $absorbed): array
    {
        return [
            'id' => $merge->uuid,
            'survivor_resident_id' => $survivor->uuid,
            'absorbed_resident_id' => $absorbed->uuid,
            'reason' => $merge->reason,
            'reassigned' => [
                'accounts' => $merge->reassigned_accounts,
                'credentials' => $merge->reassigned_credentials,
                'kyc_cases' => $merge->reassigned_kyc_cases,
                'sectors' => $merge->reassigned_sectors,
            ],
            'merged_at' => $merge->merged_at?->toIso8601ZuluString(),
        ];
    }
}
