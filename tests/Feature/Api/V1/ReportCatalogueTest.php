<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Reporting\Application\MetricsService;
use PHPUnit\Framework\Attributes\Test;

/**
 * The report catalogue and synchronous run — TAB 07's two `ReportRepository` rows.
 *
 * The command's constraints are the tests: *"Aggregate-first. Suppress small cells rather than
 * rounding them. No grouping by caseworker — filtering to one named worker is permitted, a
 * leaderboard is not."*
 */
final class ReportCatalogueTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the catalogue ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_catalogue_names_each_report_and_the_question_it_answers(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $reports = collect($this->getJson('/api/v1/admin/reports')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertArrayHasKey('case-summary', $reports);

        foreach ($reports as $report) {
            // A title is not a question. "Reach by barangay" does not tell a reader whether a
            // blank row means nobody applied or nobody was served, and that difference is the
            // finding.
            $this->assertNotEmpty($report['question']);
            $this->assertContains($report['grain'], ['aggregate', 'person-level']);
        }
    }

    /**
     * Filtered by permission, not annotated with it.
     *
     * A caller who cannot run the payout manifest does not see a greyed-out payout manifest. A
     * listing that names what somebody may not have tells them it exists, and `release-manifest`
     * existing is itself a fact about how this office pays people.
     */
    #[Test]
    public function the_catalogue_omits_reports_the_caller_may_not_run(): void
    {
        // Holds report.view and not report.export-person-level — the exact caller this rule is for.
        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'lgu_staff');

        Sanctum::actingAs($account);

        $ids = collect($this->getJson('/api/v1/admin/reports')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertNotContains('release-manifest', $ids, 'A person-level report is not listed to somebody who cannot run it.');
    }

    #[Test]
    public function a_person_level_report_is_listed_as_not_runnable_here(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $reports = collect($this->getJson('/api/v1/admin/reports')->assertOk()->json('data'))->keyBy('id');

        foreach ($reports as $report) {
            if ($report['grain'] === 'person-level') {
                $this->assertFalse($report['runnable'], 'Naming people is an export: it carries a retention window, an audit entry and a warning.');
            }
        }
    }

    // ── the run ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_aggregate_report_runs_and_reports_how_it_suppresses(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $body = $this->postJson('/api/v1/admin/reports/case-summary/run')->assertOk();

        $this->assertSame('case-summary', $body->json('data.id'));
        $this->assertNotEmpty($body->json('data.question'));

        $this->assertSame(MetricsService::MINIMUM_CELL, $body->json('meta.suppression.minimum_cell'));

        // Never rounded and never zeroed: a rounded figure is an untrue number in a report, and a
        // zero says the office served nobody, which is itself the finding.
        $this->assertSame('withheld', $body->json('meta.suppression.method'));
    }

    /**
     * The disclosure control, proven against real rows rather than asserted in a comment.
     *
     * "3 households in Barangay Dolores" is a statistic. "1 household" plus the barangay is an
     * identification.
     */
    #[Test]
    public function a_cell_below_the_minimum_is_withheld_rather_than_published(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // One case in one barangay — comfortably under the minimum cell.
        $this->welfareCase($this->barangayId());

        $rows = $this->postJson('/api/v1/admin/reports/barangay-reach/run')->assertOk()->json('data.rows');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertTrue($row['suppressed']);
            $this->assertNull($row['total'], 'A withheld cell reports null, never a rounded or zeroed number.');
        }
    }

    #[Test]
    public function a_cell_at_or_above_the_minimum_is_published(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        for ($i = 0; $i < MetricsService::MINIMUM_CELL; $i++) {
            $this->welfareCase($this->barangayId());
        }

        $rows = $this->postJson('/api/v1/admin/reports/barangay-reach/run')->assertOk()->json('data.rows');

        $this->assertSame(MetricsService::MINIMUM_CELL, $rows[0]['total']);
        $this->assertFalse($rows[0]['suppressed']);
    }

    /**
     * The command: *"No grouping by caseworker — filtering to one named worker is permitted, a
     * leaderboard is not."*
     *
     * The dashboard accepts the filter, because that is how a supervisor reviews a caseload they
     * are responsible for. A **report** is the artefact that gets pasted into a meeting pack, and
     * a per-worker report is a league table however it was produced.
     */
    #[Test]
    public function a_report_cannot_be_filtered_to_one_caseworker(): void
    {
        $me = $this->reviewer('lgu_admin');
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/admin/reports/field-workload/run?assigned_to={$me->uuid}")
            ->assertStatus(422);

        // Still permitted on the dashboard, which is the supervision surface rather than the
        // artefact. Removing it there would break a legitimate use.
        $this->getJson("/api/v1/admin/dashboard?assigned_to={$me->uuid}")->assertOk();
    }

    #[Test]
    public function a_report_that_names_people_has_no_synchronous_form(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        foreach (['release-manifest', 'event-registrants'] as $personLevel) {
            $this->postJson("/api/v1/admin/reports/{$personLevel}/run")->assertNotFound();
        }
    }

    #[Test]
    public function an_unknown_report_and_a_person_level_one_refuse_identically(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $unknown = $this->postJson('/api/v1/admin/reports/invented-report/run')->assertNotFound();
        $personLevel = $this->postJson('/api/v1/admin/reports/release-manifest/run')->assertNotFound();

        $this->assertSame($unknown->json('error.message'), $personLevel->json('error.message'));
    }

    #[Test]
    public function running_a_report_is_recorded_in_the_trail(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson('/api/v1/admin/reports/case-summary/run')->assertOk();

        // Nothing was written and no name was returned, and it is still audited: who asked which
        // question of the welfare registry is itself the audit interest.
        $this->assertTrue(
            DB::table('audit_entries')->where('action', 'report.run')->exists(),
            'A report run leaves no other trace; if it is not audited, nobody can tell it happened.',
        );
    }

    // ── access ───────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_barangay_scoped_caller_reports_only_on_their_own_barangay(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        for ($i = 0; $i < MetricsService::MINIMUM_CELL; $i++) {
            $this->welfareCase($this->barangayId());
        }

        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'lgu_admin', $this->otherBarangayId());

        Sanctum::actingAs($account);

        $rows = $this->postJson('/api/v1/admin/reports/barangay-reach/run')->assertOk()->json('data.rows');

        // An aggregate is exactly the shape that hides a scope leak: one number reveals nothing
        // about where it came from.
        $this->assertSame([], $rows);
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_neither_endpoint(): void
    {
        $this->getJson('/api/v1/admin/reports')->assertUnauthorized();
        $this->postJson('/api/v1/admin/reports/case-summary/run')->assertUnauthorized();
    }

    #[Test]
    public function the_catalogue_is_paginated(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->assertArrayHasKey(
            'pagination',
            $this->getJson('/api/v1/admin/reports')->assertOk()->json('meta'),
        );
    }

    private function welfareCase(int $barangayId): void
    {
        DB::table('welfare_cases')->insert([
            'uuid' => (string) Str::uuid7(),
            'case_number' => 'WC-'.strtoupper(Str::random(10)),
            'type' => 'aics',
            'resident_id' => (string) Str::uuid7(),
            'barangay_id' => $barangayId,
            'status' => 'assessment',
            'priority' => 'normal',
            'opened_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
