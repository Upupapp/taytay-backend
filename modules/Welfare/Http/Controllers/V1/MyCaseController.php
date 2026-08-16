<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccountDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseService;
use Modules\Welfare\Application\CaseTimeline;
use Modules\Welfare\Infrastructure\Eloquent\CaseEvent;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * An applicant tracking their own case (ADR 0007 projection, ADR 0016 §5).
 *
 * THE ACCEPTANCE CRITERION THIS CLASS EXISTS FOR: a citizen cannot infer internal case notes
 * from any payload here.
 *
 * The projection is built by *listing what may be shown*, never by taking the staff payload and
 * removing things. A subtractive projection leaks the first time somebody adds a column and
 * forgets the deny-list; an additive one fails closed, because a new field is absent until
 * someone decides it belongs.
 *
 * Absent by construction: the internal `reason` on every transition, staff identities,
 * assignment history, priority, `needs_home_visit`, `is_escalated`, the internal case type for
 * restricted work, and every timeline event that was not explicitly written with a message for
 * the applicant.
 *
 * The resident is resolved from the token. There is no identifier in the contract to tamper
 * with, which is what stops a case-tracking endpoint becoming a case-enumeration endpoint.
 */
final class MyCaseController
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly CaseTimeline $timeline,
        private readonly CaseService $cases,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        $pagination = PaginationParams::fromRequest($request);

        $query = WelfareCase::query()
            ->where('resident_id', $residentId)
            ->orderByDesc('opened_at');

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (WelfareCase $case): array => $this->summaryProjection($case),
        );
    }

    public function show(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        /** @var WelfareCase|null $model */
        $model = WelfareCase::query()
            ->where('uuid', $case)
            // Ownership is part of the lookup, not a check after it. Another applicant's case
            // id resolves to nothing rather than to a 403 that confirms it exists.
            ->where('resident_id', $residentId)
            ->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That request was not found.');
        }

        return ApiResponse::item($this->detailProjection($model));
    }

    /**
     * The applicant withdraws their own request.
     *
     * Ownership and the current state are BOTH re-checked here at execution time. The
     * `citizenMayCancel()` flag in the projection is what the client renders; it is not what
     * authorises the action (ADR 0007 §4) — a client that renders a stale button must still
     * be refused by the server.
     */
    public function cancel(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        /** @var WelfareCase|null $model */
        $model = WelfareCase::query()
            ->where('uuid', $case)
            ->where('resident_id', $residentId)
            ->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That request was not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /*
         * Ownership was established by the lookup above; the state limit belongs to the
         * application service, which re-checks it inside the row lock.
         *
         * Doing it here would read the status outside the transaction: staff could move the
         * case to `assessment` between the check and the write, and the withdrawal would land
         * anyway. It would also bind the rule to this one controller, so the next citizen
         * write path would silently not have it (ADR 0017 §5).
         *
         * `available_actions` in the projection is what a client renders. It is never what
         * authorises the action (ADR 0007 §4).
         */
        $updated = $this->cases->cancelByApplicant($model, $actor, $validated['reason']);

        return ApiResponse::item($this->detailProjection($updated));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function summaryProjection(WelfareCase $case): array
    {
        return [
            'id' => $case->uuid,
            'reference' => $case->case_number,
            // The projected vocabulary, never the internal one. `assessment` and `endorsed`
            // both read as `under-review`, because which desk holds the file would identify
            // the handling social worker.
            'status' => $case->status->citizenStatus(),
            'status_message' => $case->status->citizenMessage(),
            'submitted_at' => $case->opened_at?->toIso8601ZuluString(),
            'last_update_at' => $case->last_activity_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailProjection(WelfareCase $case): array
    {
        return $this->summaryProjection($case) + [
            'is_open' => $case->status->isOpen(),
            /*
             * Computed server-side. This replaces the citizen app's local `canCancel`, which
             * was a business rule inside a shipped mobile build that could not be patched on
             * demand (ADR 0007 §4).
             */
            'available_actions' => $case->status->citizenMayCancel() ? ['cancel'] : [],
            'timeline' => $this->timeline->forCitizen($case)
                ->map(fn (CaseEvent $event): array => [
                    'occurred_at' => $event->occurred_at?->toIso8601ZuluString(),
                    // The message written *for the applicant*. Never `summary`, which is the
                    // operator-facing line, and never the transition `reason`, which is the
                    // caseworker's internal justification.
                    'message' => $event->citizen_message,
                ])->all(),
        ];
    }

    private function ownResidentIdOrFail(ActorContext $actor): string
    {
        $residentId = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        if ($residentId === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        return $residentId;
    }
}
