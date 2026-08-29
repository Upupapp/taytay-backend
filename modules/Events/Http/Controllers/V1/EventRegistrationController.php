<?php

declare(strict_types=1);

namespace Modules\Events\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Events\Application\EventRegistrationService;
use Modules\Events\Application\EventService;
use Modules\Events\Domain\AttendanceState;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Events\Infrastructure\Eloquent\EventRegistration;
use Modules\Identity\Application\AccountDirectory;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\IdempotencyService;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Shared\Support\Identifier;

/**
 * Taking a place at an event, and recording who turned up (ADR 0031).
 *
 * TWO AUDIENCES AND TWO PROJECTIONS, as everywhere else in this module. What differs here is that
 * the staff projection carries a **name** and a **staff note**, and the citizen one carries
 * neither — a registrant reading their own record must not find the door volunteer's remark about
 * them in it.
 *
 * EVERY CITIZEN READ IS SCOPED AT THE QUERY to the resident resolved from the token. There is no
 * citizen endpoint that takes a registration id and looks it up unscoped, so *"a citizen cannot
 * access another resident's registration by changing the ID"* is held by absence rather than by a
 * check.
 */
final class EventRegistrationController
{
    public function __construct(
        private readonly EventRegistrationService $registrations,
        private readonly EventService $events,
        private readonly AuthorizationService $authorization,
        private readonly AccountDirectory $accounts,
        private readonly ResidentDirectory $residents,
        private readonly IdempotencyService $idempotency,
    ) {}

    // ── citizen ───────────────────────────────────────────────────────────────────────

    /**
     * Take a place.
     *
     * WRAPPED IN THE IDEMPOTENCY SERVICE, and safe without it. A client that sends
     * `Idempotency-Key` gets its stored response replayed; a client that does not still cannot
     * duplicate a registration, because the service returns the existing one and the database
     * refuses a second live row either way (ADR 0031 §3).
     */
    public function register(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $model = $this->publiclyVisibleEventOrFail($event);
        $residentId = $this->ownResidentIdOrFail($actor);

        [$status, $body] = $this->idempotency->execute(
            $request->header('Idempotency-Key'),
            $actor->subjectId,
            'POST /api/v1/events/{event}/registration',
            ['event' => (string) $model->uuid],
            function () use ($model, $residentId, $actor): array {
                [$registration, $existed] = $this->registrations->register($model, $residentId, $actor);

                // 200 for a place already held, 201 for a new one — so a client can tell whether
                // its retry was the attempt that landed.
                return [$existed ? 200 : 201, $this->citizenProjection($registration, $model)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * Withdraw.
     */
    public function withdraw(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $model = $this->publiclyVisibleEventOrFail($event);
        $residentId = $this->ownResidentIdOrFail($actor);

        /** @var EventRegistration|null $registration */
        $registration = $this->registrations->registrationsForResident($residentId)
            ->where('event_id', $model->id)
            ->whereNotNull('active_key')
            ->first();

        if ($registration === null) {
            throw ResourceNotFoundException::make('You do not hold a place at this event.');
        }

        return ApiResponse::item($this->citizenProjection(
            $this->registrations->cancelOwn($registration, $actor),
            $model,
        ));
    }

    /**
     * Everything this person has signed up for.
     */
    public function mine(Request $request, ActorContext $actor): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->registrations->registrationsForResident($residentId);

        if ($request->boolean('upcoming')) {
            $query->whereIn('event_id', Event::query()->where('ends_at', '>=', now())->select('id'));
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        $events = Event::query()->whereIn('id', $rows->pluck('event_id')->unique())->get()->keyBy('id');

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (EventRegistration $registration): array => $this->citizenProjection(
                $registration,
                $events->get($registration->event_id),
            ),
        );
    }

    /**
     * One of this person's own registrations.
     */
    public function mineShow(Request $request, ActorContext $actor, string $registration): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        /** @var EventRegistration|null $model */
        $model = $this->registrations->registrationsForResident($residentId)
            ->where(function ($where) use ($registration): void {
                $where->where('reference', $registration);

                if (Identifier::isUuid($registration)) {
                    $where->orWhere('uuid', $registration);
                }
            })
            ->first();

        /*
         * SCOPED AT THE QUERY, so somebody else's registration is ABSENT rather than refused —
         * `404`, not `403`, and produced by there being no row rather than by a check. Answering
         * `403` would confirm that the id names a real registration, which is most of what an
         * enumeration attempt wants (OWASP API1).
         */
        if ($model === null) {
            throw ResourceNotFoundException::make('That registration was not found.');
        }

        return ApiResponse::item($this->citizenProjection(
            $model,
            Event::query()->find($model->event_id),
        ));
    }

    // ── staff ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->registrations->registrationsFor($model);

        foreach (['status', 'attendance'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        /*
         * ONE LOOKUP FOR THE WHOLE PAGE, not one per row. `summaryFor()` inside the projection was
         * an N+1: measured at 11 queries for one registrant and 18 for eight. At a feeding
         * programme with two hundred registrants that is two hundred round trips to render one
         * page — and it degrades exactly when the office is busiest.
         */
        $names = $this->residents->summariesFor(
            $rows->pluck('resident_id')->map(strval(...))->all(),
        );

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (EventRegistration $registration): array => $this->staffProjection($registration, $names),
            $this->registrations->summaryFor($model),
        );
    }

    public function cancel(Request $request, ActorContext $actor, string $event, string $registration): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->staffProjection($this->registrations->cancelAsStaff(
            $this->registrationOrFail($model, $registration),
            $validated['reason'],
            $actor,
        )));
    }

    public function restore(Request $request, ActorContext $actor, string $event, string $registration): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);

        return ApiResponse::item($this->staffProjection($this->registrations->restore(
            $this->registrationOrFail($model, $registration),
            $actor,
        )));
    }

    /**
     * Run the waitlist.
     *
     * Cancellations promote automatically; this is the lever for the other way room appears —
     * somebody raised the capacity, or the office wants to confirm the queue has been worked.
     * It promotes **in order**, never a chosen person: a door that could pick would be a door
     * where it is worth knowing somebody.
     */
    public function promote(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);
        $promoted = $this->registrations->promoteFromWaitlist($model);

        // A list, so the names resolve in one query rather than one per promoted registrant.
        $promotedNames = $this->residents->summariesFor(
            array_map(static fn (EventRegistration $r): string => (string) $r->resident_id, $promoted),
        );

        return ApiResponse::item([
            'promoted_count' => count($promoted),
            'promoted' => array_map(
                fn (EventRegistration $r): array => $this->staffProjection($r, $promotedNames),
                $promoted,
            ),
        ] + $this->registrations->summaryFor($model->refresh()));
    }

    public function markAttendance(
        Request $request,
        ActorContext $actor,
        string $event,
        string $registration,
    ): JsonResponse {
        // Its own permission: the person at the door is often not the person who wrote the event.
        $this->authorization->authorize($actor, Permission::EventMarkAttendance);

        $model = $this->eventOrFail($event);

        $validated = $request->validate([
            'attendance' => ['required', 'string', 'in:'.implode(',', AttendanceState::values())],
        ]);

        return ApiResponse::item($this->staffProjection($this->registrations->markAttendance(
            $this->registrationOrFail($model, $registration),
            AttendanceState::from($validated['attendance']),
            $actor,
        )));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * What the registrant sees about their own place.
     *
     * NO STAFF NOTE, NO CANCELLING OFFICER, NO ACCOUNT ID. A separate method rather than the staff
     * one with fields removed — the same rule as ADR 0028 §1 — so the next column somebody adds to
     * the staff view does not silently appear here.
     *
     * @return array<string, mixed>
     */
    private function citizenProjection(EventRegistration $registration, ?Event $event): array
    {
        return [
            'id' => $registration->uuid,
            // What is read out at the door.
            'reference' => $registration->reference,
            'status' => $registration->status->value,
            'registered_at' => $registration->registered_at?->toIso8601ZuluString(),
            // So "was I always in, or did I get in later?" has an answer.
            'promoted_at' => $registration->promoted_at?->toIso8601ZuluString(),
            'attendance' => $registration->attendance->value,
            'cancelled_at' => $registration->cancelled_at?->toIso8601ZuluString(),
            /*
             * The registrant sees why their own place was cancelled, because being removed from a
             * list without being told why is the thing people come to the counter about.
             */
            'cancellation_reason' => $registration->cancellation_reason,
            'event' => $event === null ? null : [
                'id' => $event->uuid,
                'slug' => $event->slug,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601ZuluString(),
                'ends_at' => $event->ends_at?->toIso8601ZuluString(),
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'status' => $event->status->value,
                // Carried so somebody looking at their own registration for a cancelled event
                // sees the reason without a second request.
                'cancellation_reason' => $event->cancellation_reason,
            ],
        ];
    }

    /**
     * The list the office works from.
     *
     * @param  array<string, ResidentSummary>|null  $names  resolved for the whole page, or null
     *                                                      when rendering a single row
     * @return array<string, mixed>
     */
    private function staffProjection(EventRegistration $registration, ?array $names = null): array
    {
        $residentId = (string) $registration->resident_id;

        /*
         * `null` means no map was supplied — a single-row caller, which does its own lookup.
         * An absent KEY in a supplied map means the page asked and this resident could not be
         * resolved, which is a real answer: the row renders without a name.
         *
         * This was `array $names = []` with `$names[$id] ?? $this->residents->summaryFor($id)`,
         * where an empty default is indistinguishable from a supplied page that lacks the row —
         * so every unresolvable registrant cost a query, ON TOP of the batch. Measured 12 queries
         * for one such row and 17 for six, against 11 flat when every resident resolves. Residents
         * become unresolvable through exactly the operation this system performs on purpose:
         * duplicate merging (ADR 0042 section 10).
         */
        $summary = $names === null
            ? $this->residents->summaryFor($residentId)
            : ($names[$residentId] ?? null);

        return [
            'id' => $registration->uuid,
            'reference' => $registration->reference,
            'resident_id' => $registration->resident_id,
            /*
             * The name, and only the name. A door list does not need an address, a contact number
             * or a vulnerability factor, and `ResidentSummary` is the published minimum precisely
             * so this endpoint cannot casually include them (Article 5.2).
             */
            'resident_name' => $summary?->displayName,
            'status' => $registration->status->value,
            'registered_at' => $registration->registered_at?->toIso8601ZuluString(),
            'promoted_at' => $registration->promoted_at?->toIso8601ZuluString(),
            'attendance' => $registration->attendance->value,
            'attendance_marked_at' => $registration->attendance_marked_at?->toIso8601ZuluString(),
            'attendance_marked_by' => $registration->attendance_marked_by,
            'source_channel' => $registration->source_channel,
            // Staff-only, and it never appears in the citizen projection above.
            'staff_notes' => $registration->staff_notes,
            'cancelled_at' => $registration->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by' => $registration->cancelled_by,
            'cancellation_reason' => $registration->cancellation_reason,
        ];
    }

    // ── lookups ───────────────────────────────────────────────────────────────────────

    private function eventOrFail(string $identifier): Event
    {
        /** @var Event|null $event */
        $event = Event::query()
            ->where('uuid', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if ($event === null) {
            throw ResourceNotFoundException::make('That event was not found.');
        }

        return $event;
    }

    /**
     * An event a citizen is allowed to know about.
     *
     * Registration goes through the PUBLIC query, so nobody can register for a draft — which would
     * otherwise be a way to discover one, and to occupy seats at an event before it is announced.
     */
    private function publiclyVisibleEventOrFail(string $identifier): Event
    {
        /** @var Event|null $event */
        $event = $this->events->publicQuery()
            ->where(function ($where) use ($identifier): void {
                $where->where('slug', $identifier)->orWhere('uuid', $identifier);
            })
            ->first();

        if ($event === null) {
            throw ResourceNotFoundException::make('That event was not found.');
        }

        return $event;
    }

    private function registrationOrFail(Event $event, string $identifier): EventRegistration
    {
        /** @var EventRegistration|null $registration */
        $registration = EventRegistration::query()
            // Scoped to the event in the path, so a registration id from another event is not
            // reachable by pairing it with an event the caller can see.
            ->where('event_id', $event->id)
            ->where(function ($where) use ($identifier): void {
                $where->where('uuid', $identifier)->orWhere('reference', $identifier);
            })
            ->first();

        if ($registration === null) {
            throw ResourceNotFoundException::make('That registration was not found.');
        }

        return $registration;
    }

    private function ownResidentIdOrFail(ActorContext $actor): string
    {
        $residentId = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        /*
         * An account with no resident behind it cannot register. Not officiousness: a place at an
         * event is held by a PERSON, checked against a list at a door, and a registration with no
         * resident record is a name nobody can verify and a seat nobody can count against a
         * household.
         */
        if ($residentId === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        return $residentId;
    }
}
