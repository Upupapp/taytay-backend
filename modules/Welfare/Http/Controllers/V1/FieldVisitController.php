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
use Modules\Welfare\Application\FieldVisitService;
use Modules\Welfare\Application\SafeguardingRegistry;
use Modules\Welfare\Domain\ObservationKind;
use Modules\Welfare\Domain\VisitStatus;
use Modules\Welfare\Infrastructure\Eloquent\FieldVisit;
use Modules\Welfare\Infrastructure\Eloquent\VisitChecklistItem;
use Modules\Welfare\Infrastructure\Eloquent\VisitObservation;

/**
 * Field visits, for staff (ADR 0022).
 *
 * TWO THINGS THIS CONTROLLER NEVER DOES, and both are acceptance criteria:
 *
 *  * it accepts no coordinate, check-in or arrival ping — there is no field in the contract to
 *    send one to, and `NoLocationTrackingTest` fails the build if one appears;
 *  * it returns **no safeguarding detail in the list projection**. The visit detail carries a
 *    one-sentence worker-safety advisory when there is one; the list carries nothing at all.
 */
final class FieldVisitController
{
    public function __construct(
        private readonly FieldVisitService $visits,
        private readonly SafeguardingRegistry $safeguarding,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The calendar and the worker's own queue.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitView);

        $pagination = PaginationParams::fromRequest($request);

        $query = $request->boolean('overdue_only')
            ? $this->visits->overdueQuery()
            : $this->visits->query();

        /*
         * `?scope=mine` — the worker's own round (TAB 07).
         *
         * The command is explicit that this is *"scope, not a new resource"*, and the reason shows
         * in the query plan: `idx_visits_worker_queue` is already
         * `(assigned_to, status, scheduled_for)`. A separate `admin/my-visits` route would have
         * been a second controller, a second projection and a second set of authorization tests
         * over the same index and the same rows.
         *
         * It resolves to the **caller's own** subject id, server-side. `?assigned_to=<uuid>`
         * already exists and still does; the difference is that this one cannot be pointed at
         * somebody else, which is what makes it safe to put behind a menu item labelled "mine".
         *
         * An actor with no subject id — a machine context — matches nothing rather than
         * everything. `whereRaw('1 = 0')` is the same deny-by-default the barangay scope uses.
         */
        if ($request->query('scope') === 'mine') {
            $actor->subjectId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('assigned_to', $actor->subjectId);
        }

        foreach (['status', 'purpose', 'assigned_to', 'resident_id'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        foreach ([['from', '>='], ['to', '<=']] as [$param, $operator]) {
            $value = $request->query($param);

            if (is_string($value) && $value !== '') {
                $query->whereDate('scheduled_for', $operator, $value);
            }
        }

        // Overdue first, then soonest — the order a worker plans their day in.
        $query->orderBy('scheduled_for')->orderBy('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (FieldVisit $visit): array => $this->listProjection($visit),
        );
    }

    public function show(Request $request, ActorContext $actor, string $visit): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitView);

        $model = $this->visitOrFail($actor, $visit);

        return ApiResponse::item($this->listProjection($model) + [
            'checklist' => $model->checklist()->get()->map(fn (VisitChecklistItem $item): array => [
                'code' => $item->code,
                'label' => $item->label,
                'checked' => (bool) $item->checked,
                'note' => $item->note,
            ])->all(),
            'observations' => $model->observations()->get()
                ->map(fn (VisitObservation $o): array => $this->observationProjection($o))->all(),
            'service_needs' => $model->service_needs,
            'declined_reason' => $model->declined_reason,
            'outcome' => $model->outcome,
            'next_action' => $model->next_action,
            'follow_up_on' => $model->follow_up_on?->toDateString(),
            /*
             * ONE SENTENCE, ON THE DETAIL VIEW ONLY.
             *
             * A worker being sent to a house is entitled to know there is a risk to them without
             * being told a family's protection history. No category, no count, no history — a
             * number is a judgement about a family that travels further than the sentence does
             * (ADR 0022 §4).
             */
            'worker_safety_advisory' => $this->safeguarding->advisoryFor((string) $model->resident_id),
        ]);
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitManage);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'case_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'household_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'purpose' => ['required', 'string', 'in:initial-assessment,verification,follow-up,monitoring,crisis-response,document-collection'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scheduled_for' => ['required', 'date'],
            // The office's own words — "morning", "after 2pm" — because that is how visits are
            // actually arranged with a household that has no diary.
            'scheduled_window' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address_visited' => ['sometimes', 'nullable', 'string', 'max:255'],
            'checklist' => ['sometimes', 'array'],
            'checklist.*.code' => ['required', 'string', 'max:48'],
            'checklist.*.label' => ['required', 'string', 'max:160'],
        ]);

        $resident = $this->residents->summaryFor($validated['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $resident->barangayId, 'That resident was not found.');

        return ApiResponse::created($this->listProjection(
            $this->visits->schedule($validated, $actor),
        ));
    }

    /**
     * Records one observation, carrying whose claim it is.
     */
    public function observe(Request $request, ActorContext $actor, string $visit): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitManage);

        $model = $this->visitOrFail($actor, $visit);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', ObservationKind::values())],
            'body' => ['required', 'string', 'max:1000'],
            'attributed_to' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        return ApiResponse::created($this->observationProjection($this->visits->observe(
            $model,
            ObservationKind::from($validated['kind']),
            $validated['body'],
            $validated['attributed_to'] ?? null,
            $actor,
        )));
    }

    public function check(Request $request, ActorContext $actor, string $visit): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitManage);

        $model = $this->visitOrFail($actor, $visit);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'checked' => ['required', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $item = $this->visits->check($model, $validated['code'], $validated['checked'], $validated['note'] ?? null);

        return ApiResponse::item([
            'code' => $item->code,
            'checked' => (bool) $item->checked,
            'note' => $item->note,
        ]);
    }

    public function conclude(Request $request, ActorContext $actor, string $visit): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::VisitManage);

        $model = $this->visitOrFail($actor, $visit);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:completed,not-found,refused,cancelled'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:500'],
            'service_needs' => ['sometimes', 'nullable', 'string', 'max:500'],
            'declined_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:500'],
            'follow_up_on' => ['sometimes', 'nullable', 'date'],
        ]);

        return ApiResponse::item($this->listProjection($this->visits->conclude(
            $model,
            VisitStatus::from($validated['status']),
            $validated,
            $actor,
        )));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * The LIST projection. Deliberately thin.
     *
     * No observations, no outcome text, no safeguarding of any kind — not even a marker. A flag
     * in a list marks the family to every person who scrolls past, which is the exposure the
     * master command's "minimal list-view" requirement is about.
     *
     * @return array<string, mixed>
     */
    private function listProjection(FieldVisit $visit): array
    {
        return [
            'id' => $visit->uuid,
            'reference_number' => $visit->reference_number,
            'resident_id' => $visit->resident_id,
            'status' => $visit->status->value,
            'purpose' => $visit->purpose,
            'assigned_to' => $visit->assigned_to,
            'scheduled_for' => $visit->scheduled_for?->toDateString(),
            'scheduled_window' => $visit->scheduled_window,
            'address_visited' => $visit->address_visited,
            'completed_at' => $visit->completed_at?->toIso8601ZuluString(),
            // The worker owes this, not the family — named that way so a queue mixing it with
            // applicant-owed work is visibly wrong.
            'is_overdue' => $visit->isOverdue(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observationProjection(VisitObservation $observation): array
    {
        return [
            'id' => $observation->uuid,
            // The whole point of the table: a reader can tell a judgement from something the
            // family said, six months later, without having been there.
            'kind' => $observation->kind->value,
            'kind_label' => $observation->kind->label(),
            'body' => $observation->body,
            'attributed_to' => $observation->attributed_to,
            'recorded_at' => $observation->recorded_at?->toIso8601ZuluString(),
        ];
    }

    private function visitOrFail(ActorContext $actor, string $uuid): FieldVisit
    {
        /** @var FieldVisit|null $visit */
        $visit = FieldVisit::query()->where('uuid', $uuid)->first();

        if ($visit === null) {
            throw ResourceNotFoundException::make('That visit was not found.');
        }

        $resident = $this->residents->summaryFor((string) $visit->resident_id);

        // Out of scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay($actor, $resident?->barangayId, 'That visit was not found.');

        return $visit;
    }
}
