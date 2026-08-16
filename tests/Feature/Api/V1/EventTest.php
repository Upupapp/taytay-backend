<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 25, as tests.
 *
 *  1. **A resident cannot create or edit events.**
 *  2. **End time validates after start time.**
 *  3. **A draft event cannot be fetched via a citizen endpoint.**
 */
final class EventTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: residents read, and only read ───────────────────────────────────

    #[Test]
    public function a_resident_cannot_reach_any_staff_event_endpoint(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/v1/admin/events')->assertForbidden();
        $this->postJson('/api/v1/admin/events', $this->payload())->assertForbidden();
        $this->getJson("/api/v1/admin/events/{$event}")->assertForbidden();
        $this->patchJson("/api/v1/admin/events/{$event}", ['title' => 'Mine now'])->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'cancelled', 'reason' => 'x'])
            ->assertForbidden();
        $this->postJson("/api/v1/admin/events/{$event}/duplicate")->assertForbidden();
        $this->getJson("/api/v1/admin/events/{$event}/registration-summary")->assertForbidden();

        // Nothing a resident did reached the record.
        $this->assertSame('published', Event::query()->where('uuid', $event)->value('status')->value);
    }

    #[Test]
    public function drafting_and_publishing_are_different_permissions(): void
    {
        Sanctum::actingAs($this->staff());

        // Front-line staff run the feeding programme, so they draft the event for it.
        $event = $this->draft();
        $this->patchJson("/api/v1/admin/events/{$event}", ['venue_name' => 'Dolores covered court'])->assertOk();

        /*
         * Publishing is not theirs, and neither is cancelling — an event called off by mistake
         * sends people home from a court they travelled to.
         */
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Typhoon signal 2.',
        ])->assertForbidden();
    }

    // ── criterion 2: an event ends after it starts ───────────────────────────────────

    #[Test]
    public function an_event_must_end_after_it_starts(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/events', $this->payload([
            'starts_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->toIso8601ZuluString(),
        ]))->assertStatus(422);

        // Zero-length is refused too: an event that ends at the instant it begins is a slip, and
        // every duration, calendar view and reminder computed from it would be wrong.
        $at = now()->addWeek()->toIso8601ZuluString();
        $this->postJson('/api/v1/admin/events', $this->payload(['starts_at' => $at, 'ends_at' => $at]))
            ->assertStatus(422);
    }

    #[Test]
    public function a_partial_update_cannot_invert_the_schedule(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->draft();

        /*
         * The check runs against the MERGED values, not against the request body. Validating only
         * what was sent is how a two-field rule gets bypassed by sending one field.
         */
        $this->patchJson("/api/v1/admin/events/{$event}", [
            'ends_at' => now()->addDay()->toIso8601ZuluString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function registration_must_close_before_the_event_starts(): void
    {
        Sanctum::actingAs($this->admin());

        /*
         * Not a technicality. A window that stays open into the event lets somebody register while
         * it is already running, and then arrive to find the room counted without them.
         */
        $this->postJson('/api/v1/admin/events', $this->payload([
            'registration_required' => true,
            'registration_opens_at' => now()->toIso8601ZuluString(),
            'registration_closes_at' => now()->addWeek()->addHour()->toIso8601ZuluString(),
        ]))->assertStatus(422);
    }

    // ── criterion 3: a draft is not public ───────────────────────────────────────────

    #[Test]
    public function a_draft_is_absent_from_the_public_list_and_unreachable_by_id(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->draft(['title' => 'Unannounced distribution']);
        $slug = (string) Event::query()->where('uuid', $event)->value('slug');

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->assertCount(0, $this->getJson('/api/v1/events')->assertOk()->json('data'));

        /*
         * The lookup runs against the public query, so a draft is simply NOT THERE — no status
         * check follows it. Both handles are refused, because a slug is guessable in a way a UUID
         * is not: "feeding-programme" is what somebody would try.
         */
        $this->getJson("/api/v1/events/{$event}")->assertNotFound();
        $this->getJson("/api/v1/events/{$slug}")->assertNotFound();
    }

    #[Test]
    public function an_archived_event_leaves_the_public_list(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'archived'])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson("/api/v1/events/{$event}")->assertNotFound();
    }

    // ── cancellation stays visible ───────────────────────────────────────────────────

    #[Test]
    public function a_cancelled_event_stays_visible_with_its_reason(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Suspended due to typhoon signal number 2.',
        ])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * THE POINT OF THE WHOLE STATE. Removing a cancelled event silently means somebody who
         * arranged their day around it travels to a covered court to find nobody there. It stays,
         * and it says why.
         */
        $body = $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data');

        $this->assertTrue($body['is_cancelled']);
        $this->assertSame('Suspended due to typhoon signal number 2.', $body['cancellation_reason']);
    }

    #[Test]
    public function a_cancellation_must_say_why(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'cancelled'])->assertStatus(422);
    }

    #[Test]
    public function a_cancelled_event_is_not_un_cancelled(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Venue unavailable.',
        ])->assertOk();

        /*
         * People were told it was off. Telling them it is back on is a new announcement, not a
         * status change — a resurrected event would silently reappear on the calendar of everybody
         * who had already crossed it out.
         */
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertStatus(409);
    }

    // ── availability is derived, never stored ────────────────────────────────────────

    #[Test]
    public function there_is_no_stored_availability_column(): void
    {
        /*
         * An enforced ABSENCE (ADR 0030 §2). A stored answer is wrong the moment the clock moves
         * past it, and whatever job was meant to rewrite it will one day not run. If somebody adds
         * the column, this fails and they read the reason.
         */
        foreach (['registration_availability', 'is_registration_open', 'registration_status'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('events', $column),
                "Availability must stay derived; `{$column}` would be a second source that can disagree with the clock.",
            );
        }
    }

    #[Test]
    public function availability_closes_when_the_clock_passes_the_window(): void
    {
        Sanctum::actingAs($this->admin());

        $event = $this->publishedEvent([
            'registration_required' => true,
            'registration_opens_at' => now()->addDay()->toIso8601ZuluString(),
            'registration_closes_at' => now()->addDays(5)->toIso8601ZuluString(),
        ]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        // Before the window opens.
        $this->assertSame(
            'not-open',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data.registration.availability'),
        );

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(
            'open',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data.registration.availability'),
        );

        /*
         * NOTHING RAN IN BETWEEN. No job, no cron, no write — only the clock moved, and the answer
         * changed with it. That is the whole argument for deriving it.
         */
        Carbon::setTestNow(now()->addDays(4));
        $this->assertSame(
            'closed',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data.registration.availability'),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function an_event_without_registration_says_so_rather_than_closed(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * "Not required" and "closed" mean opposite things to somebody deciding whether to turn
         * up. Collapsing them would tell a resident that a walk-in event is shut.
         */
        $this->assertSame(
            'not-required',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data.registration.availability'),
        );
    }

    #[Test]
    public function a_cancelled_event_accepts_nobody_whatever_its_window_says(): void
    {
        Sanctum::actingAs($this->admin());

        $event = $this->publishedEvent([
            'registration_required' => true,
            'registration_closes_at' => now()->addDays(5)->toIso8601ZuluString(),
        ]);

        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Venue flooded.',
        ])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        // The window is still open on paper. The event is off, so registration is closed.
        $this->assertSame(
            'closed',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data.registration.availability'),
        );
    }

    // ── accessibility ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_cover_image_needs_alt_text(): void
    {
        Sanctum::actingAs($this->admin());

        // An event poster a blind resident cannot read is an event they were not invited to.
        $this->postJson('/api/v1/admin/events', $this->payload([
            'cover_file_id' => (string) Str::uuid7(),
        ]))->assertStatus(422);

        $this->postJson('/api/v1/admin/events', $this->payload([
            'cover_file_id' => (string) Str::uuid7(),
            'cover_alt_text' => 'Residents queueing outside the covered court.',
        ]))->assertCreated();
    }

    // ── duplication ──────────────────────────────────────────────────────────────────

    #[Test]
    public function a_duplicate_is_always_a_draft_and_carries_no_schedule_forward(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        $copy = $this->postJson("/api/v1/admin/events/{$event}/duplicate")->assertCreated()->json('data');

        /*
         * A duplicate that kept its status would publish an event for a day that has already
         * happened — and duplicating is precisely what somebody does when the dates are the thing
         * changing.
         */
        $this->assertSame('draft', $copy['status']);
        $this->assertNull($copy['published_at']);
        $this->assertNotSame($event, $copy['id']);

        // The slug is minted fresh: two events cannot share the link printed on a poster.
        $this->assertNotSame(
            (string) Event::query()->where('uuid', $event)->value('slug'),
            $copy['slug'],
        );

        // What was worth copying: the venue, the contact, the instructions.
        $this->assertSame('Dolores covered court', $copy['venue_name']);
    }

    // ── the public list ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_public_list_shows_what_is_coming_and_not_what_has_passed(): void
    {
        Sanctum::actingAs($this->admin());

        $upcoming = $this->publishedEvent();

        $past = $this->publishedEvent([
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
        ]);
        // Move it into the past without going through the API, which would refuse the dates.
        Event::query()->where('uuid', $past)->update([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subMonth()->addHours(3),
        ]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $feed = $this->getJson('/api/v1/events')->assertOk()->json('data');
        $this->assertCount(1, $feed);
        $this->assertSame($upcoming, $feed[0]['id']);

        // Still findable when asked for: "was there one last August?" is a real question.
        $this->assertCount(2, $this->getJson('/api/v1/events?include_past=1')->assertOk()->json('data'));
    }

    #[Test]
    public function a_reader_gets_no_editorial_fields(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $body = $this->getJson("/api/v1/events/{$event}")->assertOk()->json('data');

        /*
         * The citizen projection is its own method, not the staff one with fields removed. A
         * subtractive projection leaks the next field somebody adds; this one has to be added to.
         */
        $this->assertArrayNotHasKey('author_subject_id', $body);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('available_transitions', $body);
    }

    #[Test]
    public function an_event_is_reachable_by_the_slug_printed_on_the_poster(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->publishedEvent();
        $slug = (string) Event::query()->where('uuid', $event)->value('slug');

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->assertSame($event, $this->getJson("/api/v1/events/{$slug}")->assertOk()->json('data.id'));
    }

    // ── audit ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function publishing_and_cancelling_are_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $event = $this->publishedEvent();

        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Venue unavailable.',
        ])->assertOk();

        // "Who called this off, and when" is the question at the covered court on the day.
        foreach (['event.published', 'event.cancelled'] as $action) {
            $this->assertDatabaseHas('audit_entries', [
                'action' => $action,
                'entity_id' => $event,
                'actor_subject_id' => (string) $admin->uuid,
            ]);
        }
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
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): string
    {
        return $this->postJson('/api/v1/admin/events', $this->payload($overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedEvent(array $overrides = []): string
    {
        $event = $this->draft($overrides);

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        return $event;
    }
}
