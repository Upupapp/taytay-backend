<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The work queues — TAB 07's three `WorkRepository` rows.
 *
 * Read-only views over `tasks`. The tests that matter here are the ones about **who sees whose
 * work**: a queue is the screen an office scans fastest and questions least, so a scoping mistake
 * on it is both the easiest to make and the slowest to notice.
 */
final class WorkQueueTest extends KycTestCase
{
    use RefreshDatabase;

    // ── mine ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function my_queue_holds_my_work_and_not_a_colleagues(): void
    {
        $me = $this->reviewer('lgu_admin');
        $colleague = $this->reviewer('lgu_admin');

        $this->task('Mine to do', assignedTo: (string) $me->uuid);
        $this->task('Theirs to do', assignedTo: (string) $colleague->uuid);
        $this->task('Nobody has this', assignedTo: null);

        Sanctum::actingAs($me);

        $titles = collect($this->getJson('/api/v1/admin/work/mine')->assertOk()->json('data'))
            ->pluck('title')
            ->all();

        $this->assertSame(['Mine to do'], $titles);
    }

    /**
     * "Mine" that can be pointed at a colleague is not "mine".
     *
     * There is deliberately no `?assignee=` on this route, and a parameter smuggled in must not
     * change whose work comes back. Reading somebody else's load is the team view, behind a
     * different permission.
     */
    #[Test]
    public function my_queue_cannot_be_redirected_at_another_worker(): void
    {
        $me = $this->reviewer('lgu_admin');
        $colleague = $this->reviewer('lgu_admin');

        $this->task('Theirs to do', assignedTo: (string) $colleague->uuid);

        Sanctum::actingAs($me);

        foreach ([
            "/api/v1/admin/work/mine?assignee={$colleague->uuid}",
            "/api/v1/admin/work/mine?assigned_to={$colleague->uuid}",
            "/api/v1/admin/work/mine?owner_id={$colleague->uuid}",
        ] as $path) {
            $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    #[Test]
    public function overdue_is_derived_against_the_date_the_response_reports(): void
    {
        $me = $this->reviewer('lgu_admin');

        $this->task('Late', assignedTo: (string) $me->uuid, dueOn: now()->subWeek()->toDateString());
        $this->task('Undated', assignedTo: (string) $me->uuid, dueOn: null);

        Sanctum::actingAs($me);

        $body = $this->getJson('/api/v1/admin/work/mine')->assertOk();

        $items = collect($body->json('data'))->keyBy('title');

        $this->assertTrue($items['Late']['is_overdue']);
        $this->assertFalse($items['Undated']['is_overdue']);

        // No service standard was supplied, so an undated item reports waiting rather than
        // lateness. "3 days overdue" would be a claim about a target nobody set.
        $this->assertNull($items['Undated']['due_on']);
        $this->assertNotNull($items['Undated']['waiting_since']);

        $this->assertSame(now()->toDateString(), $body->json('meta.as_of'));
    }

    /**
     * ADR 0024 §2, held on the queue.
     *
     * A queue is the one screen designed to be scanned by somebody reviewing other people's work.
     * A subject summary on every row would disclose who each task is about to everybody who can
     * see it, so the row carries a type and an opaque identifier and the client follows the
     * pointer to that module's own endpoint, which does its own authorization.
     */
    #[Test]
    public function a_queue_row_names_no_subject(): void
    {
        $me = $this->reviewer('lgu_admin');
        $this->task('Review something', assignedTo: (string) $me->uuid);

        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/admin/work/mine')->assertOk()->json('data.0');

        foreach (['subject', 'preview', 'subject_name', 'resident_name', 'summary'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }

        $this->assertArrayHasKey('subject_type', $row);
        $this->assertArrayHasKey('subject_id', $row);
    }

    // ── team ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_team_view_groups_by_carrier_and_never_hides_unassigned_work(): void
    {
        $me = $this->reviewer('lgu_admin');

        $this->task('Mine', assignedTo: (string) $me->uuid);
        $this->task('Nobody has this', assignedTo: null);
        $this->task('Nobody has this either', assignedTo: null);

        Sanctum::actingAs($me);

        $body = $this->getJson('/api/v1/admin/work/team')->assertOk();

        $this->assertSame(2, $body->json('meta.unassigned_count'));

        // Unassigned first: it is the group with nobody watching it.
        $this->assertNull($body->json('data.0.assigned_to'));
        $this->assertSame(2, $body->json('data.0.total'));
    }

    /**
     * The permission split this endpoint exists to respect.
     *
     * Reading a colleague's caseload is supervision, not something that comes free with having a
     * queue of your own. `staff.view` guards it; `task.view` does not reach it.
     */
    #[Test]
    public function the_team_view_is_refused_to_a_caller_who_only_holds_task_view(): void
    {
        $account = Account::factory()->staff()->create();
        // Holds `task.view` and not `staff.view` — a caseworker with a queue of their own.
        $this->grantRole($account, 'lgu_staff');

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/work/team')->assertForbidden();
    }

    // ── alerts ───────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_alert_states_the_rule_that_produced_it(): void
    {
        $me = $this->reviewer('lgu_admin');

        $this->task('Nobody has this', assignedTo: null);
        $this->task('Late', assignedTo: (string) $me->uuid, dueOn: now()->subWeek()->toDateString());

        Sanctum::actingAs($me);

        $alerts = collect($this->getJson('/api/v1/admin/work/alerts')->assertOk()->json('data'))
            ->keyBy('kind');

        $this->assertArrayHasKey('unassigned-work', $alerts);
        $this->assertArrayHasKey('overdue-work', $alerts);

        foreach ($alerts as $alert) {
            // An alert nobody can check is one an office learns to dismiss.
            $this->assertNotEmpty($alert['basis']);
            $this->assertGreaterThan(0, $alert['detected_from']);
        }
    }

    #[Test]
    public function an_alert_goes_when_the_record_is_fixed_because_nothing_is_stored(): void
    {
        $me = $this->reviewer('lgu_admin');
        $task = $this->task('Nobody has this', assignedTo: null);

        Sanctum::actingAs($me);

        $this->assertNotEmpty($this->getJson('/api/v1/admin/work/alerts')->assertOk()->json('data'));

        $this->postJson("/api/v1/tasks/{$task}/assignment", ['assigned_to' => (string) $me->uuid])
            ->assertSuccessful();

        $kinds = collect($this->getJson('/api/v1/admin/work/alerts')->assertOk()->json('data'))
            ->pluck('kind')
            ->all();

        $this->assertNotContains('unassigned-work', $kinds, 'Nothing is stored, so fixing the record clears the alert.');
    }

    // ── shape and access ─────────────────────────────────────────────────────────────

    #[Test]
    public function every_work_collection_is_paginated(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        foreach (['mine', 'team', 'alerts'] as $queue) {
            $meta = $this->getJson("/api/v1/admin/work/{$queue}")->assertOk()->json('meta');

            $this->assertArrayHasKey('pagination', $meta, "admin/work/{$queue} returned an unbounded collection.");
        }
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_no_queue(): void
    {
        foreach (['mine', 'team', 'alerts'] as $queue) {
            $this->getJson("/api/v1/admin/work/{$queue}")->assertUnauthorized();
        }
    }

    #[Test]
    public function no_queue_offers_a_way_to_change_the_work(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // Acting on an item goes to the task's own endpoints, which already audit. A second write
        // path to one record is how two histories of it come to exist.
        foreach (['mine', 'team', 'alerts'] as $queue) {
            $this->postJson("/api/v1/admin/work/{$queue}", [])->assertStatus(405);
        }
    }

    private function task(string $title, ?string $assignedTo, ?string $dueOn = null): string
    {
        $uuid = (string) Str::uuid7();

        DB::table('tasks')->insert([
            'uuid' => $uuid,
            'type' => 'general',
            'title' => $title,
            'assigned_to' => $assignedTo,
            'priority' => 'normal',
            'status' => 'open',
            'due_on' => $dueOn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }
}
