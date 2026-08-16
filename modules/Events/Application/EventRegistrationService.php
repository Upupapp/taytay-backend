<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Modules\Events\Contracts\EventRegistrationPromoted;
use Modules\Events\Domain\AttendanceState;
use Modules\Events\Domain\EventStatus;
use Modules\Events\Domain\RegistrationAvailability;
use Modules\Events\Domain\RegistrationStatus;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Events\Infrastructure\Eloquent\EventRegistration;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Registration, waitlist and attendance (ADR 0031).
 *
 * **EVERY DECISION ABOUT A SEAT IS MADE UNDER A ROW LOCK ON THE EVENT.** That single sentence is
 * the whole design, and it is what the acceptance criterion asks for.
 *
 * `SELECT ... FOR UPDATE` on the event row serialises registration for that event and nothing
 * else: two people registering for different events never wait on each other, and two people
 * registering for the same one are decided one after the other against **committed rows**. No
 * counter, no cached total, no number the client sent. The count is taken inside the lock, from
 * the table, every time.
 *
 * WHY NOT A COUNTER ON THE EVENT. It is the obvious optimisation and it is the bug. A counter and
 * the rows it counts are two sources of one fact, and they drift — a failed insert that
 * incremented, a cancel that forgot to decrement, a restore that decremented twice. When they
 * disagree the counter wins, because the counter is what the check reads, and the court is
 * oversold by four seats with nothing in the log.
 *
 * WHY NOT AN ADVISORY LOCK OR A REDIS MUTEX. Both work; neither is portable to the SQLite the
 * tests run on, and a concurrency control that is only exercised in production is one nobody has
 * ever seen fail.
 */
final class EventRegistrationService
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventsAudit $audit,
    ) {}

    /**
     * Registers a resident, or returns the registration they already hold.
     *
     * IDEMPOTENT WITHOUT AN IDEMPOTENCY KEY. `Shared\Application\IdempotencyService` protects the
     * endpoint for clients that opt in, but the acceptance criterion — *"retry does not duplicate
     * registration"* — must hold for a client that does not. It does, twice over:
     *
     *  * inside the lock, an existing live registration is **returned** rather than duplicated;
     *  * `uniq_event_registrations_active` makes a second live row impossible at the database.
     *
     * The second is what survives a code path nobody thought of.
     *
     * @return array{0: EventRegistration, 1: bool} the registration, and whether it already existed
     */
    public function register(Event $event, string $residentId, ActorContext $actor): array
    {
        return DB::transaction(function () use ($event, $residentId, $actor): array {
            $locked = $this->lock($event);

            /*
             * The retry answer, and it comes FIRST — before capacity, before the window. Somebody
             * whose seat is already held must get the same answer on the tenth attempt as on the
             * first, even if the event filled up in between.
             */
            $existing = $this->activeRegistration($locked, $residentId);

            if ($existing !== null) {
                return [$existing, true];
            }

            if (! $locked->registration_required) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'This event does not take registrations — just come along.',
                );
            }

            // Counted from committed rows, under the lock. Never from a column, never from the
            // client.
            $taken = $this->seatsTaken($locked);
            $availability = $this->events->availabilityFor($locked, $taken);

            $status = match ($availability) {
                RegistrationAvailability::Open => RegistrationStatus::Registered,
                RegistrationAvailability::Full => $locked->waitlist_enabled
                    ? RegistrationStatus::Waitlisted
                    // Full, no waitlist. The conflict carries the capacity state so a client can
                    // say something true instead of "try again".
                    : throw $this->fullConflict($locked, $taken),
                default => throw new ApiException(
                    ErrorCode::Conflict,
                    $availability->message(),
                    $this->capacityDetails($locked, $taken, $availability),
                ),
            };

            /** @var EventRegistration $registration */
            $registration = EventRegistration::query()->create([
                'event_id' => $locked->id,
                'resident_id' => $residentId,
                'account_subject_id' => $actor->subjectId,
                'reference' => $this->mintReference(),
                'status' => $status,
                'registered_at' => now(),
                'attendance' => AttendanceState::NotCheckedIn,
                // Telemetry. Article 3.3 — recorded, and it grants nothing.
                'source_channel' => $actor->channel->value,
                // Live, so it collides. See the migration.
                'active_key' => $residentId,
            ]);

            $this->audit->record(
                $actor->subjectId,
                'event.registered',
                'Registration '.$status->value,
                (string) $registration->uuid,
            );

            return [$registration, false];
        });
    }

    /**
     * The registrant withdraws.
     *
     * ALLOWED UNTIL THE EVENT STARTS, and not after. Cancelling afterwards would turn a no-show
     * into "never registered" — erasing exactly the record the office needs in order to size the
     * next one, and doing it at the request of the person it reflects on.
     */
    public function cancelOwn(EventRegistration $registration, ActorContext $actor): EventRegistration
    {
        $event = $this->eventFor($registration);

        if ($event->starts_at !== null && now()->gte($event->starts_at)) {
            throw new ApiException(
                ErrorCode::Conflict,
                'This event has already started, so a registration can no longer be withdrawn.',
            );
        }

        return $this->cancel($registration, $actor, 'Withdrawn by the registrant.', 'event.registration-withdrawn');
    }

    /**
     * Staff remove somebody from the list.
     */
    public function cancelAsStaff(
        EventRegistration $registration,
        ?string $reason,
        ActorContext $actor,
    ): EventRegistration {
        if (trim((string) $reason) === '') {
            // A seat taken away without a recorded reason is indistinguishable from a mistake to
            // the colleague who finds it, and from arbitrariness to the person it happened to.
            throw new ApiException(ErrorCode::ValidationFailed, 'Record why this registration is being cancelled.');
        }

        return $this->cancel($registration, $actor, (string) $reason, 'event.registration-cancelled');
    }

    /**
     * Puts a cancelled registration back.
     *
     * IT DOES NOT GET ITS OLD SEAT BACK AUTOMATICALLY. If the event filled while it was cancelled,
     * restoring goes to the waitlist — the people who registered in the meantime did nothing wrong
     * and are not displaced by an administrative undo.
     */
    public function restore(EventRegistration $registration, ActorContext $actor): EventRegistration
    {
        return DB::transaction(function () use ($registration, $actor): EventRegistration {
            $event = $this->lock($this->eventFor($registration));

            /** @var EventRegistration $registration */
            $registration = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);

            if ($registration->status !== RegistrationStatus::Cancelled) {
                throw new ApiException(ErrorCode::Conflict, 'That registration is not cancelled.');
            }

            /*
             * They may have simply registered again. Restoring on top of that would breach the
             * live-registration index — better to say so than to fail on a constraint.
             */
            if ($this->activeRegistration($event, (string) $registration->resident_id) !== null) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That person already holds a live registration for this event.',
                );
            }

            $taken = $this->seatsTaken($event);
            $hasRoom = $event->capacity === null || $taken < (int) $event->capacity;

            $status = $hasRoom ? RegistrationStatus::Registered : RegistrationStatus::Waitlisted;

            if (! $hasRoom && ! $event->waitlist_enabled) {
                throw $this->fullConflict($event, $taken);
            }

            $registration->forceFill([
                'status' => $status,
                'active_key' => $registration->resident_id,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'event.registration-restored',
                'Registration restored as '.$status->value,
                (string) $registration->uuid,
            );

            return $registration->refresh();
        });
    }

    /**
     * Fills freed seats from the waitlist, in order.
     *
     * DETERMINISTIC AND IDEMPOTENT. The order is `id` ascending — monotonic, maintenance-free, and
     * incapable of drifting from the order people actually joined, which a stored
     * `waitlist_position` does the first time somebody in the middle cancels.
     *
     * Idempotent because each promotion is a **conditional update** (`WHERE status = 'waitlisted'`)
     * inside the event lock: running this twice promotes nobody twice, and two callers racing
     * produce one update and one no-op. Being told twice that you got a seat is a smaller harm
     * than the other failure mode — two people promoted into one seat — but it is still a message
     * that cannot be unsent.
     *
     * Safe to call after anything that might have freed or created room. It is called after every
     * cancellation, and after a capacity change.
     *
     * @return list<EventRegistration>
     */
    public function promoteFromWaitlist(Event $event): array
    {
        /** @var list<EventRegistration> $promoted */
        $promoted = DB::transaction(function () use ($event): array {
            $locked = $this->lock($event);

            // Nobody is promoted into an event that is off, over or not yet announced.
            if (! $locked->status->acceptsRegistrations() || ! $locked->registration_required) {
                return [];
            }

            // An uncapped event cannot have a waitlist to promote from: nobody was ever refused a
            // seat. If rows exist anyway (capacity removed by an edit), everybody goes in.
            $room = $locked->capacity === null
                ? PHP_INT_MAX
                : (int) $locked->capacity - $this->seatsTaken($locked);

            if ($room <= 0) {
                return [];
            }

            /** @var list<EventRegistration> $queue */
            $queue = EventRegistration::query()
                ->where('event_id', $locked->id)
                ->where('status', RegistrationStatus::Waitlisted->value)
                ->orderBy('id')
                ->limit($room === PHP_INT_MAX ? 1000 : $room)
                ->get()
                ->all();

            $moved = [];

            foreach ($queue as $registration) {
                /*
                 * CONDITIONAL. If anything moved this row since the read, the update matches
                 * nothing and no promotion is announced — which is what stops a duplicate
                 * notification rather than merely making one unlikely.
                 */
                $updated = EventRegistration::query()
                    ->where('id', $registration->id)
                    ->where('status', RegistrationStatus::Waitlisted->value)
                    ->update([
                        'status' => RegistrationStatus::Registered->value,
                        'promoted_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated === 1) {
                    $moved[] = $registration->refresh();
                }
            }

            return $moved;
        });

        foreach ($promoted as $registration) {
            $this->audit->record(
                null,
                'event.registration-promoted',
                'Promoted from the waitlist',
                (string) $registration->uuid,
            );

            /*
             * Announced, never delivered from here. Events decides a seat opened; whether anybody
             * is told is Notification's business, and a provider outage must not be able to roll
             * back a promotion (ADR 0025 §1).
             */
            EventBus::dispatch(new EventRegistrationPromoted(
                (string) $registration->uuid,
                (string) $event->uuid,
                (string) $registration->resident_id,
                (string) $event->title,
                (string) $event->starts_at?->toIso8601ZuluString(),
            ));
        }

        return $promoted;
    }

    /**
     * Records whether somebody turned up.
     */
    public function markAttendance(
        EventRegistration $registration,
        AttendanceState $state,
        ActorContext $actor,
    ): EventRegistration {
        $event = $this->eventFor($registration);

        if (! in_array($event->status, [EventStatus::Published, EventStatus::Completed], true)) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Attendance can only be recorded for an event that is running or has run.',
            );
        }

        /*
         * A WAITLISTED PERSON CANNOT BE MARKED PRESENT. It reads like unhelpful rigidity and it is
         * not: recording attendance for somebody who never held a seat puts the attendance list
         * above capacity, and every later count — how many came, how much food was needed —
         * silently disagrees with how many were let in. If the door admits somebody from the
         * queue, promote them; that is one call, and it leaves a record of the seat opening.
         */
        if ($registration->status !== RegistrationStatus::Registered) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only somebody holding a seat can be marked. Promote them from the waitlist first.',
            );
        }

        $previous = $registration->attendance;

        $registration->forceFill([
            'attendance' => $state,
            'attendance_marked_at' => now(),
            'attendance_marked_by' => $actor->subjectId,
        ])->save();

        /*
         * The history the master command asks for lives in the append-only audit trail rather than
         * in a table of its own. Both the previous and the new value are recorded, because the
         * question afterwards is "who changed this from attended to no-show, and when" — and a
         * trail holding only the new value cannot answer it.
         */
        $this->audit->record(
            $actor->subjectId,
            'event.attendance-marked',
            sprintf('Attendance %s → %s', $previous->value, $state->value),
            (string) $registration->uuid,
        );

        return $registration->refresh();
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────

    /**
     * The office's view of one event's list.
     *
     * @return Builder<EventRegistration>
     */
    public function registrationsFor(Event $event): Builder
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            // Seated first, then the queue in order, then the withdrawn — which is the order the
            // list is read in at a door.
            ->orderByRaw("CASE status WHEN 'registered' THEN 0 WHEN 'waitlisted' THEN 1 ELSE 2 END")
            ->orderBy('id');
    }

    /**
     * One person's registrations, and only theirs.
     *
     * SCOPED AT THE QUERY. *"A citizen cannot access another resident's registration by changing
     * the ID"* is held by there being no unscoped citizen read — a `where uuid = ?` on top of this
     * returns nothing for somebody else's registration, so the answer is a `404` produced by
     * absence rather than by a check that could be omitted.
     *
     * @return Builder<EventRegistration>
     */
    public function registrationsForResident(string $residentId): Builder
    {
        return EventRegistration::query()
            ->where('resident_id', $residentId)
            ->orderByDesc('id');
    }

    /**
     * Seats taken, counted from committed rows.
     */
    public function seatsTaken(Event $event): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->count();
    }

    public function waitlistLength(Event $event): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Waitlisted->value)
            ->count();
    }

    /**
     * The live capacity picture, as the console and the conflict body both show it.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Event $event): array
    {
        $taken = $this->seatsTaken($event);

        return [
            'registration_required' => (bool) $event->registration_required,
            'capacity' => $event->capacity === null ? null : (int) $event->capacity,
            'waitlist_enabled' => (bool) $event->waitlist_enabled,
            'opens_at' => $event->registration_opens_at?->toIso8601ZuluString(),
            'closes_at' => $event->registration_closes_at?->toIso8601ZuluString(),
            'availability' => $this->events->availabilityFor($event, $taken)->value,
            'registered_count' => $taken,
            'waitlisted_count' => $this->waitlistLength($event),
            'seats_remaining' => $event->capacity === null ? null : max(0, (int) $event->capacity - $taken),
            'attendance' => [
                'attended' => $this->attendanceCount($event, AttendanceState::Attended),
                'no_show' => $this->attendanceCount($event, AttendanceState::NoShow),
                'not_checked_in' => $this->attendanceCount($event, AttendanceState::NotCheckedIn),
            ],
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────────────────

    private function cancel(
        EventRegistration $registration,
        ActorContext $actor,
        string $reason,
        string $auditAction,
    ): EventRegistration {
        $event = $this->eventFor($registration);

        $cancelled = DB::transaction(function () use ($registration, $actor, $reason, $auditAction, $event) {
            $this->lock($event);

            /** @var EventRegistration $registration */
            $registration = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);

            if ($registration->status === RegistrationStatus::Cancelled) {
                // Already gone. Idempotent rather than a conflict: a second tap on "cancel" is
                // not a client bug, and answering it with an error teaches people to ignore
                // errors.
                return $registration;
            }

            if ($registration->attendance !== AttendanceState::NotCheckedIn) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'Attendance has been recorded for this registration, so it can no longer be cancelled.',
                );
            }

            $registration->forceFill([
                'status' => RegistrationStatus::Cancelled,
                // NULLed, which is what frees the person to register again and frees the unique
                // index to let them.
                'active_key' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->subjectId,
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record($actor->subjectId, $auditAction, $reason, (string) $registration->uuid);

            return $registration->refresh();
        });

        /*
         * OUTSIDE the cancelling transaction, so a promotion notification is never sent for a
         * cancellation that rolled back. Promotion takes its own lock and its own transaction.
         */
        $this->promoteFromWaitlist($event);

        return $cancelled;
    }

    /**
     * `SELECT ... FOR UPDATE` on the event row. Everything about seats happens behind this.
     */
    private function lock(Event $event): Event
    {
        /** @var Event $locked */
        $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

        return $locked;
    }

    private function activeRegistration(Event $event, string $residentId): ?EventRegistration
    {
        /** @var EventRegistration|null $registration */
        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('active_key', $residentId)
            ->first();

        return $registration;
    }

    private function attendanceCount(Event $event, AttendanceState $state): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->where('attendance', $state->value)
            ->count();
    }

    private function fullConflict(Event $event, int $taken): ApiException
    {
        return new ApiException(
            ErrorCode::Conflict,
            'This event is full and has no waiting list.',
            $this->capacityDetails($event, $taken, RegistrationAvailability::Full),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function capacityDetails(Event $event, int $taken, RegistrationAvailability $availability): array
    {
        return [
            'availability' => $availability->value,
            'capacity' => $event->capacity === null ? null : (int) $event->capacity,
            'registered_count' => $taken,
            'waitlist_enabled' => (bool) $event->waitlist_enabled,
        ];
    }

    private function eventFor(EventRegistration $registration): Event
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($registration->event_id);

        return $event;
    }

    /**
     * A short, human-transcribable handle.
     *
     * Read out from a phone screen to a volunteer with a clipboard, which a UUID is not. Ambiguous
     * characters are excluded from the alphabet: somebody reading `0` aloud as "oh" at a noisy
     * covered court is not a hypothetical.
     */
    private function mintReference(): string
    {
        do {
            $reference = 'EVT-'.Str::upper(Str::password(8, symbols: false, numbers: true, letters: true));
            $reference = str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $reference);
        } while (EventRegistration::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
