<?php

declare(strict_types=1);

namespace Modules\Events\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Events\Application\EventRegistrationService;
use Modules\Events\Application\EventService;
use Modules\Events\Domain\EventStatus;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Files\Application\DocumentLibrary;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Official LGU events (ADR 0030).
 *
 * TWO PROJECTIONS AND TWO QUERIES, as with the newsfeed. The public one carries what somebody
 * needs in order to turn up; the staff one adds the status, the author and the configuration.
 *
 * **AVAILABILITY IS COMPUTED ON EVERY READ.** There is no column to return, so there is no way for
 * this endpoint to report `open` for a registration that closed an hour ago.
 */
final class EventController
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly AuthorizationService $authorization,
        private readonly DocumentLibrary $library,
    ) {}

    // ── staff ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->events->adminQuery();

        foreach (['status', 'category'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where(function ($where) use ($search): void {
                $where->where('title', 'like', '%'.$search.'%')
                    ->orWhere('venue_name', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Event $event): array => $this->adminProjection($event),
        );
    }

    public function show(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        return ApiResponse::item($this->adminProjection($this->eventOrFail($event)));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        return ApiResponse::created($this->adminProjection(
            $this->events->draft($request->validate($this->rules()), $actor),
        ));
    }

    public function update(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);

        $updated = $this->events->update($model, $request->validate($this->rules(partial: true)), $actor);

        /*
         * RAISING THE CAPACITY IS THE OTHER WAY ROOM APPEARS, and it is the one that is easy to
         * forget: a cancellation promotes the queue on its own, but an office that moves an event
         * to a bigger hall and adds thirty seats would otherwise leave thirty people waiting for a
         * seat that already exists. Idempotent and cheap when nothing changed (ADR 0031 §5).
         */
        $this->registrations->promoteFromWaitlist($updated);

        // A cover swapped on an already-published event must become deliverable now.
        $this->syncCoverMedia($updated);

        return ApiResponse::item($this->adminProjection($updated->refresh()));
    }

    /**
     * Publish, cancel, complete, archive.
     */
    public function transition(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $model = $this->eventOrFail($event);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', EventStatus::values())],
            'reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
        ]);

        $target = EventStatus::from($validated['status']);

        // The permission comes from the target state: publishing and cancelling are what residents
        // plan their week around, and an office may want those held more narrowly than drafting.
        $this->authorization->authorize($actor, $target->requiredPermission());

        $updated = $this->events->transition($model, $target, $actor, $validated['reason'] ?? null);

        /*
         * The cover becomes publicly deliverable when the event is published, and stops being so
         * when it is archived. Driven by `isPubliclyVisible()` rather than by which transition was
         * requested, so a state added to the enum later cannot leave an image public on an event
         * nobody can see (ADR 0033 §3).
         *
         * A CANCELLED EVENT KEEPS ITS COVER. It stays on the public list with its reason showing
         * (ADR 0030 §3), and a listing that lost its image the moment it was called off would look
         * broken to exactly the people who most need to read it.
         */
        $this->syncCoverMedia($updated);

        return ApiResponse::item($this->adminProjection($updated));
    }

    public function duplicate(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        return ApiResponse::created($this->adminProjection(
            $this->events->duplicate($this->eventOrFail($event), $actor),
        ));
    }

    /**
     * What happened to an event, and when (TAB 07).
     *
     * Built from the event's own dated columns, under `event.manage`, for the same reason the
     * newsfeed history is: every act here already writes to the audit trail, and `audit.view` is
     * deliberately withheld from everybody but the Data Protection Officer. An events officer
     * reading the lifecycle of their own event would otherwise have needed the permission that
     * opens the trail of every approval in the office.
     *
     * **Cancellation carries its reason and cancellation is one-way.** An event that is back on is
     * a new event naming the old one, so this history is the whole story of this event and does
     * not need to describe a revival that cannot happen.
     */
    public function history(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        $model = $this->eventOrFail($event);

        $events = array_values(array_filter([
            ['kind' => 'created', 'occurred_at' => $model->created_at?->toIso8601ZuluString(), 'detail' => null],
            ['kind' => 'published', 'occurred_at' => $model->published_at?->toIso8601ZuluString(), 'detail' => null],
            [
                'kind' => 'cancelled',
                'occurred_at' => $model->cancelled_at?->toIso8601ZuluString(),
                // Recorded at the point of cancellation and shown here. People arranged their day
                // around this; "cancelled" with no reason is the version that wastes the trip.
                'detail' => $model->cancellation_reason,
            ],
        ], static fn (array $e): bool => $e['occurred_at'] !== null));

        usort($events, static fn (array $a, array $b): int => $b['occurred_at'] <=> $a['occurred_at']);

        return ApiResponse::page(Page::fromArray($events, PaginationParams::fromRequest($request)));
    }

    /**
     * The registration summary the console shows on an event.
     */
    public function registrationSummary(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EventManage);

        /*
         * Every number here is COUNTED FROM COMMITTED ROWS, not read from a column. TAB 25 shipped
         * this endpoint with zeroes as present placeholders precisely so that filling them in
         * needed no shape change on any client (ADR 0031 §2).
         */
        return ApiResponse::item($this->registrations->summaryFor($this->eventOrFail($event)));
    }

    // ── readers ───────────────────────────────────────────────────────────────────────

    public function publicIndex(Request $request, ActorContext $actor): JsonResponse
    {
        $pagination = PaginationParams::fromRequest($request);
        $query = $this->events->publicQuery();

        $category = $request->query('category');

        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        // Past events are excluded by default: a resident opening the list wants what is coming.
        if (! $request->boolean('include_past')) {
            $query->where('ends_at', '>=', now());
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        // Every cover's public renditions for the page, in two queries. Asking per event cost
        // three each — measured 7 queries for one event and 22 for six (ADR 0042 §9).
        $coverUrls = $this->library->publicMediaUrlsFor(
            $rows->pluck('cover_file_id')->filter()->map(strval(...))->values()->all(),
        );

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Event $event): array => $this->publicProjection($event, $coverUrls),
        );
    }

    /**
     * By slug, because that is what is printed on a poster.
     */
    public function publicShow(Request $request, ActorContext $actor, string $event): JsonResponse
    {
        /** @var Event|null $model */
        $model = $this->events->publicQuery()
            ->where(function ($where) use ($event): void {
                $where->where('slug', $event)->orWhere('uuid', $event);
            })
            ->first();

        /*
         * The lookup runs against the public query, so a draft is simply not there — no status
         * check follows. That is the acceptance criterion, held the way ADR 0028 §2 holds the
         * same one for posts.
         */
        if ($model === null) {
            throw ResourceNotFoundException::make('That event was not found.');
        }

        return ApiResponse::item($this->publicProjection($model));
    }

    /**
     * Keeps the cover's public renditions in step with whether the event is visible.
     *
     * Outside the transition transaction: re-encoding an image is slow, and a failure to resize a
     * poster must not roll back the publication of an event. The event is published either way,
     * and a missing cover is a smaller problem than an announcement that silently did not go out.
     */
    private function syncCoverMedia(Event $event): void
    {
        $cover = $event->cover_file_id;

        if ($cover === null) {
            return;
        }

        if ($event->status->isPubliclyVisible()) {
            $this->library->publishMedia([(string) $cover]);

            return;
        }

        $this->library->withdrawMedia([(string) $cover]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function adminProjection(Event $event): array
    {
        return $this->publicProjection($event) + [
            'status' => $event->status->value,
            'author_subject_id' => $event->author_subject_id,
            'published_at' => $event->published_at?->toIso8601ZuluString(),
            'available_transitions' => array_map(
                static fn (EventStatus $status): string => $status->value,
                $event->status->allowedNext(),
            ),
        ];
    }

    /**
     * What somebody needs in order to turn up.
     *
     * A separate method, not the admin one with fields removed — the same rule as ADR 0028 §1. A
     * draft's status never reaches a reader because a reader never receives a draft, so the field
     * is absent rather than filtered.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, array<string, string>>|null  $coverUrls  resolved for the whole page,
     *                                                                or null for a single event
     */
    private function publicProjection(Event $event, ?array $coverUrls = null): array
    {
        $availability = $this->events->availabilityFor($event);

        return [
            'id' => $event->uuid,
            // What is on the poster.
            'slug' => $event->slug,
            'title' => $event->title,
            'summary' => $event->summary,
            'description' => $event->description,
            'category' => $event->category,
            'cover_file_id' => $event->cover_file_id,
            /*
             * Public URLs of the RE-ENCODED renditions, never of the uploaded original — which
             * stays on the private disk for its whole life. Empty until the event is published.
             */
            /*
             * `null` means no map was supplied — a single-event caller. An absent KEY in a
             * supplied map means the page asked and this cover has no published renditions,
             * which is a real answer and not a lookup to retry per row (ADR 0033 §3).
             */
            'cover_urls' => $coverUrls === null
                ? $this->library->publicMediaUrls($event->cover_file_id)
                : ($coverUrls[(string) $event->cover_file_id] ?? []),
            // Always emitted when there is a cover, so a client never has to decide what to do
            // with a missing one.
            'cover_alt_text' => $event->cover_alt_text,
            // UTC, with the local context alongside it (Article 4).
            'starts_at' => $event->starts_at?->toIso8601ZuluString(),
            'ends_at' => $event->ends_at?->toIso8601ZuluString(),
            'timezone' => $event->timezone,
            'venue_name' => $event->venue_name,
            'venue_address' => $event->venue_address,
            'map_url' => $event->map_url,
            'contact_office' => $event->contact_office,
            'contact_person' => $event->contact_person,
            'contact_number' => $event->contact_number,
            'participation_note' => $event->participation_note,
            'participant_instructions' => $event->participant_instructions,
            'registration' => [
                'required' => (bool) $event->registration_required,
                'opens_at' => $event->registration_opens_at?->toIso8601ZuluString(),
                'closes_at' => $event->registration_closes_at?->toIso8601ZuluString(),
                'capacity' => $event->capacity === null ? null : (int) $event->capacity,
                'waitlist_enabled' => (bool) $event->waitlist_enabled,
                /*
                 * DERIVED ON EVERY READ. There is no column, so this can never say `open` for a
                 * registration that closed an hour ago (ADR 0030 §2).
                 */
                'availability' => $availability->value,
                // The wording travels with the state, so it is the same everywhere and a client
                // cannot invent a friendlier one for a closed window.
                'message' => $availability->message(),
            ],
            /*
             * A cancelled event stays visible with its reason. Somebody arranged their day around
             * this, and removing it silently means they travel to a covered court to find nobody
             * there.
             */
            'is_cancelled' => $event->status === EventStatus::Cancelled,
            'cancellation_reason' => $event->cancellation_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => [$required, 'string', 'max:20000'],
            'category' => [$required, 'string', 'max:48'],
            'cover_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cover_alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => [$required, 'date'],
            // Also checked in the service against the merged values, so a partial update cannot
            // produce an end before a start by sending only one of them.
            'ends_at' => [$required, 'date'],
            'timezone' => ['sometimes', 'string', 'max:48'],
            'venue_name' => [$required, 'string', 'max:160'],
            'venue_address' => [$required, 'string', 'max:255'],
            'map_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'contact_office' => ['sometimes', 'nullable', 'string', 'max:160'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:160'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registration_required' => ['sometimes', 'boolean'],
            'registration_opens_at' => ['sometimes', 'nullable', 'date'],
            'registration_closes_at' => ['sometimes', 'nullable', 'date'],
            // Null means uncapped, which is different from zero.
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'participation_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'participant_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

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
}
