<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Events\Domain\EventStatus;
use Modules\Events\Domain\RegistrationAvailability;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;

/**
 * Official LGU events (ADR 0030).
 *
 * TWO THINGS THIS CLASS HOLDS, AND BOTH ARE ABSENCES:
 *
 *  * **there is no stored availability**, so nothing can disagree with the clock;
 *  * **there is no citizen write path**, so "a resident cannot create or edit events" is not a
 *    permission check that could be forgotten on a new endpoint — there is no method for one to
 *    call.
 */
final class EventService
{
    public function __construct(private readonly EventsAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function draft(array $attributes, ActorContext $actor): Event
    {
        $this->assertTimesAreCoherent($attributes);
        $this->assertCoverHasAltText($attributes);

        /** @var Event $event */
        $event = Event::query()->create($this->writable($attributes) + [
            'slug' => $this->mintSlug((string) $attributes['title']),
            'status' => EventStatus::Draft,
            'author_subject_id' => $actor->subjectId,
            'timezone' => $attributes['timezone'] ?? 'Asia/Manila',
        ]);

        $this->audit->record($actor->subjectId, 'event.drafted', 'Event drafted', (string) $event->uuid);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Event $event, array $changes, ActorContext $actor): Event
    {
        if ($event->status === EventStatus::Archived) {
            throw new ApiException(ErrorCode::Conflict, 'An archived event cannot be edited.');
        }

        // Merged with the current values so a partial update cannot produce an end before a start
        // by only sending one of them.
        $this->assertTimesAreCoherent([
            'starts_at' => $changes['starts_at'] ?? $event->starts_at,
            'ends_at' => $changes['ends_at'] ?? $event->ends_at,
            'registration_opens_at' => $changes['registration_opens_at'] ?? $event->registration_opens_at,
            'registration_closes_at' => $changes['registration_closes_at'] ?? $event->registration_closes_at,
        ]);

        $this->assertCoverHasAltText([
            'cover_file_id' => $changes['cover_file_id'] ?? $event->cover_file_id,
            'cover_alt_text' => $changes['cover_alt_text'] ?? $event->cover_alt_text,
        ]);

        $event->forceFill($this->writable($changes))->save();

        $this->audit->record($actor->subjectId, 'event.updated', 'Event updated', (string) $event->uuid);

        return $event->refresh();
    }

    public function transition(
        Event $event,
        EventStatus $target,
        ActorContext $actor,
        ?string $reason = null,
    ): Event {
        return DB::transaction(function () use ($event, $target, $actor, $reason): Event {
            /** @var Event $event */
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            if (! $event->status->canMoveTo($target)) {
                throw InvalidStateTransitionException::between($event->status->value, $target->value);
            }

            /*
             * A cancellation must say why, and the reason is SHOWN TO THE PUBLIC. Somebody who
             * arranged their day around an event is owed more than "cancelled".
             */
            if ($target->requiresReason() && trim((string) $reason) === '') {
                throw new ApiException(ErrorCode::ValidationFailed, 'Say why this event is cancelled.');
            }

            $event->forceFill([
                'status' => $target,
                'published_at' => $target === EventStatus::Published
                    ? ($event->published_at ?? now())
                    : $event->published_at,
                'cancelled_at' => $target === EventStatus::Cancelled ? now() : $event->cancelled_at,
                'cancellation_reason' => $target === EventStatus::Cancelled ? $reason : $event->cancellation_reason,
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'event.'.$target->value,
                'Event '.$target->value,
                (string) $event->uuid,
            );

            return $event->refresh();
        });
    }

    /**
     * Copies an event as a new draft.
     *
     * The office runs the same feeding programme every month, and retyping a venue, a contact and
     * a set of instructions each time is how one of them ends up wrong.
     *
     * The COPY IS ALWAYS A DRAFT with no schedule carried over: a duplicated event that kept its
     * dates would be published for a day that has already happened, and duplicating is precisely
     * what somebody does when the dates are the thing changing.
     */
    public function duplicate(Event $event, ActorContext $actor): Event
    {
        $copy = $event->replicate([
            'uuid', 'slug', 'status', 'published_at', 'cancelled_at', 'cancellation_reason',
            'created_at', 'updated_at',
        ]);

        $copy->forceFill([
            'uuid' => (string) Str::uuid7(),
            'slug' => $this->mintSlug((string) $event->title),
            'title' => $event->title.' (copy)',
            'status' => EventStatus::Draft,
            'author_subject_id' => $actor->subjectId,
            'published_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ])->save();

        $this->audit->record($actor->subjectId, 'event.duplicated', 'Event duplicated', (string) $copy->uuid);

        return $copy;
    }

    /**
     * THE DERIVED ANSWER. Computed on every read, stored nowhere.
     *
     * @param  int  $registeredCount  the live count, supplied by the caller so this stays a pure
     *                                function of its inputs and TAB 26 can pass a real number
     *                                without this module knowing how registrations work.
     */
    public function availabilityFor(Event $event, int $registeredCount = 0, ?Carbon $on = null): RegistrationAvailability
    {
        if (! $event->registration_required) {
            return RegistrationAvailability::NotRequired;
        }

        // A cancelled, completed or archived event accepts nobody, whatever its window says.
        if (! $event->status->acceptsRegistrations()) {
            return RegistrationAvailability::Closed;
        }

        $now = $on ?? Carbon::now();

        if ($event->registration_opens_at !== null && $now->lt($event->registration_opens_at)) {
            return RegistrationAvailability::NotOpen;
        }

        if ($event->registration_closes_at !== null && $now->gt($event->registration_closes_at)) {
            return RegistrationAvailability::Closed;
        }

        // The event itself has started. Registering for something already under way is a promise
        // the office cannot keep.
        if ($now->gt($event->starts_at)) {
            return RegistrationAvailability::Closed;
        }

        /*
         * `Full` is distinct from `Closed` because a waitlist may still accept — TAB 26 needs to
         * tell "there is no room" from "the window has passed", and collapsing them would make a
         * waitlist unimplementable without reintroducing the distinction elsewhere.
         */
        if ($event->capacity !== null && $registeredCount >= (int) $event->capacity) {
            return RegistrationAvailability::Full;
        }

        return RegistrationAvailability::Open;
    }

    /**
     * @return Builder<Event>
     */
    public function adminQuery(): Builder
    {
        return Event::query()->orderByDesc('starts_at')->orderByDesc('id');
    }

    /**
     * THE PUBLIC QUERY. Every citizen-facing read goes through this.
     *
     * Narrows on status **at the query**, so a draft is absent from a public lookup rather than
     * filtered out of one — which is what makes "a draft cannot be fetched via a citizen endpoint"
     * survive the next endpoint somebody adds (ADR 0028 §2, same rule).
     *
     * @return Builder<Event>
     */
    public function publicQuery(): Builder
    {
        return Event::query()
            ->whereIn('status', [
                EventStatus::Published->value,
                // Both stay visible. Somebody arranged their day around this.
                EventStatus::Cancelled->value,
                EventStatus::Completed->value,
            ])
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertTimesAreCoherent(array $attributes): void
    {
        $start = $this->asDate($attributes['starts_at'] ?? null);
        $end = $this->asDate($attributes['ends_at'] ?? null);

        // The acceptance criterion. An event that ends before it starts is a data-entry slip that
        // makes every duration, every calendar view and every reminder wrong.
        if ($start !== null && $end !== null && $end->lte($start)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'An event must end after it starts.');
        }

        $opens = $this->asDate($attributes['registration_opens_at'] ?? null);
        $closes = $this->asDate($attributes['registration_closes_at'] ?? null);

        if ($opens !== null && $closes !== null && $closes->lte($opens)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Registration must close after it opens.');
        }

        /*
         * Registration cannot close after the event starts.
         *
         * Not a technicality: a window that stays open into the event lets somebody register
         * while it is already running, and then arrive to find the room counted without them.
         */
        if ($closes !== null && $start !== null && $closes->gt($start)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Registration must close before the event starts.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertCoverHasAltText(array $attributes): void
    {
        $cover = $attributes['cover_file_id'] ?? null;

        if ($cover !== null && trim((string) ($attributes['cover_alt_text'] ?? '')) === '') {
            // An event poster a blind resident cannot read is an event they were not invited to.
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Describe the cover image for readers who cannot see it.',
            );
        }
    }

    private function asDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }

    /**
     * A readable, unique, immutable public identifier.
     *
     * The random suffix is what makes "feeding-programme" usable twelve times without a collision
     * and without anybody having to invent "feeding-programme-2". It is minted once: changing a
     * slug after publication breaks every link already handed out on a poster.
     */
    private function mintSlug(string $title): string
    {
        return Str::limit(Str::slug($title), 100, '').'-'.Str::lower(Str::random(6));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'title', 'summary', 'description', 'category', 'cover_file_id', 'cover_alt_text',
            'starts_at', 'ends_at', 'timezone', 'venue_name', 'venue_address', 'map_url',
            'contact_office', 'contact_person', 'contact_number',
            'registration_required', 'registration_opens_at', 'registration_closes_at',
            'capacity', 'waitlist_enabled', 'participation_note', 'participant_instructions',
        ]));
    }
}
