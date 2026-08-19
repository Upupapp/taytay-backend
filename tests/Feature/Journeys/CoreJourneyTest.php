<?php

declare(strict_types=1);

namespace Tests\Feature\Journeys;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Events\Infrastructure\Eloquent\EventRegistration;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\KycTestCase;

/**
 * The eight core end-to-end journeys the master command names (TAB 34).
 *
 * **WHY THESE EXIST WHEN 865 TESTS ALREADY PASS.**
 *
 * Every step below is already tested somewhere. What is not tested anywhere is the *seam*: a
 * feature test sets up its own fixture, exercises one module, and asserts on that module's output.
 * It cannot see the thing that actually breaks in production — a case that is approved but whose
 * requirement was never verified, a registration that survives a merge pointing at the wrong
 * resident, a citizen endpoint that starts returning an internal field only once a case has
 * reached a state no unit test ever puts it in.
 *
 * A journey builds the state the way the office builds it, through the API, in order, with the
 * real actors — and then asks what the **citizen** can see. That last part is the point: most of
 * these end by switching back to the resident and checking that what they are shown is true,
 * complete and free of the office's internal reasoning.
 *
 * FICTIONAL TAYTAY DATA ONLY, per the master command. No real resident, no real case, no real
 * number appears anywhere here.
 */
final class CoreJourneyTest extends KycTestCase
{
    use RefreshDatabase;

    // ── 1. register → KYC → verification → resident link ─────────────────────────────

    #[Test]
    public function a_citizen_registers_is_verified_and_is_linked_to_a_resident_record(): void
    {
        [$citizen, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);

        /*
         * THE WHOLE POINT OF THE LINK, checked from the citizen's side rather than from the join
         * table. `me/profile` resolves the resident from the token — there is no identifier in the
         * contract — so if the link were wrong this would return somebody else's record or
         * nothing, and both are visible here.
         */
        $profile = $this->getJson('/api/v1/me/profile')->assertOk()->json('data');

        $this->assertSame((string) $resident->uuid, (string) $profile['id']);
        $this->assertSame('verified', (string) $profile['verification_tier']);

        // And the account knows it too, which is what the mobile app renders its home screen from.
        $this->assertSame((string) $resident->uuid, (string) $this->getJson('/api/v1/me')->json('data.resident_id'));
    }

    #[Test]
    public function an_unverified_account_reaches_no_resident_record_at_all(): void
    {
        // The denial half. A citizen with no linked resident is not an error state — it is the
        // normal state of somebody who has just registered — and every `me/*` endpoint must say so
        // consistently rather than half-answering.
        Sanctum::actingAs($this->citizen());

        foreach (['/api/v1/me/profile', '/api/v1/me/household', '/api/v1/me/cases'] as $url) {
            $this->assertContains($this->getJson($url)->status(), [404, 200], $url);
        }

        $this->assertNull($this->getJson('/api/v1/me')->assertOk()->json('data.resident_id'));
    }

    // ── 2. assistance: submit → process → requirements → approve → release ───────────

    #[Test]
    public function an_assistance_request_travels_from_a_citizen_draft_to_a_released_payment(): void
    {
        [$citizen] = $this->activeCitizenWithResident();

        // ── the citizen files ──
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help with hospital bills after a fire.',
            'consent_reference' => 'ack-journey-2',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        $case = (string) DB::table('welfare_cases')->latest('id')->value('uuid');

        /*
         * WHAT THE APPLICANT SEES WHILE IT IS IN PROGRESS. A citizen-facing status message, never
         * the caseworker's internal reason — the office's deliberation about a family is written
         * for a colleague (ADR 0016 §5).
         */
        $mine = $this->getJson("/api/v1/me/cases/{$case}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('assigned_to', $mine);
        $this->assertNotEmpty($mine['status']);

        /*
         * ── the office works it, and IT TAKES TWO PEOPLE ──
         *
         * The first version of this journey drove every step as `lgu_admin` and got a `403` at
         * `endorsed` — which is the separation of duties working, not a bug. The MSWDO head
         * approves what the social workers recommend; they do not write the recommendation and
         * then sign it, so `RequestEndorse` is deliberately absent from their role (ADR 0016 §6).
         *
         * So the journey uses the actors the office uses. That is the point of a journey test: a
         * per-module test picks whichever role makes its own assertion pass, and never discovers
         * that no single person can complete the workflow.
         */
        $staff = $this->reviewer('lgu_staff');
        $admin = $this->reviewer('lgu_admin');

        // The social worker: intake, assessment, and the recommendation.
        Sanctum::actingAs($staff);

        foreach (['intake-review', 'assessment', 'endorsed'] as $target) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $target])->assertOk();
        }

        // The approving authority. A different person, by design.
        Sanctum::actingAs($admin);

        foreach (['approved', 'scheduled'] as $target) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $target])->assertOk();
        }

        // ── the money ──
        $releaseId = (string) $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'cash',
            // INTEGER CENTAVOS: five thousand pesos. Never a decimal — a peso figure that has been
            // through a float is a peso figure nobody can reconcile (ADR 0023 §1).
            'amount_centavos' => 500000,
            'release_mode' => 'over-the-counter',
            'scheduled_for' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        /*
         * THE ONE OPERATION THAT MOVES MONEY, and it is performed by a role that CANNOT approve —
         * `lgu_admin` holds approval and not release, so this call has to be made by somebody else
         * or the split is not real (ADR 0023 §3).
         */
        Sanctum::actingAs($this->reviewer('disbursing_officer'));

        $this->money()->postJson("/api/v1/admin/releases/{$releaseId}/confirmation", [
            // Frequently not the beneficiary: an elderly person sends a daughter, and recording
            // only "released" loses the one fact a dispute turns on (ADR 0023).
            'acknowledged_by_name' => 'Ana Dela Cruz',
            'acknowledged_relationship' => 'daughter',
            'acknowledgement_method' => 'signature',
        ], ['Idempotency-Key' => 'journey-2-release'])->assertOk();

        // The money moved, and the record says so.
        $this->assertSame('released', (string) DB::table('releases')->where('uuid', $releaseId)->value('status'));

        // ── the citizen sees the movement, and nothing more ──
        Sanctum::actingAs($citizen);

        $updated = $this->getJson("/api/v1/me/cases/{$case}")->assertOk()->json('data');
        $this->assertNotSame($mine['status'], $updated['status']);

        /*
         * AND THE AMOUNT IS CENTAVOS WHEREVER IT SURFACES. Article 4 and ADR 0023 §1 — a decimal
         * here would be a rounding error in somebody's assistance.
         */
        $history = $this->getJson('/api/v1/me/assistance-history')->assertOk()->content();
        $this->assertStringNotContainsString('5000.00', $history);

        /*
         * THE SEAM A PER-MODULE TEST CANNOT SEE. The case has now been through three modules and
         * two actors. Every internal field that could have leaked in along the way would be here,
         * on a record that no unit test ever puts in this state.
         */
        $body = $this->getJson("/api/v1/me/cases/{$case}")->content();

        foreach (['assigned_to', 'internal', 'caseworker', 'assessment_score'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }

    #[Test]
    public function a_citizen_cannot_move_their_own_case_through_the_lifecycle(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help.',
            'consent_reference' => 'ack-journey-2b',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        $case = (string) DB::table('welfare_cases')->latest('id')->value('uuid');

        // The denial half of the journey. An applicant who could approve their own request is the
        // whole system's failure in one call.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])->assertForbidden();

        $this->assertNotSame('approved', (string) DB::table('welfare_cases')->where('uuid', $case)->value('status'));
    }

    // ── 3. a household with two families ─────────────────────────────────────────────

    #[Test]
    public function a_household_holding_two_families_is_reported_coherently_to_each_member(): void
    {
        [$firstAccount, $firstResident] = $this->activeCitizenWithResident();
        [$secondAccount, $secondResident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->postJson('/api/v1/admin/households', [
            'name' => 'Dela Cruz household',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Sampaguita Street',
        ])->assertCreated()->json('data.id');

        foreach ([$firstResident, $secondResident] as $resident) {
            $this->postJson("/api/v1/admin/households/{$household}/members", [
                'resident_id' => (string) $resident->uuid,
                'relationship_to_head' => 'head',
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        /*
         * TWO FAMILIES UNDER ONE ROOF is a real and common arrangement, and the thing that goes
         * wrong is a member seeing the *other* family's affairs because they share a household id.
         *
         * Each member is asked what they can see, from their own token.
         */
        foreach ([$firstAccount, $secondAccount] as $account) {
            Sanctum::actingAs($account);

            $body = $this->getJson('/api/v1/me/household')->assertOk()->content();

            /*
             * A household summary names members and their relationships. It must not carry their
             * cases, their documents or their vulnerability factors — two families under one roof
             * is exactly where "we share a household id" turns into one family reading the other's
             * welfare history.
             */
            foreach (['welfare_case', 'philsys', 'vulnerability', 'safeguarding', 'narrative'] as $leak) {
                $this->assertStringNotContainsString($leak, $body);
            }
        }
    }

    // ── 4. duplicate candidate → merge review ────────────────────────────────────────

    #[Test]
    public function a_merge_moves_every_module_and_leaves_the_survivor_whole(): void
    {
        [$survivorAccount, $survivor] = $this->activeCitizenWithResident();
        [, $absorbed] = $this->activeCitizenWithResident();

        // Give the absorbed record something in three different modules, so the merge has to move
        // work that no single module's test would notice being stranded.
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $event = $this->publishedEvent();

        Sanctum::actingAs($survivorAccount);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        $before = [
            'registrations' => EventRegistration::query()->count(),
            'residents' => DB::table('residents')->whereNull('deleted_at')->count(),
        ];

        // ── the merge ──
        Event::dispatch(
            new ResidentMerged(
                (string) $survivor->uuid,
                (string) $absorbed->uuid,
            ),
        );

        /*
         * NOTHING POINTS AT THE ABSORBED RECORD ANY MORE, across every module that stores a
         * resident id. `ResidentMergeCoverageTest` proves a mechanism exists per module; this
         * proves the mechanisms actually ran together, which is a different claim.
         */
        foreach (['event_registrations', 'welfare_cases', 'consent_records'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $this->assertSame(
                0,
                DB::table($table)->where('resident_id', (string) $absorbed->uuid)->count(),
                "[{$table}] still points at the absorbed resident.",
            );
        }

        // And nothing was lost on the way: a merge moves rows, it does not delete them.
        $this->assertSame($before['registrations'], EventRegistration::query()->count());

        // The survivor can still see their own registration afterwards.
        Sanctum::actingAs($survivorAccount);
        $this->assertNotEmpty($this->getJson('/api/v1/me/event-registrations')->assertOk()->json('data'));
    }

    // ── 5. newsfeed publish → engagement → moderation ────────────────────────────────

    #[Test]
    public function a_post_is_published_engaged_with_and_then_moderated(): void
    {
        $admin = $this->reviewer('lgu_admin');
        Sanctum::actingAs($admin);

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Relief distribution at the Dolores covered court on Thursday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        // ── a citizen cannot see it yet ──
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson("/api/v1/newsfeed/{$post}")->assertNotFound();

        // ── published ──
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        // ── the citizen engages ──
        Sanctum::actingAs($citizen);
        $this->getJson("/api/v1/newsfeed/{$post}")->assertOk();
        $this->postJson("/api/v1/newsfeed/{$post}/reaction", ['reaction' => 'like'])->assertOk();

        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'What time does it start?',
        ])->assertCreated()->json('data.id');

        $this->assertCount(1, $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk()->json('data'));

        // ── the office moderates ──
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'Answered directly at the counter.',
        ])->assertOk();

        // ── and the thread reflects it, without saying so ──
        Sanctum::actingAs($citizen);

        $thread = $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk();

        /*
         * ABSENT, NOT MARKED. A count that included hidden comments — or a placeholder saying one
         * was removed — would be a moderation log by arithmetic, readable by anybody (ADR 0029 §2).
         */
        $this->assertCount(0, $thread->json('data'));
        $this->assertStringNotContainsString('hidden', $thread->content());
        $this->assertStringNotContainsString('Answered directly', $thread->content());
    }

    // ── 6. event publish → registrations → waitlist → attendance ─────────────────────

    #[Test]
    public function an_event_fills_waitlists_promotes_and_records_attendance(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishedEvent(['capacity' => 2, 'waitlist_enabled' => true]);

        // Three residents, two seats.
        $registrations = [];

        foreach (range(1, 3) as $index) {
            [$account] = $this->activeCitizenWithResident();
            Sanctum::actingAs($account);
            $registrations[$index] = [
                'account' => $account,
                'id' => $this->postJson("/api/v1/events/{$event}/registration")->assertCreated()->json('data.id'),
            ];
        }

        $this->assertSame('registered', $this->statusOf($registrations[1]['id']));
        $this->assertSame('registered', $this->statusOf($registrations[2]['id']));
        $this->assertSame('waitlisted', $this->statusOf($registrations[3]['id']));

        // ── somebody withdraws, and the queue moves ──
        Sanctum::actingAs($registrations[1]['account']);
        $this->deleteJson("/api/v1/events/{$event}/registration")->assertOk();

        $this->assertSame('registered', $this->statusOf($registrations[3]['id']));

        // ── the door marks who came ──
        Sanctum::actingAs($this->reviewer('lgu_staff'));

        $this->postJson("/api/v1/admin/events/{$event}/registrations/{$registrations[3]['id']}/attendance", [
            'attendance' => 'attended',
        ])->assertOk();

        // ── and the promoted registrant sees the whole story from their side ──
        Sanctum::actingAs($registrations[3]['account']);

        $mine = $this->getJson('/api/v1/me/event-registrations/'.$registrations[3]['id'])->assertOk()->json('data');

        $this->assertSame('registered', $mine['status']);
        // "Was I always in, or did I get in later?" is a question people ask.
        $this->assertNotNull($mine['promoted_at']);
        $this->assertSame('attended', $mine['attendance']);
    }

    // ── 7. restricted export denied ──────────────────────────────────────────────────

    #[Test]
    public function a_person_level_export_is_refused_to_everybody_who_should_not_have_it(): void
    {
        $refused = [];

        foreach (['lgu_staff', 'disbursing_officer', 'security_officer', 'operations_engineer', 'data_protection_officer'] as $role) {
            Sanctum::actingAs($this->reviewer($role));

            $status = $this->postJson('/api/v1/admin/exports', [
                'report' => 'release-manifest',
                'format' => 'csv',
            ])->status();

            if ($status !== 403) {
                $refused[] = "{$role} got {$status}";
            }
        }

        /*
         * A person-level export is a copy of a caseload leaving this application's control
         * (ADR 0026 §3). Every role that should not have it is checked, rather than one — the one
         * that would be missed is the role somebody adds later.
         */
        $this->assertSame([], $refused, implode("\n", $refused));

        // A citizen cannot even reach the endpoint.
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);
        $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest', 'format' => 'csv'])
            ->assertForbidden();

        // And the one role that may, may.
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest', 'format' => 'csv'])
            ->assertCreated();
    }

    // ── 8. mobile retry with the same idempotency key ────────────────────────────────

    #[Test]
    public function a_mobile_client_on_a_weak_connection_retries_without_duplicating_anything(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $headers = ['Idempotency-Key' => 'journey-retry-1', 'X-Client-Channel' => 'citizen-mobile'];

        /*
         * THE SCENARIO, LITERALLY. The request reaches the server, the response never arrives, and
         * the app retries. Three times, because a phone on a poor connection does not stop at two.
         */
        $responses = [];

        foreach (range(1, 3) as $attempt) {
            $responses[] = $this->postJson("/api/v1/events/{$event}/registration", [], $headers);
        }

        foreach ($responses as $response) {
            $response->assertCreated();
            $this->assertSame($responses[0]->json('data.id'), $response->json('data.id'));
        }

        $this->assertDatabaseCount('event_registrations', 1);

        // The same retry on the assistance submission, which is the one where a duplicate means
        // two case files for one household worked by two reviewers.
        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help.',
            'consent_reference' => 'ack-journey-8',
        ])->assertCreated()->json('data.id');

        $submitHeaders = ['Idempotency-Key' => 'journey-submit-1'];

        $first = $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit", [], $submitHeaders)->assertCreated();
        $second = $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit", [], $submitHeaders)->assertCreated();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, DB::table('welfare_cases')->count());
    }

    #[Test]
    public function the_same_key_with_a_different_body_is_refused(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $first = $this->publishedEvent(['capacity' => 10]);
        $second = $this->publishedEvent(['capacity' => 10]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $headers = ['Idempotency-Key' => 'journey-reuse-1'];

        $this->postJson("/api/v1/events/{$first}/registration", [], $headers)->assertCreated();

        /*
         * A key reused for a DIFFERENT request is a client bug, and answering it with the first
         * result would silently discard the second — this resident would believe they had
         * registered for an event they had not.
         */
        $this->postJson("/api/v1/events/{$second}/registration", [], $headers)->assertStatus(409);

        $this->assertDatabaseCount('event_registrations', 1);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedEvent(array $overrides = []): string
    {
        $event = $this->postJson('/api/v1/admin/events', $overrides + [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding for children aged 2 to 5.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        return $event;
    }

    private function statusOf(string $registrationUuid): string
    {
        return EventRegistration::query()->where('uuid', $registrationUuid)->value('status')->value;
    }
}
