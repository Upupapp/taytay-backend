<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Notification\Application\ChannelRegistry;
use Modules\Notification\Application\Notifier;
use Modules\Notification\Contracts\OutboundNotification;
use Modules\Notification\Infrastructure\Eloquent\Notification;
use Modules\Notification\Jobs\DeliverNotification;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 20, as tests.
 *
 *  1. **Notifications are not sent before required transactions commit.**
 *  2. **A device token cannot be attached to another user by spoofing an id** — held by
 *     Identity, which owns devices; `AccountSecurityTest` covers it and this TAB added no second
 *     registry (ADR 0025 §5).
 *  3. **Provider outages do not block core API transactions.**
 *
 * Plus the constitutional constraint that shapes the whole design: no personal data may reach a
 * third-party push channel (Article 8.4).
 */
final class NotificationTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: nothing is sent before the transaction commits ───────────────────

    #[Test]
    public function delivery_is_queued_after_commit(): void
    {
        Queue::fake();

        [$account, $resident] = $this->activeCitizenWithResident();

        $this->driveCaseToApproved($resident);

        /*
         * Queued inside the transaction, a worker can pick the job up before the row exists — or
         * worse, after a rollback, telling somebody about a decision that was undone. A family
         * told their assistance was approved cannot be un-told.
         */
        Queue::assertPushed(DeliverNotification::class);

        $this->assertSame(1, Notification::query()
            ->where('recipient_subject_id', (string) $account->uuid)->count());
    }

    #[Test]
    public function a_rolled_back_transition_leaves_no_notification(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);

        // A transition that fails on its last check writes nothing — including no notification,
        // because the dispatch is bound to the commit that never happened.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])
            ->assertStatus(409);

        $this->assertSame(0, Notification::query()
            ->where('recipient_subject_id', (string) $account->uuid)->count());
    }

    // ── criterion 3: a provider outage blocks nothing ────────────────────────────────

    #[Test]
    public function an_unconfigured_push_provider_does_not_fail_the_case_transition(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        // No FCM credentials in the test environment — the push channel is unconfigured.
        $this->assertNotContains('push', app(ChannelRegistry::class)->configured());

        // The case still moves, and the applicant still has their in-app record.
        $this->driveCaseToApproved($resident);

        $this->assertSame(1, Notification::query()
            ->where('recipient_subject_id', (string) $account->uuid)->count());
    }

    #[Test]
    public function an_unconfigured_channel_records_skipped_and_never_sent(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $this->driveCaseToApproved($resident);

        $dispatches = DB::table('notification_dispatches')->pluck('status', 'channel');

        // The in-app copy always lands.
        $this->assertSame('sent', $dispatches['database']);

        /*
         * `skipped`, never `sent`. A dashboard showing "delivered" for a channel that does not
         * exist is worse than one showing nothing: it tells an operator the family was told.
         */
        $this->assertSame('skipped', $dispatches['push']);
    }

    // ── the constitutional constraint: nothing personal reaches a push provider ───────

    #[Test]
    public function the_push_payload_carries_routing_information_and_nothing_else(): void
    {
        $message = new OutboundNotification(
            notificationId: 'notif-uuid',
            recipientSubjectId: 'account-uuid',
            type: 'case.status-changed',
            title: 'Update on your request',
            body: 'Your AICS assistance of PHP 5,000 is ready for release at the barangay hall.',
            subjectType: 'welfare.case',
            subjectId: 'case-uuid',
            priority: 'normal',
        );

        $payload = $message->routingPayload();

        /*
         * The same sentence that is correct in the app is a disclosure on a lock screen, on a
         * shared phone, and in a third party's logs (Article 8.4).
         */
        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('AICS', $encoded);
        $this->assertStringNotContainsString('5,000', $encoded);
        $this->assertStringNotContainsString('barangay hall', $encoded);
        $this->assertStringNotContainsString('Update on your request', $encoded);

        // A type and two opaque identifiers. The client opens the app, authenticates, and fetches
        // the detail where authorization is rechecked.
        $this->assertSame(
            ['type', 'notification_id', 'subject_type', 'subject_id'],
            array_keys($payload),
        );
    }

    #[Test]
    public function no_dispatch_row_stores_what_was_sent(): void
    {
        [, $resident] = $this->activeCitizenWithResident();
        $this->driveCaseToApproved($resident);

        // A stored push body would be a second copy of exactly the content this design keeps out
        // of the push channel in the first place.
        $columns = array_keys((array) DB::table('notification_dispatches')->first());

        foreach (['body', 'payload', 'title', 'content', 'message'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    // ── preferences ───────────────────────────────────────────────────────────────────

    #[Test]
    public function an_optional_notice_respects_a_preference(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/me/notification-preferences', [
            'notification_type' => '*',
            'channel' => 'push',
            'enabled' => false,
        ])->assertOk();

        app(Notifier::class)->notify((string) $account->uuid, 'newsfeed.published', [
            'title' => 'A new advisory',
            'body' => 'Read it in the app.',
        ], ['database', 'push']);

        $channels = DB::table('notification_dispatches')->pluck('channel')->all();
        $this->assertSame(['database'], $channels);
    }

    #[Test]
    public function a_mandatory_notice_ignores_a_preference(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/me/notification-preferences', [
            'notification_type' => '*',
            'channel' => 'push',
            'enabled' => false,
        ])->assertOk();

        /*
         * A scheduled release is a service notice. Letting it be switched off would mean somebody
         * misses a payout because of a toggle they set months earlier.
         */
        $this->driveCaseToScheduled($resident);

        $channels = DB::table('notification_dispatches')->pluck('channel')->all();
        $this->assertContains('push', $channels);
    }

    #[Test]
    public function the_in_app_record_cannot_be_switched_off(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        // `database` is not an allowed channel to set a preference on: opting out of email means
        // "stop emailing me", not "stop keeping a record of what you told me".
        $this->putJson('/api/v1/me/notification-preferences', [
            'notification_type' => '*',
            'channel' => 'database',
            'enabled' => false,
        ])->assertStatus(422);

        $body = $this->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data');

        // Stated in the payload so a client can explain the switch it is not offering.
        $this->assertStringContainsString('cannot be switched off', $body['mandatory_notice']);
    }

    // ── the inbox ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_applicant_reads_only_their_own_notifications(): void
    {
        [$mine, $resident] = $this->activeCitizenWithResident();
        [$stranger] = $this->activeCitizenWithResident();

        $this->driveCaseToApproved($resident);

        Sanctum::actingAs($mine);
        $this->assertCount(1, $this->getJson('/api/v1/me/notifications')->assertOk()->json('data'));

        Sanctum::actingAs($stranger);
        $this->assertCount(0, $this->getJson('/api/v1/me/notifications')->assertOk()->json('data'));
    }

    #[Test]
    public function another_persons_notification_id_is_not_found_rather_than_forbidden(): void
    {
        [$mine, $resident] = $this->activeCitizenWithResident();
        [$stranger] = $this->activeCitizenWithResident();

        $this->driveCaseToApproved($resident);
        $id = (string) Notification::query()->value('uuid');

        Sanctum::actingAs($stranger);

        // NOT FOUND, never FORBIDDEN — a 403 would confirm the notification exists (OWASP API1).
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertNotFound();
    }

    #[Test]
    public function the_applicant_is_told_the_projected_sentence_and_never_the_internal_reason(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", [
            'to' => 'rejected',
            'reason' => 'Household income exceeds the threshold; suspect undeclared earnings.',
        ])->assertOk();

        Sanctum::actingAs($account);
        $body = $this->getJson('/api/v1/me/notifications')->assertOk()->content();

        /*
         * The wording that survives an appeal is not the wording written for a colleague. The
         * event carries the projected sentence and the internal justification stays on the case.
         */
        $this->assertStringNotContainsString('undeclared', $body);
        $this->assertStringNotContainsString('suspect', $body);
    }

    #[Test]
    public function paper_shuffling_between_desks_is_not_announced(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        /*
         * Telling somebody each time a file moves between desks teaches them the notifications
         * are noise, and then the one that matters arrives among fourteen that did not.
         */
        $this->assertSame(0, Notification::query()
            ->where('recipient_subject_id', (string) $account->uuid)->count());
    }

    #[Test]
    public function marking_read_is_idempotent_and_scoped(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->driveCaseToApproved($resident);

        Sanctum::actingAs($account);
        $id = $this->getJson('/api/v1/me/notifications')->assertOk()->json('data.0.id');

        $first = $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk()->json('data.read_at');
        $second = $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk()->json('data.read_at');

        // A second read does not move the timestamp: when they first saw it is the useful fact.
        $this->assertSame($first, $second);

        $this->assertCount(0, $this->getJson('/api/v1/me/notifications?unread=1')->assertOk()->json('data'));
    }

    #[Test]
    public function notification_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/me/devices', [])->assertUnauthorized();
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

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }

    private function driveCaseToApproved(Resident $resident): string
    {
        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])->assertOk();

        return $case;
    }

    private function driveCaseToScheduled(Resident $resident): string
    {
        $case = $this->driveCaseToApproved($resident);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'scheduled'])->assertOk();

        return $case;
    }
}
