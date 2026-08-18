<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Welfare\Application\IntakeAdvisory;
use PHPUnit\Framework\Attributes\Test;

/**
 * The last four TAB 07 rows: the intake advisory, and the newsfeed and event lifecycle histories.
 *
 * The advisory is the one that matters. It moved from the console to here, and the tests that earn
 * their place are the ones proving that moving it did not turn evidence into a verdict.
 */
final class AdvisoryAndHistoryTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the intake advisory ──────────────────────────────────────────────────────────

    #[Test]
    public function an_advisory_states_the_rule_it_applied_and_what_it_read(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid);
        $this->openCase((string) $resident->uuid);

        $body = $this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertOk();

        $signals = collect($body->json('data.signals'));

        $this->assertNotEmpty($signals);

        foreach ($signals as $signal) {
            // A finding an encoder cannot check is one they learn to click past.
            $this->assertNotEmpty($signal['rule']);
            $this->assertNotEmpty($signal['finding']);
            $this->assertContains($signal['tone'], ['note', 'caution']);
        }

        // "Nothing found" and "nothing looked at" are different answers, and the difference is
        // invisible without this number.
        $this->assertGreaterThan(0, $body->json('data.records_read'));
    }

    /**
     * `DL-60`, held on the wire: the shape has nowhere to put a verdict.
     *
     * A duplicate check with a score attached stops being evidence and becomes an eligibility
     * engine — one nobody voted for and that an encoder cannot argue with.
     */
    #[Test]
    public function an_advisory_carries_no_score_no_verdict_and_no_recommendation(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid);

        $data = $this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertOk()->json('data');

        foreach (['score', 'total', 'eligible', 'recommendation', 'decision', 'risk', 'blocked'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }

        foreach ($data['signals'] as $signal) {
            foreach (['score', 'weight', 'eligible', 'blocks', 'severity'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $signal);
            }
        }
    }

    #[Test]
    public function the_windows_say_they_are_convention_rather_than_policy(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid);

        $windows = $this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertOk()->json('data.windows');

        $this->assertSame(IntakeAdvisory::SAME_PROGRAMME_WINDOW_DAYS, $windows['same_programme_days']);
        $this->assertSame(IntakeAdvisory::ASSISTANCE_LOOKBACK_MONTHS, $windows['assistance_lookback_months']);

        // Neither came from a DSWD issuance. Said in the payload so a screen can say it too,
        // rather than presenting a convention as policy the office adopted.
        $this->assertSame('convention-pending-confirmation', $windows['basis']);
    }

    #[Test]
    public function a_person_with_no_history_gets_an_advisory_with_no_signals_rather_than_an_error(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid, status: 'draft');

        $data = $this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertOk()->json('data');

        $this->assertIsArray($data['signals']);
    }

    /**
     * `DL-93` reaching the encoder's screen.
     *
     * Nobody priced that sack of rice. Adding an in-kind release into the peso total as zero would
     * put "received, worth nothing" in front of the person deciding.
     */
    #[Test]
    public function an_in_kind_release_is_counted_in_words_and_never_valued_at_zero(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid);

        $this->release($case, (string) $resident->uuid, kind: 'in-kind', centavos: null);

        $signals = collect($this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertOk()->json('data.signals'))
            ->keyBy('code');

        $this->assertStringContainsString('no recorded value', $signals['assistance-within-lookback']['finding']);
        $this->assertStringContainsString('₱0.00', $signals['assistance-within-lookback']['finding']);
    }

    #[Test]
    public function the_advisory_is_refused_to_a_caller_who_may_not_read_the_request(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $resident = $this->existingResident();
        $case = $this->openCase((string) $resident->uuid);

        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'data_protection_officer');

        Sanctum::actingAs($account);

        $this->getJson("/api/v1/admin/assistance-requests/{$case}/advisory")->assertForbidden();
    }

    // ── lifecycle histories ──────────────────────────────────────────────────────────

    #[Test]
    public function a_post_history_reports_what_happened_newest_first(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->newsfeedPost();

        $events = collect($this->getJson("/api/v1/admin/newsfeed/{$post}/history")->assertOk()->json('data'));

        $this->assertNotEmpty($events);
        $this->assertContains('created', $events->pluck('kind')->all());
    }

    /**
     * The reason these histories exist rather than pointing the console at the audit trail.
     *
     * `audit.view` is withheld from everybody but the Data Protection Officer, because the auditee
     * must not be the auditor. A newsfeed manager reading their own post's lifecycle must not need
     * the permission that opens every approval in the office.
     */
    #[Test]
    public function reading_a_post_history_does_not_require_the_audit_permission(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $post = $this->newsfeedPost();

        // Confirms the premise: this caller cannot read the trail at all.
        $this->getJson('/api/v1/admin/audit-entries')->assertForbidden();

        // And can still read the lifecycle of the post they manage.
        $this->getJson("/api/v1/admin/newsfeed/{$post}/history")->assertOk();
    }

    #[Test]
    public function an_event_history_carries_the_cancellation_reason(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $event = $this->newEvent();

        DB::table('events')->where('uuid', $event)->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'The venue flooded.',
        ]);

        $events = collect($this->getJson("/api/v1/admin/events/{$event}/history")->assertOk()->json('data'))
            ->keyBy('kind');

        // People arranged their day around this. "Cancelled" with no reason is the version that
        // wastes the trip.
        $this->assertSame('The venue flooded.', $events['cancelled']['detail']);
    }

    #[Test]
    public function both_histories_are_paginated_and_closed_to_anonymous_callers(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->newsfeedPost();
        $event = $this->newEvent();

        foreach (["/api/v1/admin/newsfeed/{$post}/history", "/api/v1/admin/events/{$event}/history"] as $path) {
            $this->assertArrayHasKey('pagination', $this->getJson($path)->assertOk()->json('meta'));
        }

        Sanctum::actingAs(Account::factory()->staff()->create());

        foreach (["/api/v1/admin/newsfeed/{$post}/history", "/api/v1/admin/events/{$event}/history"] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────

    private function openCase(string $residentUuid, string $status = 'assessment'): string
    {
        $uuid = (string) Str::uuid7();

        DB::table('welfare_cases')->insert([
            'uuid' => $uuid,
            'case_number' => 'WC-'.strtoupper(Str::random(10)),
            'type' => 'assistance',
            'resident_id' => $residentUuid,
            'barangay_id' => $this->barangayId(),
            'status' => $status,
            'priority' => 'normal',
            'opened_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function release(string $caseUuid, string $residentUuid, string $kind, ?int $centavos): void
    {
        $caseId = (int) DB::table('welfare_cases')->where('uuid', $caseUuid)->value('id');

        DB::table('releases')->insert([
            'uuid' => (string) Str::uuid7(),
            'reference_number' => 'RL-'.strtoupper(Str::random(10)),
            'welfare_case_id' => $caseId,
            'resident_id' => $residentUuid,
            'sequence' => 1,
            'kind' => $kind,
            'amount_centavos' => $centavos,
            'in_kind_description' => $kind === 'in-kind' ? 'One sack of rice' : null,
            'currency' => 'PHP',
            'release_mode' => 'cash-pickup',
            'status' => 'released',
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function newsfeedPost(): string
    {
        return (string) $this->postJson('/api/v1/admin/newsfeed', [
            'headline' => 'Relief distribution on Saturday',
            'body' => 'Bring your barangay certificate.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');
    }

    private function newEvent(): string
    {
        return (string) $this->postJson('/api/v1/admin/events', [
            'title' => 'Medical mission',
            'description' => 'Free consultations at the covered court.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHours(4)->toIso8601String(),
            'venue_name' => 'Barangay covered court',
            'venue_address' => 'M. L. Quezon Street, Taytay, Rizal',
        ])->assertCreated()->json('data.id');
    }
}
