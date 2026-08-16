<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseAssignmentService;
use Modules\Welfare\Application\CaseService;
use Modules\Welfare\Application\CaseTimeline;
use Modules\Welfare\Application\WelfareAudit;
use Modules\Welfare\Domain\CasePriority;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\CaseType;
use Modules\Welfare\Infrastructure\Eloquent\CaseAssignment;
use Modules\Welfare\Infrastructure\Eloquent\CaseEvent;
use Modules\Welfare\Infrastructure\Eloquent\CaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The staff case queue and case file (ADR 0016).
 *
 * ONE TRANSITION ENDPOINT, NOT NINE VERBS. `POST .../transitions` takes a target state and
 * resolves the permission from it, so the state machine and the authorization table stay in
 * one place. Nine endpoints would be nine places the transition map could be forgotten, and
 * the tenth somebody adds in a hurry would be the one that skips it.
 *
 * RESTRICTED CASES ARE FILTERED AT THE QUERY. A protective-services case is invisible without
 * `request.view-sensitive` — absent from the list, absent from the count, and `404` on a
 * guessed id. Knowing a protection case exists for a named person is most of the disclosure,
 * so a 403 would defeat the control (ADR 0016 §5).
 */
final class CaseController
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseAssignmentService $assignments,
        private readonly CaseTimeline $timeline,
        private readonly AuthorizationService $authorization,
        private readonly ResidentDirectory $residents,
        private readonly WelfareAudit $audit,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $pagination = PaginationParams::fromRequest($request);

        $query = $this->authorization->scopeToBarangays($actor, $this->cases->query());

        // Restricted types are excluded at the query, so the pagination total cannot betray
        // how many protection cases exist in a barangay.
        if ($this->authorization->denies($actor, Permission::RequestViewSensitive)) {
            $query->whereNotIn('type', $this->restrictedTypeValues());
        }

        $status = $request->query('status');

        if (is_string($status) && CaseStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($request->boolean('open_only')) {
            $query->whereIn('status', CaseStatus::openValues());
        }

        $type = $request->query('type');

        if (is_string($type) && CaseType::tryFrom($type) !== null) {
            $query->where('type', $type);
        }

        $assignedTo = $request->query('assigned_to');

        if (is_string($assignedTo) && $assignedTo !== '') {
            // `me` avoids a client having to know its own subject id, and avoids it sending
            // somebody else's.
            $query->where('assigned_to', $assignedTo === 'me' ? $actor->subjectId : $assignedTo);
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }

        // The narrowest scope: barangay bound AND ownership (ADR 0012).
        if ($actor->scope->requiresCaseAssignment()) {
            $query->where('assigned_to', $actor->subjectId);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (WelfareCase $case): array => $this->listProjection($case),
        );
    }

    public function show(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        $this->audit->recordRead($actor->subjectId, (string) $model->uuid);

        return ApiResponse::item($this->detailProjection($model));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestCreate);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:'.implode(',', CaseType::values())],
            'household_id' => ['nullable', 'string', 'max:64'],
            'program_id' => ['nullable', 'string', 'max:64'],
        ]);

        $type = CaseType::from($validated['type']);

        // Opening a protection case is itself a protection decision; it must not be reachable
        // by whoever happens to hold general intake rights.
        if ($type->isRestricted()) {
            $this->authorization->authorize($actor, Permission::RequestViewSensitive);
        }

        $resident = $this->residents->summaryFor($validated['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        // A case must open inside the caller's scope, or it lands where its own office cannot
        // work it.
        $this->authorization->authorizeBarangay($actor, $resident->barangayId, 'That resident was not found.');

        $model = $this->cases->open([
            'resident_id' => $resident->id,
            'household_id' => $validated['household_id'] ?? null,
            'program_id' => $validated['program_id'] ?? null,
            'type' => $type,
            // Cached from the resident so the queue can be scoped without asking
            // ResidentProfile for every row (ADR 0008 §10).
            'barangay_id' => $resident->barangayId,
        ], $actor);

        return ApiResponse::created($this->detailProjection($model));
    }

    /**
     * The one lifecycle endpoint.
     */
    public function transition(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        // Deliberately only `request.view` here: the real authorization is resolved from the
        // target state inside the service, so it lives with the state machine rather than
        // being duplicated per route.
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'to' => ['required', 'string', 'in:'.implode(',', CaseStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
            'applicant_message' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->cases->transition(
            $model,
            CaseStatus::from($validated['to']),
            $actor,
            fn (string $permission): bool => $this->authorization->allows($actor, $permission),
            $validated['reason'] ?? null,
            $validated['applicant_message'] ?? null,
        );

        return ApiResponse::item($this->detailProjection($updated));
    }

    public function changePriority(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssign);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'priority' => ['required', 'string', 'in:'.implode(',', CasePriority::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->detailProjection($this->cases->changePriority(
            $model,
            CasePriority::from($validated['priority']),
            $actor,
            $validated['reason'] ?? null,
        )));
    }

    public function assign(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssign);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'assignee_subject_id' => ['required', 'string', 'max:64'],
            'team' => ['nullable', 'string', 'max:64'],
        ]);

        $this->assignments->assign($model, $validated['assignee_subject_id'], $actor, $validated['team'] ?? null);

        return ApiResponse::item($this->detailProjection($model->refresh()));
    }

    public function unassign(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestAssign);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->assignments->unassign($model, $actor, $validated['reason']);

        return ApiResponse::item($this->detailProjection($model->refresh()));
    }

    public function archive(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestClose);

        $model = $this->caseOrFail($actor, $case);

        return ApiResponse::item($this->detailProjection($this->cases->archive($model, $actor)));
    }

    /**
     * The operational timeline, plus the transition and assignment logs.
     */
    public function history(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        $this->audit->recordRead($actor->subjectId, (string) $model->uuid);

        return ApiResponse::item([
            'transitions' => $model->transitions()->orderBy('occurred_at')->get()
                ->map(fn (CaseTransition $row): array => [
                    'id' => $row->uuid,
                    'from' => $row->from_status,
                    'to' => $row->to_status,
                    // Staff-facing: the internal justification, shown here and nowhere a
                    // citizen can reach.
                    'reason' => $row->reason,
                    'applicant_message' => $row->applicant_message,
                    'actor_subject_id' => $row->actor_subject_id,
                    'occurred_at' => $row->occurred_at?->toIso8601ZuluString(),
                ])->all(),
            'assignments' => $this->assignments->historyFor($model)
                ->map(fn (CaseAssignment $row): array => [
                    'id' => $row->uuid,
                    'assignee_subject_id' => $row->assignee_subject_id,
                    'team' => $row->team,
                    'assigned_at' => $row->assigned_at?->toIso8601ZuluString(),
                    'unassigned_at' => $row->unassigned_at?->toIso8601ZuluString(),
                    'unassigned_reason' => $row->unassigned_reason,
                ])->all(),
            'events' => $this->timeline->forStaff($model)
                ->map(fn (CaseEvent $row): array => [
                    'id' => $row->uuid,
                    'event_type' => $row->event_type,
                    'summary' => $row->summary,
                    'is_citizen_visible' => $row->isVisibleToCitizen(),
                    'occurred_at' => $row->occurred_at?->toIso8601ZuluString(),
                ])->all(),
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function listProjection(WelfareCase $case): array
    {
        return [
            'id' => $case->uuid,
            'case_number' => $case->case_number,
            'type' => $case->type->value,
            'status' => $case->status->value,
            'priority' => $case->priority->value,
            'resident_id' => $case->resident_id,
            'barangay_id' => $case->barangay_id,
            'assigned_to' => $case->assigned_to,
            'opened_at' => $case->opened_at?->toIso8601ZuluString(),
            'last_activity_at' => $case->last_activity_at?->toIso8601ZuluString(),
            'next_follow_up_on' => $case->next_follow_up_on?->toDateString(),
        ];
    }

    /**
     * The staff case file.
     *
     * Carries the internal flags and the available transitions. It deliberately does NOT embed
     * a vulnerability snapshot: that score is placeholder, decision-support-only (gap G-20),
     * and embedding it in the case payload would make it look like case data rather than
     * something a worker chose to consult. Clients call the vulnerability endpoint when a
     * worker asks for it (ADR 0016 §4).
     *
     * @return array<string, mixed>
     */
    private function detailProjection(WelfareCase $case): array
    {
        return $this->listProjection($case) + [
            'household_id' => $case->household_id,
            'program_id' => $case->program_id,
            'priority_reason' => $case->priority_reason,
            'needs_home_visit' => (bool) $case->needs_home_visit,
            'is_escalated' => (bool) $case->is_escalated,
            'opened_by' => $case->opened_by,
            'closed_at' => $case->closed_at?->toIso8601ZuluString(),
            'archived_at' => $case->archived_at?->toIso8601ZuluString(),
            'is_open' => $case->status->isOpen(),
            // Computed from the transition map, so a client renders what the server allows
            // rather than deciding for itself (ADR 0007 §4).
            'available_transitions' => $this->cases->availableTransitions($case),
        ];
    }

    /**
     * @return list<string>
     */
    private function restrictedTypeValues(): array
    {
        return array_values(array_map(
            static fn (CaseType $type): string => $type->value,
            array_filter(CaseType::cases(), static fn (CaseType $type): bool => $type->isRestricted()),
        ));
    }

    /**
     * Loads a case and enforces scope, assignment and restriction.
     *
     * Every staff route goes through here, so there is no verb a caller can switch to in order
     * to reach a case their scope excludes. Out-of-scope and restricted both return NOT FOUND,
     * never FORBIDDEN — "exists but not yours" is enough to enumerate, and for a protection
     * case the existence *is* the disclosure.
     */
    private function caseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        if ($case->isRestricted() && $this->authorization->denies($actor, Permission::RequestViewSensitive)) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        $this->authorization->authorizeBarangay(
            $actor,
            $case->barangay_id === null ? null : (int) $case->barangay_id,
            'That case was not found.',
        );

        if ($actor->scope->requiresCaseAssignment() && $case->assigned_to !== $actor->subjectId) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        return $case;
    }
}
