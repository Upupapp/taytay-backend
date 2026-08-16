<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Tasks\Infrastructure\Eloquent\Task;
use Modules\Welfare\Application\ReferralService;
use Modules\Welfare\Jobs\SweepOverdueReferrals;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 19, as tests.
 *
 *  1. **Overdue tasks are queryable efficiently.**
 *  2. **Completing a task records an outcome without silently changing unrelated case state.**
 *  3. **Linked entity access is still policy checked.**
 *
 * The third is the interesting one, and it is held by a design rather than a check: a task carries
 * a type, an opaque identifier and a short instruction, so there is nothing on it worth reading
 * if you cannot open its subject.
 */
final class TaskTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 2: closing a task changes nothing else ──────────────────────────────

    #[Test]
    public function completing_a_task_records_an_outcome_and_touches_nothing_else(): void
    {
        Sanctum::actingAs($this->staff());

        $case = $this->caseFor($this->client());
        $before = DB::table('welfare_cases')->where('uuid', $case)->first();

        $task = $this->postJson('/api/v1/tasks', [
            'type' => 'close-case',
            'title' => 'Close the case once the release is confirmed',
            'subject_type' => 'welfare.case',
            'subject_id' => $case,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tasks/{$task}/closure", [
            'status' => 'done',
            'outcome' => 'Confirmed with the disbursement desk.',
        ])->assertOk()->assertJsonPath('data.status', 'done');

        /*
         * THE CRITERION. Completing "close the case" does not close the case — it records that
         * somebody says they did. A task that moved its subject would be automation nobody asked
         * for, changing an outcome for a family through a queue action.
         */
        $after = DB::table('welfare_cases')->where('uuid', $case)->first();

        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->closed_at, $after->closed_at);
    }

    #[Test]
    public function closing_a_task_always_records_why(): void
    {
        Sanctum::actingAs($this->staff());

        $task = $this->openTask();

        // "What happened" is the only thing a completed task leaves behind, and a cancelled task
        // that says nothing is indistinguishable from one nobody did.
        $this->postJson("/api/v1/tasks/{$task}/closure", ['status' => 'done'])->assertStatus(422);
        $this->postJson("/api/v1/tasks/{$task}/closure", ['status' => 'cancelled'])->assertStatus(422);

        $this->postJson("/api/v1/tasks/{$task}/closure", [
            'status' => 'cancelled',
            'outcome' => 'The family withdrew the request.',
        ])->assertOk();
    }

    #[Test]
    public function a_closed_task_cannot_be_closed_again(): void
    {
        Sanctum::actingAs($this->staff());

        $task = $this->openTask();

        $this->postJson("/api/v1/tasks/{$task}/closure", [
            'status' => 'done',
            'outcome' => 'Done.',
        ])->assertOk();

        $this->postJson("/api/v1/tasks/{$task}/closure", [
            'status' => 'done',
            'outcome' => 'Done again.',
        ])->assertStatus(409);
    }

    // ── criterion 3: the queue discloses nothing about its subject ────────────────────

    #[Test]
    public function a_task_carries_no_detail_about_the_record_it_points_at(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->existingResident([
            'first_name' => 'Confidential',
            'middle_name' => null,
            'last_name' => 'Person',
        ]);
        $case = $this->caseFor($resident);

        $this->postJson('/api/v1/tasks', [
            'type' => 'review-intake',
            'title' => 'Review the intake',
            'subject_type' => 'welfare.case',
            'subject_id' => $case,
        ])->assertCreated();

        $body = $this->getJson('/api/v1/tasks')->assertOk()->content();

        /*
         * A queue row is read by everyone who can see the queue. Anything denormalised onto it is
         * disclosed to all of them regardless of whether they may open the thing it points at —
         * so a task carries a type, an opaque identifier and an instruction, and nothing else.
         *
         * That is why "team membership alone does not grant access to a linked sensitive entity"
         * needs no per-row permission check here: there is nothing on the row to protect, and so
         * nothing for a future field to forget.
         */
        $this->assertStringNotContainsString('Confidential', $body);
        $this->assertStringNotContainsString('narrative', $body);
        $this->assertStringContainsString($case, $body);
    }

    #[Test]
    public function following_the_pointer_still_hits_the_subjects_own_authorization(): void
    {
        // A case in another barangay, with a task pointing at it.
        $elsewhere = $this->existingResident(['first_name' => 'Far', 'middle_name' => null, 'last_name' => 'Away']);
        $elsewhere->forceFill(['barangay_id' => $this->otherBarangayId()])->save();

        Sanctum::actingAs($this->admin());
        $case = $this->caseFor($elsewhere);

        $this->postJson('/api/v1/tasks', [
            'type' => 'review-intake',
            'title' => 'Review the intake',
            'subject_type' => 'welfare.case',
            'subject_id' => $case,
        ])->assertCreated();

        // A barangay-scoped clerk can see the task…
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        $this->assertSame(1, count($this->getJson('/api/v1/tasks')->assertOk()->json('data')));

        // …and still cannot open what it points at. The task holds a pointer, not a key.
        $this->getJson("/api/v1/admin/cases/{$case}")->assertNotFound();
    }

    // ── criterion 1: the queues ───────────────────────────────────────────────────────

    #[Test]
    public function the_queues_answer_mine_overdue_due_today_and_upcoming(): void
    {
        $worker = $this->staff();
        Sanctum::actingAs($worker);

        $mineIs = ['assigned_to' => (string) $worker->uuid];

        $overdue = $this->openTask($mineIs + ['due_on' => now()->subWeek()->toDateString()]);
        $today = $this->openTask($mineIs + ['due_on' => now()->toDateString()]);
        $later = $this->openTask($mineIs + ['due_on' => now()->addWeek()->toDateString()]);

        // Somebody else's work.
        $other = Account::factory()->staff()->create();
        $this->grantRole($other, 'lgu_staff', $this->barangayId());
        $this->openTask(['assigned_to' => (string) $other->uuid, 'due_on' => now()->toDateString()]);

        $this->assertSame([$overdue], $this->ids('/api/v1/tasks?overdue=1'));
        $this->assertSame([$later], $this->ids('/api/v1/tasks?upcoming=1'));
        $this->assertContains($today, $this->ids('/api/v1/tasks?due_today=1'));

        // `mine` resolves from the token, never a parameter: a queue filtered by an account id in
        // the query string is a queue anybody can point at anybody.
        $mine = $this->ids('/api/v1/tasks?mine=1');
        $this->assertContains($overdue, $mine);
        $this->assertCount(3, $mine);
    }

    #[Test]
    public function a_task_with_no_due_date_is_never_overdue(): void
    {
        Sanctum::actingAs($this->staff());

        $this->openTask(['due_on' => null]);

        // It was never a promise about a date.
        $this->assertSame([], $this->ids('/api/v1/tasks?overdue=1'));
    }

    #[Test]
    public function the_queue_puts_overdue_and_urgent_work_first(): void
    {
        Sanctum::actingAs($this->staff());

        $normal = $this->openTask(['due_on' => now()->addDay()->toDateString()]);
        $urgent = $this->openTask(['due_on' => now()->addDay()->toDateString(), 'priority' => 'urgent']);
        $overdue = $this->openTask(['due_on' => now()->subDay()->toDateString(), 'priority' => 'low']);

        // Overdue first even at low priority, then most urgent, then soonest.
        $this->assertSame([$overdue, $urgent, $normal], $this->ids('/api/v1/tasks'));
    }

    // ── the automation ────────────────────────────────────────────────────────────────

    #[Test]
    public function an_overdue_referral_raises_exactly_one_task_however_often_the_sweep_runs(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->sentReferral();

        $sweep = fn (): int => app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        $sweep();
        $sweep();
        $sweep();

        /*
         * The sweep runs nightly and the referral stays overdue until somebody chases it. Without
         * the automation key the queue grows a fresh copy every morning, and within a fortnight it
         * is fourteen identical rows and nobody trusts it.
         */
        $tasks = Task::query()->where('subject_id', $referral)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame('referral.overdue', $tasks[0]->raised_by_event);
        $this->assertSame('welfare.referral', $tasks[0]->subject_type);
    }

    #[Test]
    public function an_automatic_task_names_the_reference_and_not_the_client(): void
    {
        Sanctum::actingAs($this->admin());

        $this->sentReferral();

        app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        $title = (string) Task::query()->value('title');

        // Enough to find the file, and discloses nothing to somebody who cannot open it.
        $this->assertStringContainsString('REF-', $title);
        $this->assertStringNotContainsString('Refer', $title);
        $this->assertStringNotContainsString('hospital bill', $title);
    }

    #[Test]
    public function closing_the_task_lets_a_later_sweep_raise_a_fresh_one(): void
    {
        Sanctum::actingAs($this->admin());

        $this->sentReferral();

        $sweep = fn (): int => app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        $sweep();
        $task = (string) Task::query()->value('uuid');

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/tasks/{$task}/closure", [
            'status' => 'done',
            'outcome' => 'Telephoned; they have no record of it.',
        ])->assertOk();

        /*
         * The key is released on closure, which is what lets the next sweep raise a fresh task if
         * the problem is still there. Held forever, a referral that went overdue, was chased and
         * went overdue again would never produce a second task.
         */
        $sweep();
        $this->assertSame(2, Task::query()->count());
    }

    #[Test]
    public function a_visit_follow_up_raises_a_task_carrying_the_action_and_not_the_observations(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->postJson('/api/v1/admin/visits', [
            'resident_id' => (string) $this->client()->uuid,
            'purpose' => 'verification',
            'scheduled_for' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/visits/{$visit}/observations", [
            'kind' => 'client-said',
            'body' => 'She says her husband has not sent money since March.',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", [
            'status' => 'completed',
            'outcome' => 'Household reached.',
            'next_action' => 'Return with the barangay certificate request form.',
            'follow_up_on' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $task = Task::query()->where('subject_type', 'welfare.field-visit')->firstOrFail();

        // What somebody must DO belongs in a queue; what a family said does not (Article 8.4).
        $this->assertSame('Return with the barangay certificate request form.', $task->title);
        $this->assertStringNotContainsString('husband', $task->title);
        $this->assertSame('visit.follow-up-due', $task->raised_by_event);
    }

    #[Test]
    public function an_automatic_task_is_distinguishable_from_one_a_colleague_raised(): void
    {
        Sanctum::actingAs($this->admin());

        $this->sentReferral();
        app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        Sanctum::actingAs($this->staff());
        $manual = $this->openTask();

        $rows = collect($this->getJson('/api/v1/tasks')->assertOk()->json('data'))->keyBy('id');

        /*
         * "The system noticed this" and "a colleague asked me to do this" carry different weight,
         * and a queue that hides the difference trains people to ignore the automatic ones.
         */
        $this->assertNull($rows[$manual]['raised_by_event']);
        $this->assertSame(
            'referral.overdue',
            $rows->firstWhere('raised_by_event', 'referral.overdue')['raised_by_event'],
        );
    }

    #[Test]
    public function an_automatic_task_is_attributed_to_nobody(): void
    {
        Sanctum::actingAs($this->admin());

        $this->sentReferral();
        app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        // `ActorContext::system()` carries a null subject, so the record is honestly attributed
        // to nobody rather than to a fictitious account or to whoever's request happened to
        // trigger the sweep.
        $this->assertNull(Task::query()->value('created_by'));
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function task_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_holds_no_task_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/tasks')->assertForbidden();
        $this->postJson('/api/v1/tasks', [])->assertForbidden();
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

    private function client(): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => 'Tas'.$n,
            'middle_name' => null,
            'last_name' => 'Ked',
            'birth_date' => '1988-04-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function openTask(array $overrides = []): string
    {
        // `general` — work that belongs to no record, which the schema deliberately allows.
        return $this->postJson('/api/v1/tasks', $overrides + [
            'type' => 'general',
            'title' => 'Ring the barangay about the distribution venue',
        ])->assertCreated()->json('data.id');
    }

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }

    /**
     * A referral that has actually been sent, so the sweep can find it overdue.
     */
    private function sentReferral(): string
    {
        $referral = $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) $this->client()->uuid,
            'destination_name' => 'District hospital',
            'destination_type' => 'hospital-msw',
            'service_requested' => 'Medical social work assessment',
            'reason' => 'Unable to meet the hospital bill.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/referrals/{$referral}/authority", [
            'basis' => 'client-consent',
            'note' => 'Told which office would receive this, and agreed.',
        ])->assertOk();

        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();

        return $referral;
    }

    /**
     * @return list<string>
     */
    private function ids(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    }
}
