<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Events\Application\EventRegistrationService;
use Modules\Events\Contracts\EventRegistrationPromoted;
use Modules\Events\Domain\RegistrationStatus;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Events\Infrastructure\Eloquent\EventRegistration;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 26, as tests.
 *
 *  1. **Concurrent registrations cannot exceed capacity according to committed backend state.**
 *  2. **Retry does not duplicate registration.**
 *  3. **A citizen cannot access another resident's registration by changing the ID.**
 */
final class EventRegistrationTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: capacity is never exceeded ──────────────────────────────────────

    #[Test]
    public function capacity_is_never_exceeded_however_many_people_try(): void
    {
        $event = $this->publishedEvent(['capacity' => 3, 'waitlist_enabled' => false]);

        $outcomes = [];

        for ($i = 0; $i < 8; $i++) {
            [$citizen] = $this->activeCitizenWithResident();
            Sanctum::actingAs($citizen);
            $outcomes[] = $this->postJson("/api/v1/events/{$event}/registration")->status();
        }

        /*
         * THE COMMITTED STATE IS THE ANSWER, not the response codes. Three seats exist, three rows
         * hold one, and the count is taken from the table rather than from a counter that could
         * have drifted.
         */
        $this->assertSame(3, EventRegistration::query()->where('status', 'registered')->count());
        $this->assertSame(3, count(array_filter($outcomes, static fn (int $s): bool => $s === 201)));
        $this->assertSame(5, count(array_filter($outcomes, static fn (int $s): bool => $s === 409)));
    }

    #[Test]
    public function the_full_conflict_carries_the_capacity_state(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => false]);

        $this->registerAsNewCitizen($event);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        // So a client can say something true instead of "please try again".
        $body = $this->postJson("/api/v1/events/{$event}/registration")->assertStatus(409)->json('error.details');

        $this->assertSame('full', $body['availability']);
        $this->assertSame(1, $body['capacity']);
        $this->assertSame(1, $body['registered_count']);
        $this->assertFalse($body['waitlist_enabled']);
    }

    #[Test]
    public function overflow_goes_to_the_waitlist_when_one_is_enabled(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $this->registerAsNewCitizen($event);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $body = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data');

        // `waitlisted`, not a refusal: `full` and `closed` are different states precisely so a
        // waitlist can accept in the first case (ADR 0030 §2).
        $this->assertSame('waitlisted', $body['status']);
    }

    #[Test]
    public function an_uncapped_event_takes_everybody(): void
    {
        $event = $this->publishedEvent(['capacity' => null]);

        for ($i = 0; $i < 4; $i++) {
            $this->registerAsNewCitizen($event);
        }

        // Null capacity means uncapped, which is not the same as zero.
        $this->assertSame(4, EventRegistration::query()->where('status', 'registered')->count());
    }

    #[Test]
    public function a_capacity_of_zero_admits_nobody(): void
    {
        $event = $this->publishedEvent(['capacity' => 0, 'waitlist_enabled' => false]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        // Somebody will eventually mean it — a placeholder while a venue is confirmed. Collapsing
        // it into "uncapped" is the direction that fills a room.
        $this->postJson("/api/v1/events/{$event}/registration")->assertStatus(409);
    }

    #[Test]
    public function there_is_no_registered_count_column_to_drift(): void
    {
        /*
         * An enforced ABSENCE. A counter and the rows it counts are two sources of one fact, and
         * when they disagree the counter wins — because the counter is what the capacity check
         * reads — and the court is oversold with nothing in the log (ADR 0031 §1).
         */
        foreach (['registered_count', 'registrations_count', 'seats_taken'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('events', $column),
                "Seats must be counted from committed rows; `{$column}` would be a second source that drifts.",
            );
        }
    }

    #[Test]
    public function every_seat_decision_is_taken_behind_a_row_lock(): void
    {
        /*
         * AN HONEST TEST FOR A GUARANTEE THIS SUITE CANNOT EXERCISE.
         *
         * The criterion is about *concurrent* registrations, and the test database is an
         * in-process SQLite whose grammar compiles `lockForUpdate()` to an empty string. Nothing
         * runs in parallel here, so `capacity_is_never_exceeded_however_many_people_try` above
         * proves the arithmetic and not the race.
         *
         * What actually holds the race is `SELECT ... FOR UPDATE` on the event row, and what would
         * break it is somebody deleting that line while every test stayed green — the failure
         * would then appear only under production load, as a covered court oversold by four seats.
         *
         * So the mechanism is asserted directly. It is a weaker test than a real concurrent one
         * and it is stated as such; it is also the difference between a guarantee that is written
         * down and one that is merely believed.
         */
        $source = (string) file_get_contents(
            base_path('modules/Events/Application/EventRegistrationService.php'),
        );

        $this->assertStringContainsString(
            'lockForUpdate()',
            $source,
            'Seat decisions must be serialised by a row lock on the event. Without it, two '.
            'concurrent registrations both read the same count and both commit.',
        );

        foreach (['register', 'restore', 'promoteFromWaitlist', 'cancel'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function '.$method.'\(.*?\$this->lock\(/s',
                $source,
                "[{$method}] decides something about a seat and must do it behind the event lock.",
            );
        }
    }

    // ── criterion 2: a retry does not duplicate ──────────────────────────────────────

    #[Test]
    public function retrying_without_an_idempotency_key_returns_the_same_registration(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $first = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data');

        /*
         * NO KEY WAS SENT. The criterion must hold for a client that does not opt in, and it does:
         * the service returns the place already held, and `uniq_event_registrations_active` makes
         * a second live row impossible even if it did not.
         */
        $second = $this->postJson("/api/v1/events/{$event}/registration")->assertOk()->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, EventRegistration::query()->count());
    }

    #[Test]
    public function retrying_with_an_idempotency_key_replays_the_original_response(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $headers = ['Idempotency-Key' => 'evt-retry-0001'];

        $first = $this->postJson("/api/v1/events/{$event}/registration", [], $headers)->assertCreated();

        // Replayed verbatim, 201 and all — the caller cannot tell it from the original, which is
        // the point of an idempotency key.
        $second = $this->postJson("/api/v1/events/{$event}/registration", [], $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, EventRegistration::query()->count());
    }

    #[Test]
    public function a_second_live_registration_is_impossible_at_the_database(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);
        [$citizen, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        /*
         * The backstop, tested directly. Every guard above lives in code somebody could route
         * around; this one does not, and it is what makes the criterion survive a code path
         * nobody thought of.
         */
        $this->expectException(QueryException::class);

        EventRegistration::query()->create([
            'event_id' => Event::query()->where('uuid', $event)->value('id'),
            'resident_id' => $resident->uuid,
            'reference' => 'EVT-DUPE0001',
            'status' => RegistrationStatus::Registered,
            'registered_at' => now(),
            'active_key' => $resident->uuid,
        ]);
    }

    #[Test]
    public function re_registering_after_withdrawing_is_allowed(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $first = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/events/{$event}/registration")->assertOk();

        /*
         * A cancelled row carries a NULL `active_key`, and NULLs are distinct in a unique index on
         * both Postgres and SQLite — which is what lets somebody change their mind twice without
         * the constraint that stops them holding two places at once getting in the way.
         */
        $second = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, EventRegistration::query()->count());
    }

    // ── criterion 3: nobody reads anybody else's registration ────────────────────────

    #[Test]
    public function a_citizen_cannot_read_another_residents_registration(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$owner] = $this->activeCitizenWithResident();
        Sanctum::actingAs($owner);
        $body = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data');

        [$stranger] = $this->activeCitizenWithResident();
        Sanctum::actingAs($stranger);

        /*
         * `404`, not `403`, and produced by ABSENCE rather than by a check: the lookup runs
         * against a query already scoped to the caller's resident, so the row is not there.
         * Answering `403` would confirm the id names a real registration, which is most of what
         * an enumeration attempt wants (OWASP API1).
         */
        $this->getJson("/api/v1/me/event-registrations/{$body['id']}")->assertNotFound();
        // The reference is short and human-readable, so it is the guessable handle — and it is
        // refused the same way.
        $this->getJson("/api/v1/me/event-registrations/{$body['reference']}")->assertNotFound();

        $this->assertCount(0, $this->getJson('/api/v1/me/event-registrations')->assertOk()->json('data'));
    }

    #[Test]
    public function a_citizen_cannot_withdraw_another_residents_registration(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$owner] = $this->activeCitizenWithResident();
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        [$stranger] = $this->activeCitizenWithResident();
        Sanctum::actingAs($stranger);

        // There is no id to tamper with: withdrawal resolves the registration from the token, so
        // the only registration reachable is the caller's own.
        $this->deleteJson("/api/v1/events/{$event}/registration")->assertNotFound();

        $this->assertSame(1, EventRegistration::query()->where('status', 'registered')->count());
    }

    #[Test]
    public function a_citizen_never_sees_the_staff_note_on_their_own_registration(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);
        $id = $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data.id');

        EventRegistration::query()->where('uuid', $id)->update([
            'staff_notes' => 'Turned up drunk last time.',
        ]);

        $response = $this->getJson("/api/v1/me/event-registrations/{$id}")->assertOk();

        /*
         * The citizen projection is its own method rather than the staff one with fields removed,
         * so a column added to the office's view does not silently arrive here — and a remark
         * written about somebody in the office's voice is not something they read about themselves
         * in an app.
         */
        $this->assertArrayNotHasKey('staff_notes', $response->json('data'));
        $this->assertStringNotContainsString('drunk', $response->content());
    }

    #[Test]
    public function a_resident_cannot_reach_the_staff_registration_endpoints(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);
        $registration = $this->registerAsNewCitizen($event);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson("/api/v1/admin/events/{$event}/registrations")->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/registrations/promote")->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/cancel", ['reason' => 'x'])
            ->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/restore")->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/attendance", [
            'attendance' => 'attended',
        ])->assertForbidden();
    }

    // ── the waitlist ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_cancellation_promotes_the_earliest_waitlisted_person(): void
    {
        EventBus::fake([EventRegistrationPromoted::class]);

        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $seated = $this->registerAsNewCitizen($event);
        $firstInLine = $this->registerAsNewCitizen($event);
        $secondInLine = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$seated}/cancel", [
            'reason' => 'Withdrew at the counter.',
        ])->assertOk();

        /*
         * DETERMINISTIC: `id` ascending. Not a stored `waitlist_position`, which drifts from the
         * order people actually joined the first time somebody in the middle cancels.
         */
        $this->assertSame('registered', $this->statusOf($firstInLine));
        $this->assertSame('waitlisted', $this->statusOf($secondInLine));

        // And the person is told — announced by Events, delivered by Notification, which is the
        // inversion that stops a push outage rolling back a promotion.
        EventBus::assertDispatched(EventRegistrationPromoted::class, 1);
    }

    #[Test]
    public function promotion_runs_at_most_once_per_seat(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $this->registerAsNewCitizen($event);
        $waiting = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());

        $service = app(EventRegistrationService::class);
        $model = Event::query()->where('uuid', $event)->firstOrFail();

        // No seat is free, so nobody moves however often this is called.
        $this->assertCount(0, $service->promoteFromWaitlist($model));
        $this->assertCount(0, $service->promoteFromWaitlist($model));
        $this->assertSame('waitlisted', $this->statusOf($waiting));

        EventRegistration::query()->where('status', 'registered')->update([
            'status' => 'cancelled',
            'active_key' => null,
        ]);

        /*
         * One seat, one promotion, and the SECOND CALL DOES NOTHING. The conditional update is
         * what makes that true rather than likely: a row that already moved matches no `WHERE`,
         * so no second promotion is announced and nobody is told twice that they got in.
         */
        $this->assertCount(1, $service->promoteFromWaitlist($model));
        $this->assertCount(0, $service->promoteFromWaitlist($model));
        $this->assertSame('registered', $this->statusOf($waiting));
    }

    #[Test]
    public function raising_the_capacity_works_the_waitlist(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $this->registerAsNewCitizen($event);
        $waiting = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());

        /*
         * The other way room appears, and the one that is easy to forget. An office that moves to
         * a bigger hall and adds seats would otherwise leave people waiting for a seat that
         * already exists.
         */
        $this->patchJson("/api/v1/admin/events/{$event}", ['capacity' => 20])->assertOk();

        $this->assertSame('registered', $this->statusOf($waiting));
    }

    #[Test]
    public function nobody_is_promoted_into_a_cancelled_event(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $seated = $this->registerAsNewCitizen($event);
        $waiting = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Typhoon signal 2.',
        ])->assertOk();

        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$seated}/cancel", [
            'reason' => 'Event is off.',
        ])->assertOk();

        // Telling somebody they got a place at an event that is not happening is worse than
        // telling them nothing.
        $this->assertSame('waitlisted', $this->statusOf($waiting));
    }

    // ── withdrawal policy ────────────────────────────────────────────────────────────

    #[Test]
    public function a_registrant_cannot_withdraw_once_the_event_has_started(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        Event::query()->where('uuid', $event)->update([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        /*
         * Withdrawing afterwards would turn a no-show into "never registered" — erasing exactly
         * the record the office needs in order to size the next one, at the request of the person
         * it reflects on.
         */
        $this->deleteJson("/api/v1/events/{$event}/registration")->assertStatus(409);
    }

    #[Test]
    public function a_second_withdrawal_is_not_an_error(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        $this->deleteJson("/api/v1/events/{$event}/registration")->assertOk();

        // Already gone. There is nothing left to withdraw, so the honest answer is that the
        // caller holds no place — not a conflict that teaches people to ignore errors.
        $this->deleteJson("/api/v1/events/{$event}/registration")->assertNotFound();
    }

    // ── restore ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_restore_does_not_displace_whoever_took_the_seat(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $original = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$original}/cancel", [
            'reason' => 'Cancelled in error.',
        ])->assertOk();

        $replacement = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());
        $restored = $this->postJson("/api/v1/admin/events/{$event}/registrations/{$original}/restore")
            ->assertOk()->json('data');

        /*
         * The person who registered in the meantime did nothing wrong and is not displaced by an
         * administrative undo. The restored registration joins the queue.
         */
        $this->assertSame('waitlisted', $restored['status']);
        $this->assertSame('registered', $this->statusOf($replacement));
    }

    // ── attendance ───────────────────────────────────────────────────────────────────

    #[Test]
    public function attendance_is_marked_by_the_door_and_audited_with_both_values(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);
        $registration = $this->registerAsNewCitizen($event);

        // Front-line staff are the door, and marking somebody in does not cost a publishing
        // permission — sharing one at a covered court is how it gets shared for good.
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/attendance", [
            'attendance' => 'attended',
        ])->assertOk();

        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/attendance", [
            'attendance' => 'no-show',
        ])->assertOk();

        /*
         * BOTH VALUES IN THE TRAIL. "Who changed this from attended to no-show, and when" is the
         * question afterwards, and a trail holding only the new value cannot answer it.
         */
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'event.attendance-marked',
            'entity_id' => $registration,
            'summary' => 'Attendance attended → no-show',
            'actor_subject_id' => (string) $staff->uuid,
        ]);
    }

    #[Test]
    public function a_waitlisted_person_cannot_be_marked_present(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $this->registerAsNewCitizen($event);
        $waiting = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->staff());

        /*
         * It reads as rigidity and it is not: recording attendance for somebody who never held a
         * seat puts the attendance list above capacity, and every later count silently disagrees
         * with how many were let in.
         */
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$waiting}/attendance", [
            'attendance' => 'attended',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_marked_registration_can_no_longer_be_cancelled(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);
        $registration = $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/attendance", [
            'attendance' => 'attended',
        ])->assertOk();

        Sanctum::actingAs($this->admin());

        // Somebody came. Cancelling the record of it would leave an attendance list that does not
        // match the room.
        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registration}/cancel", [
            'reason' => 'Tidying up.',
        ])->assertStatus(409);
    }

    #[Test]
    public function attendance_defaults_to_not_checked_in_rather_than_no_show(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);
        $registration = $this->registerAsNewCitizen($event);

        /*
         * "We have not marked this person" is the truth before the door opens. Defaulting to
         * `no-show` would record every registrant at an event nobody checked in as having failed
         * to attend — and a no-show record quietly shapes who gets a seat next time.
         */
        $this->assertSame('not-checked-in', EventRegistration::query()->where('uuid', $registration)
            ->value('attendance')->value);
    }

    // ── staff visibility ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_staff_list_carries_live_counts_and_a_name(): void
    {
        $event = $this->publishedEvent(['capacity' => 1, 'waitlist_enabled' => true]);

        $this->registerAsNewCitizen($event);
        $this->registerAsNewCitizen($event);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson("/api/v1/admin/events/{$event}/registrations")->assertOk();

        $this->assertSame(1, $response->json('meta.registered_count'));
        $this->assertSame(1, $response->json('meta.waitlisted_count'));
        $this->assertSame(0, $response->json('meta.seats_remaining'));

        // A door list needs the name and not the address, the contact number or a vulnerability
        // factor — `ResidentSummary` is the published minimum precisely so this cannot creep.
        $this->assertNotNull($response->json('data.0.resident_name'));

        $summary = $this->getJson("/api/v1/admin/events/{$event}/registration-summary")->assertOk()->json('data');
        $this->assertSame(1, $summary['registered_count']);
        $this->assertSame('full', $summary['availability']);
    }

    // ── registration is not a way in ─────────────────────────────────────────────────

    #[Test]
    public function nobody_can_register_for_a_draft(): void
    {
        Sanctum::actingAs($this->admin());
        $draft = $this->postJson('/api/v1/admin/events', $this->payload(['capacity' => 10]))
            ->assertCreated()->json('data.id');

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * Registration resolves the event through the PUBLIC query, so a draft is absent here for
         * the same reason it is absent from the list — which also means registering cannot be used
         * to discover one, or to occupy seats before an event is announced.
         */
        $this->postJson("/api/v1/events/{$draft}/registration")->assertNotFound();
    }

    #[Test]
    public function an_event_that_takes_no_registrations_says_so(): void
    {
        $event = $this->publishedEvent(['registration_required' => false]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/v1/events/{$event}/registration")->assertStatus(409);
    }

    #[Test]
    public function an_account_with_no_resident_record_cannot_register(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        // A place is held by a PERSON, checked against a list at a door. A registration with no
        // resident behind it is a name nobody can verify.
        Sanctum::actingAs($this->citizen());

        $this->postJson("/api/v1/events/{$event}/registration")->assertNotFound();
    }

    #[Test]
    public function anonymous_readers_can_see_an_event_but_not_take_a_seat(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        $this->app['auth']->forgetGuards();

        // Reading a poster is public; taking one of a fixed number of seats is a claim on a
        // scarce public resource, and an anonymous one is unaccountable and repeatable.
        $this->getJson("/api/v1/events/{$event}")->assertOk();
        $this->postJson("/api/v1/events/{$event}/registration")->assertUnauthorized();
    }

    // ── a merge does not strand a registration ───────────────────────────────────────

    #[Test]
    public function a_merge_repoints_registrations_and_keeps_the_earlier_place(): void
    {
        $event = $this->publishedEvent(['capacity' => 10]);

        [, $survivor] = $this->activeCitizenWithResident();
        [, $absorbed] = $this->activeCitizenWithResident();

        $eventId = Event::query()->where('uuid', $event)->value('id');

        // The absorbed record registered FIRST — the same person signing up twice under two files,
        // which is exactly what a duplicate resident looks like.
        $earlier = $this->rawRegistration($eventId, (string) $absorbed->uuid, 'EVT-EARLY001');
        $later = $this->rawRegistration($eventId, (string) $survivor->uuid, 'EVT-LATER001');

        EventBus::dispatch(new ResidentMerged((string) $survivor->uuid, (string) $absorbed->uuid));

        /*
         * THE EARLIER PLACE SURVIVES, not the survivor record's. A queue position a person earned
         * belongs to the person, not to whichever of their two files the office happened to keep —
         * demoting somebody because an administrator merged their record is a real harm from an
         * invisible cause.
         */
        $this->assertSame('registered', $this->statusOf($earlier));
        $this->assertSame('cancelled', $this->statusOf($later));

        // And nothing points at the soft-deleted record any more.
        $this->assertSame(0, EventRegistration::query()->where('resident_id', $absorbed->uuid)->count());
        $this->assertSame(2, EventRegistration::query()->where('resident_id', $survivor->uuid)->count());
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding for children aged 2 to 5.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedEvent(array $overrides = []): string
    {
        Sanctum::actingAs($this->admin());

        $event = $this->postJson('/api/v1/admin/events', $this->payload($overrides))
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        return $event;
    }

    /**
     * Registers a brand-new resident and returns the registration id.
     */
    private function registerAsNewCitizen(string $event): string
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        return $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data.id');
    }

    /**
     * A registration written straight to the table, for the cases the API deliberately refuses.
     */
    private function rawRegistration(mixed $eventId, string $residentUuid, string $reference): string
    {
        /** @var EventRegistration $registration */
        $registration = EventRegistration::query()->create([
            'event_id' => $eventId,
            'resident_id' => $residentUuid,
            'reference' => $reference,
            'status' => RegistrationStatus::Registered,
            'registered_at' => now(),
            'active_key' => $residentUuid,
        ]);

        return (string) $registration->uuid;
    }

    private function statusOf(string $registrationUuid): string
    {
        return EventRegistration::query()->where('uuid', $registrationUuid)->value('status')->value;
    }
}
