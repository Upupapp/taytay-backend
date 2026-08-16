<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Application\ResidentMergeService;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Welfare\Contracts\VisitFollowUpDue;
use Modules\Welfare\Infrastructure\Eloquent\FieldVisit;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 17, as tests.
 *
 *  1. **Notes carry visibility and source classification.**
 *  2. **Safeguarding detail is not returned to generic list endpoints.**
 *  3. **No continuous location tracking is implemented.**
 *
 * The third is held structurally by `NoLocationTrackingTest`; what is tested here is that the
 * contract itself refuses one, which is the behavioural half.
 */
final class FieldVisitTest extends KycTestCase
{
    use RefreshDatabase;

    // ── source classification on observations ─────────────────────────────────────────

    #[Test]
    public function an_observation_records_whose_claim_it_is(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();

        /*
         * The three sentences this table exists for. As one block of prose they become
         * indistinguishable, and six months on a different worker reads all three as established
         * fact about the family.
         */
        $this->observe($visit, 'observed', 'The roof is missing sheets over the sleeping area.');
        $this->observe($visit, 'client-said', 'She says her husband has not sent money since March.');
        $this->observe($visit, 'worker-assessed', 'The household appears unable to meet its own food costs.');

        $observations = $this->getJson("/api/v1/admin/visits/{$visit}")->assertOk()->json('data.observations');

        $this->assertSame(
            ['observed', 'client-said', 'worker-assessed'],
            array_column($observations, 'kind'),
        );

        // Rendered with a label, so a client cannot present a judgement as a finding by choosing
        // its own wording.
        $this->assertSame("The worker's assessment", $observations[2]['kind_label']);
    }

    #[Test]
    public function something_a_third_party_said_must_name_them(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();

        /*
         * "A neighbour said" with no neighbour named is a rumour the office cannot check and
         * cannot answer for — and it is the form in which a grudge enters a family's file.
         */
        $this->postJson("/api/v1/admin/visits/{$visit}/observations", [
            'kind' => 'third-party-said',
            'body' => 'The children are often left alone in the evenings.',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/visits/{$visit}/observations", [
            'kind' => 'third-party-said',
            'body' => 'The children are often left alone in the evenings.',
            'attributed_to' => 'Barangay kagawad, Purok 3',
        ])->assertCreated();
    }

    #[Test]
    public function an_attribution_cannot_be_attached_to_the_workers_own_observation(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();

        // It would read as though somebody else vouched for what the worker saw.
        $this->postJson("/api/v1/admin/visits/{$visit}/observations", [
            'kind' => 'observed',
            'body' => 'The roof is missing sheets over the sleeping area.',
            'attributed_to' => 'Barangay kagawad',
        ])->assertStatus(422);
    }

    // ── criterion 1: notes carry visibility ───────────────────────────────────────────

    #[Test]
    public function a_reader_without_clearance_sees_that_a_protected_note_exists_but_not_its_body(): void
    {
        $case = $this->caseWithNotes();

        Sanctum::actingAs($this->staff());
        $body = $this->getJson("/api/v1/admin/cases/{$case}/notes")->assertOk()->json('data');

        $this->assertCount(2, $body['notes']);

        [$protected, $routine] = $body['notes'];

        // The routine note reads normally.
        $this->assertFalse($routine['is_withheld']);
        $this->assertNotNull($routine['body']);

        /*
         * THE DESIGN, not a compromise. A caseworker who cannot see that a restricted entry
         * exists reads the file as complete and acts as though nothing happened. Knowing a record
         * is there, and that it is not theirs to read, is what makes it possible to ask the right
         * person.
         */
        $this->assertTrue($protected['is_withheld']);
        $this->assertNull($protected['body']);
        $this->assertSame('protected', $protected['sensitivity']);
        $this->assertNotNull($protected['created_at']);
        $this->assertSame(1, $body['withheld_count']);
    }

    #[Test]
    public function the_protected_body_never_reaches_the_payload_at_all(): void
    {
        $case = $this->caseWithNotes();

        Sanctum::actingAs($this->staff());
        $raw = $this->getJson("/api/v1/admin/cases/{$case}/notes")->assertOk()->content();

        // Removed by the application, not hidden by a client. A payload that never contained the
        // paragraph cannot leak it, and no future change to a template can undo that.
        $this->assertStringNotContainsString('safety plan', $raw);
        $this->assertStringNotContainsString('shelter', $raw);
    }

    #[Test]
    public function a_cleared_reader_sees_everything(): void
    {
        $case = $this->caseWithNotes();

        Sanctum::actingAs($this->admin());
        $body = $this->getJson("/api/v1/admin/cases/{$case}/notes")->assertOk()->json('data');

        $this->assertSame(0, $body['withheld_count']);
        $this->assertStringContainsString('safety plan', (string) $body['notes'][0]['body']);
    }

    #[Test]
    public function writing_into_the_protected_tier_needs_the_same_clearance_as_reading_it(): void
    {
        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($this->client());

        /*
         * Otherwise anybody could file a note nobody in their own team can see, which puts
         * something beyond review rather than beyond disclosure — the opposite of what the tier
         * is for.
         */
        $this->postJson("/api/v1/admin/cases/{$case}/notes", [
            'body' => 'Something only I can see.',
            'sensitivity' => 'protected',
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/cases/{$case}/notes", [
            'body' => 'Visited the household this morning.',
        ])->assertCreated()->assertJsonPath('data.sensitivity', 'routine');
    }

    #[Test]
    public function a_note_is_withdrawn_rather_than_deleted_and_only_by_its_author(): void
    {
        $author = $this->staff();
        Sanctum::actingAs($author);

        $case = $this->caseFor($this->client());
        $note = $this->postJson("/api/v1/admin/cases/{$case}/notes", [
            'body' => 'Recorded the wrong household by mistake.',
        ])->assertCreated()->json('data.id');

        // A record of what one worker believed at a moment is not another worker's to retract.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/cases/{$case}/notes/{$note}/withdrawal", [
            'reason' => 'Wrong household.',
        ])->assertForbidden();

        Sanctum::actingAs($author);
        $this->postJson("/api/v1/admin/cases/{$case}/notes/{$note}/withdrawal", [
            'reason' => 'Wrong household.',
        ])->assertOk()->assertJsonPath('data.is_withdrawn', true);

        // The fact that something was written and retracted is itself part of the record.
        $remaining = $this->getJson("/api/v1/admin/cases/{$case}/notes")->assertOk()->json('data.notes');
        $this->assertCount(1, $remaining);
        $this->assertSame('Wrong household.', $remaining[0]['withdrawn_reason']);
    }

    // ── criterion 2: safeguarding stays out of lists ──────────────────────────────────

    #[Test]
    public function safeguarding_detail_never_appears_in_a_list_endpoint(): void
    {
        $resident = $this->client();
        $this->raiseConcern($resident);

        Sanctum::actingAs($this->admin());
        $this->scheduleVisit($resident);

        foreach ([
            '/api/v1/admin/visits',
            '/api/v1/admin/cases',
            '/api/v1/admin/residents',
        ] as $list) {
            $body = $this->getJson($list)->assertOk()->content();

            // Not the detail, not the category, and not even a marker — a flag in a list marks
            // the family to every person who scrolls past.
            $this->assertStringNotContainsString('locked the door', $body, "leaked via {$list}");
            $this->assertStringNotContainsString('child-protection', $body, "leaked via {$list}");
            $this->assertStringNotContainsString('safeguarding', $body, "leaked via {$list}");
        }
    }

    #[Test]
    public function a_worker_attending_is_told_what_they_need_for_their_own_safety_and_no_more(): void
    {
        $resident = $this->client();
        $this->raiseConcern($resident);

        Sanctum::actingAs($this->staff());
        $visit = $this->scheduleVisit($resident);

        $detail = $this->getJson("/api/v1/admin/visits/{$visit}")->assertOk()->json('data');

        /*
         * A worker being sent to a house is entitled to know there is a risk to THEM without
         * being told a family's protection history. The advisory says what to do; the detail says
         * why, and this worker holds no `safeguarding.view`.
         */
        $this->assertSame('Attend in pairs. Do not attend after dark.', $detail['worker_safety_advisory']);
        $this->assertStringNotContainsString('locked the door', json_encode($detail));
        $this->assertStringNotContainsString('child-protection', json_encode($detail));
    }

    #[Test]
    public function a_case_file_says_that_a_restricted_record_exists_without_saying_what(): void
    {
        $resident = $this->client();
        $this->raiseConcern($resident);

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);

        $body = $this->getJson("/api/v1/admin/cases/{$case}/notes")->assertOk()->json('data');

        // Existence on a DETAIL view, so somebody opening the file does not read it as complete.
        $this->assertTrue($body['has_safeguarding_concern']);
    }

    #[Test]
    public function safeguarding_detail_needs_its_own_permission(): void
    {
        $resident = $this->client();
        $this->raiseConcern($resident);

        Sanctum::actingAs($this->staff());
        $this->getJson("/api/v1/admin/residents/{$resident->uuid}/safeguarding")->assertForbidden();

        Sanctum::actingAs($this->admin());
        $concerns = $this->getJson("/api/v1/admin/residents/{$resident->uuid}/safeguarding")
            ->assertOk()->json('data.concerns');

        $this->assertCount(1, $concerns);
        $this->assertStringContainsString('locked the door', $concerns[0]['detail']);
    }

    #[Test]
    public function there_is_no_way_to_browse_safeguarding_concerns(): void
    {
        Sanctum::actingAs($this->admin());

        /*
         * A queue of safeguarding concerns is a list of families under suspicion, and once it
         * exists it will be filtered, sorted, exported and eventually joined to something. Every
         * read is scoped to one named resident somebody already had reason to open.
         */
        $this->getJson('/api/v1/admin/safeguarding-concerns')->assertStatus(405);
    }

    #[Test]
    public function closing_a_concern_requires_saying_why(): void
    {
        $resident = $this->client();
        $concern = $this->raiseConcern($resident);

        Sanctum::actingAs($this->admin());

        // Deciding a family no longer needs watching is as consequential as deciding they do.
        $this->postJson("/api/v1/admin/safeguarding-concerns/{$concern}/closure", [])->assertStatus(422);

        $this->postJson("/api/v1/admin/safeguarding-concerns/{$concern}/closure", [
            'reason' => 'Reviewed with the WCPD; no further action needed.',
        ])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    #[Test]
    public function the_audit_trail_does_not_repeat_the_concern(): void
    {
        $resident = $this->client();
        $this->raiseConcern($resident);

        // The audit log is read by operators investigating something else entirely. It must not
        // become a second, less-guarded copy of exactly what this table restricts.
        $entries = DB::table('audit_entries')
            ->where('action', 'safeguarding.raised')->pluck('summary')->implode(' ');

        $this->assertStringContainsString('Safeguarding concern raised', $entries);
        $this->assertStringNotContainsString('child-protection', $entries);
        $this->assertStringNotContainsString('locked the door', $entries);
    }

    // ── criterion 3: no location tracking ─────────────────────────────────────────────

    #[Test]
    public function the_visit_contract_ignores_anything_that_looks_like_a_position(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->client();

        $visit = $this->postJson('/api/v1/admin/visits', [
            'resident_id' => (string) $resident->uuid,
            'purpose' => 'verification',
            'scheduled_for' => now()->addDay()->toDateString(),
            // A client that tried anyway.
            'latitude' => 14.5764,
            'longitude' => 121.1329,
            'checked_in_at' => now()->toIso8601ZuluString(),
        ])->assertCreated()->json('data');

        // Nothing to send it to, and nothing stored.
        $stored = FieldVisit::query()->where('uuid', $visit['id'])->firstOrFail()->getAttributes();

        foreach (array_keys($stored) as $column) {
            $this->assertStringNotContainsStringIgnoringCase('lat', $column);
            $this->assertStringNotContainsStringIgnoringCase('lng', $column);
            $this->assertStringNotContainsStringIgnoringCase('check', $column);
        }

        // The address IS recorded — the household registry already holds it, and a visit record
        // that cannot say where it happened is useless.
        $this->assertNotEmpty($visit['address_visited']);
    }

    // ── the lifecycle ─────────────────────────────────────────────────────────────────

    #[Test]
    public function nobody_home_and_declined_are_different_facts(): void
    {
        Sanctum::actingAs($this->staff());

        $a = $this->scheduleVisit();
        $b = $this->scheduleVisit();

        $this->postJson("/api/v1/admin/visits/{$a}/conclusion", ['status' => 'not-found'])
            ->assertOk()->assertJsonPath('data.status', 'not-found');

        $this->postJson("/api/v1/admin/visits/{$b}/conclusion", [
            'status' => 'refused',
            'declined_reason' => 'The household asked us to come back another day.',
        ])->assertOk()->assertJsonPath('data.status', 'refused');

        /*
         * Collapsing these into "unsuccessful" is how a family that was out at work acquires a
         * reputation for being uncooperative.
         */
        $this->assertNull(FieldVisit::query()->where('uuid', $a)->value('declined_reason'));
        $this->assertNotNull(FieldVisit::query()->where('uuid', $b)->value('declined_reason'));
    }

    #[Test]
    public function a_concluded_visit_is_terminal(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();
        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", ['status' => 'not-found'])->assertOk();

        /*
         * A visit that happened, happened. A second attempt is a second visit, so "how many times
         * did we go?" keeps one answer and a household is never shown as visited once when a
         * worker travelled three times.
         */
        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", [
            'status' => 'completed',
            'outcome' => 'Actually we got in.',
        ])->assertStatus(409);
    }

    #[Test]
    public function completing_a_visit_requires_recording_what_was_found(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();

        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", ['status' => 'completed'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_visit_cannot_be_scheduled_into_the_past(): void
    {
        Sanctum::actingAs($this->staff());

        // It would be overdue the moment it is created, putting a worker in default for
        // something they were never given time to do.
        $this->postJson('/api/v1/admin/visits', [
            'resident_id' => (string) $this->client()->uuid,
            'purpose' => 'monitoring',
            'scheduled_for' => now()->subWeek()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_next_action_with_a_date_raises_follow_up_work(): void
    {
        Event::fake([VisitFollowUpDue::class]);

        Sanctum::actingAs($this->staff());
        $visit = $this->scheduleVisit();

        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", [
            'status' => 'completed',
            'outcome' => 'Household reached; roof repair needed.',
            'next_action' => 'Return with the barangay certificate request form.',
            'follow_up_on' => now()->addWeek()->toDateString(),
        ])->assertOk();

        // The seam TAB 19 listens for. It carries the action, never the observations.
        Event::assertDispatched(VisitFollowUpDue::class, function (VisitFollowUpDue $event): bool {
            return str_contains($event->nextAction, 'barangay certificate')
                && ! str_contains($event->nextAction, 'roof');
        });
    }

    #[Test]
    public function a_visit_without_a_next_action_raises_nothing(): void
    {
        Event::fake([VisitFollowUpDue::class]);

        Sanctum::actingAs($this->staff());
        $visit = $this->scheduleVisit();

        $this->postJson("/api/v1/admin/visits/{$visit}/conclusion", [
            'status' => 'completed',
            'outcome' => 'Household reached; nothing further needed.',
        ])->assertOk();

        Event::assertNotDispatched(VisitFollowUpDue::class);
    }

    #[Test]
    public function an_overdue_visit_is_the_workers_debt_not_the_familys(): void
    {
        Sanctum::actingAs($this->staff());

        $visit = $this->scheduleVisit();
        FieldVisit::query()->where('uuid', $visit)
            ->update(['scheduled_for' => now()->subWeek()->toDateString()]);

        $overdue = $this->getJson('/api/v1/admin/visits?overdue_only=1')->assertOk()->json('data');

        $this->assertCount(1, $overdue);
        $this->assertTrue($overdue[0]['is_overdue']);
    }

    #[Test]
    public function a_merge_carries_visits_and_safeguarding_to_the_surviving_client(): void
    {
        Sanctum::actingAs($this->admin());

        $survivor = $this->existingResident(['first_name' => 'Mer', 'middle_name' => null, 'last_name' => 'Ged']);
        $absorbed = $this->existingResident([
            'first_name' => 'Mer', 'middle_name' => null, 'last_name' => 'Ged',
            'street_address' => '12 Duplicate Street',
        ]);

        $visit = $this->scheduleVisit($absorbed);
        $this->raiseConcern($absorbed);

        $service = app(ResidentMergeService::class);
        $pair = $service->recordPair($survivor, $absorbed, 'name-and-birth-date', 'exact');
        $pair->forceFill(['decision' => 'same-person', 'decided_at' => now()])->save();
        $service->merge(
            $survivor,
            $absorbed,
            ActorContext::authenticated((string) $this->admin()->uuid),
            'Duplicate.',
            $pair->refresh(),
        );

        $this->assertSame(
            (string) $survivor->uuid,
            (string) FieldVisit::query()->where('uuid', $visit)->firstOrFail()->resident_id,
        );

        /*
         * The most consequential row. A concern left on a soft-deleted duplicate is a protection
         * record that has silently stopped applying to the person it is about — nobody opening
         * the survivor's file would see one exists, and a worker sent to that address would get
         * no advisory. Total, and completely silent.
         */
        Sanctum::actingAs($this->staff());
        $newVisit = $this->scheduleVisit($survivor);
        $this->assertSame(
            'Attend in pairs. Do not attend after dark.',
            $this->getJson("/api/v1/admin/visits/{$newVisit}")->assertOk()->json('data.worker_safety_advisory'),
        );
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function visit_and_note_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/visits')->assertUnauthorized();
        $this->getJson('/api/v1/admin/residents/'.Str::uuid7().'/safeguarding')->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_reaches_none_of_this(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/visits')->assertForbidden();
        $this->postJson('/api/v1/admin/safeguarding-concerns', [])->assertForbidden();
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
            'first_name' => 'Vis'.$n,
            'middle_name' => null,
            'last_name' => 'Ited',
            'birth_date' => '1979-06-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
            'street_address' => $n.' Kalachuchi Street',
        ]);
    }

    private function scheduleVisit(?Resident $resident = null): string
    {
        return $this->postJson('/api/v1/admin/visits', [
            'resident_id' => (string) ($resident ?? $this->client())->uuid,
            'purpose' => 'verification',
            'scheduled_for' => now()->addDay()->toDateString(),
            'scheduled_window' => 'morning',
        ])->assertCreated()->json('data.id');
    }

    private function observe(string $visit, string $kind, string $body): void
    {
        $this->postJson("/api/v1/admin/visits/{$visit}/observations", [
            'kind' => $kind,
            'body' => $body,
        ])->assertCreated();
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
     * A case with one routine note and one protected note.
     */
    private function caseWithNotes(): string
    {
        Sanctum::actingAs($this->admin());

        $case = $this->caseFor($this->client());

        $this->postJson("/api/v1/admin/cases/{$case}/notes", [
            'body' => 'Visited the household this morning; roof repair discussed.',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/cases/{$case}/notes", [
            'body' => 'Agreed a safety plan; shelter contacted on her behalf.',
            'sensitivity' => 'protected',
        ])->assertCreated();

        return $case;
    }

    private function raiseConcern(Resident $resident): string
    {
        Sanctum::actingAs($this->admin());

        return $this->postJson('/api/v1/admin/safeguarding-concerns', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'child-protection',
            'detail' => 'Neighbour reported that the children were locked the door out overnight.',
            'worker_safety_advisory' => 'Attend in pairs. Do not attend after dark.',
        ])->assertCreated()->json('data.id');
    }
}
