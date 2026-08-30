<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Reporting\Application\MetricsService;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Reporting\Jobs\BuildReportExport;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 21, as tests.
 *
 *  1. **Metrics reproduce the frontend filters.**
 *  2. **Large exports do not hold an HTTP request open.**
 *  3. **A person-level export requires explicit permission and is privately downloadable.**
 *
 * Plus the two instructions the master command gives in prose: aggregate-first, and no employee
 * performance rankings.
 */
final class ReportingTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('object-storage');
        config()->set('files.disk', 'object-storage');
    }

    // ── criterion 1: the filters ──────────────────────────────────────────────────────

    #[Test]
    public function the_dashboard_accepts_the_filters_the_console_sends(): void
    {
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/v1/admin/dashboard?'.http_build_query([
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
            'barangay_id' => $this->barangayId(),
            'status' => 'submitted',
        ]))->assertOk()->json('data');

        // Echoed back so a client can show what a figure was filtered by, and so a screenshot of
        // a dashboard says what it was a dashboard of.
        $this->assertSame((string) $this->barangayId(), (string) $body['filters']['barangay_id']);
        $this->assertSame('submitted', $body['filters']['status']);

        foreach ([
            'summary', 'case_aging', 'barangay_reach', 'program_utilization',
            'referral_outcomes', 'field_workload', 'data_completeness',
        ] as $section) {
            $this->assertArrayHasKey($section, $body);
        }
    }

    #[Test]
    public function a_period_filter_actually_narrows_the_counts(): void
    {
        Sanctum::actingAs($this->staff());

        foreach (range(1, 3) as $ignored) {
            $this->caseFor($this->client());
        }

        Sanctum::actingAs($this->admin());

        $all = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.summary.new_requests');

        $none = $this->getJson('/api/v1/admin/dashboard?'.http_build_query([
            'from' => now()->addYear()->toDateString(),
        ]))->assertOk()->json('data.summary.new_requests');

        $this->assertSame(3, $all);
        $this->assertSame(0, $none);
    }

    // ── aggregate-first, and a small cell is a person ─────────────────────────────────

    #[Test]
    public function the_dashboard_returns_no_names(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->existingResident([
            'first_name' => 'Identifiable',
            'middle_name' => null,
            'last_name' => 'Person',
        ]);
        $this->caseFor($resident);

        Sanctum::actingAs($this->admin());
        $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->content();

        // Aggregate-first: the detail behind a count is reached through the module that owns it,
        // where authorization is checked per record.
        $this->assertStringNotContainsString('Identifiable', $body);
        $this->assertStringNotContainsString('resident_id', $body);
    }

    #[Test]
    public function a_count_below_the_minimum_cell_is_suppressed_rather_than_published(): void
    {
        Sanctum::actingAs($this->staff());

        // Two cases in one barangay — below the threshold.
        $this->caseFor($this->client());
        $this->caseFor($this->client());

        Sanctum::actingAs($this->admin());
        $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $cell = $body['barangay_reach'][0];

        /*
         * "1 household in Barangay Dolores" plus any other filter the caller applied is an
         * identification, not a statistic — the standard disclosure control in official
         * statistics, applied because the objective asks for privacy-aware aggregates.
         */
        $this->assertTrue($cell['suppressed']);
        $this->assertNull($cell['total']);

        // The row is kept rather than dropped: dropping it would say the barangay has zero, which
        // is a different and false statement.
        $this->assertArrayHasKey('barangay_id', $cell);

        // And the threshold is published so a client can label a blank cell honestly.
        $this->assertSame(MetricsService::MINIMUM_CELL, $body['suppression']['minimum_cell']);
    }

    #[Test]
    public function a_count_at_or_above_the_minimum_is_published(): void
    {
        Sanctum::actingAs($this->staff());

        foreach (range(1, MetricsService::MINIMUM_CELL) as $ignored) {
            $this->caseFor($this->client());
        }

        Sanctum::actingAs($this->admin());
        $cell = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.barangay_reach.0');

        $this->assertFalse($cell['suppressed']);
        $this->assertSame(MetricsService::MINIMUM_CELL, $cell['total']);
    }

    #[Test]
    public function metrics_are_scoped_to_the_callers_barangays(): void
    {
        Sanctum::actingAs($this->admin());

        $elsewhere = $this->existingResident(['first_name' => 'Far', 'middle_name' => null, 'last_name' => 'Away']);
        $elsewhere->forceFill(['barangay_id' => $this->otherBarangayId()])->save();

        foreach (range(1, 6) as $ignored) {
            $this->caseFor($this->existingResident([
                'first_name' => 'Out'.$ignored,
                'middle_name' => null,
                'last_name' => 'Side',
                'barangay_id' => $this->otherBarangayId(),
            ]));
        }

        // A barangay-scoped clerk.
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        /*
         * An aggregate is exactly the shape that hides a scope leak: a number does not look like
         * a disclosure until you notice it was counted over the whole municipality.
         */
        $this->assertSame(0, $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()->json('data.summary.new_requests'));
    }

    #[Test]
    public function the_released_total_counts_money_that_actually_moved(): void
    {
        Sanctum::actingAs($this->admin());

        // An approved case with a prepared but unreleased payment.
        $case = $this->approvedCase();
        $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'cash',
            'amount_centavos' => 500000,
            'release_mode' => 'cash-pickup',
        ])->assertCreated();

        /*
         * A dashboard that counted approvals as money out would tell the MSWDO head they had
         * spent what they still hold.
         */
        $this->assertSame(0, $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()->json('data.summary.released_total_centavos'));
    }

    // ── no employee performance rankings ─────────────────────────────────────────────

    #[Test]
    public function workload_is_reported_by_team_and_never_by_person(): void
    {
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        /*
         * The master command's instruction, held where it would be broken first: one `GROUP BY
         * assigned_to` and the office has a leaderboard.
         *
         * The objection is not squeamishness. A caseworker's open-case count measures the cases
         * they were GIVEN — the worker handed the hardest families has the longest queue and the
         * slowest closures, and a ranking presents that as underperformance.
         */
        foreach ($body['field_workload'] as $row) {
            $this->assertArrayHasKey('team', $row);
            $this->assertArrayNotHasKey('assigned_to', $row);
            $this->assertArrayNotHasKey('caseworker', $row);
        }
    }

    #[Test]
    public function there_is_no_per_caseworker_report_to_export(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['caseworker-performance', 'staff-ranking', 'worker-productivity'] as $report) {
            $this->postJson('/api/v1/admin/exports', ['report' => $report])->assertStatus(422);
        }
    }

    #[Test]
    public function filtering_to_one_named_worker_is_still_allowed(): void
    {
        Sanctum::actingAs($this->admin());

        // Filtering is how a worker sees their own queue and how a supervisor reviews a caseload
        // they are responsible for. It is not a grouping.
        $this->getJson('/api/v1/admin/dashboard?assigned_to='.$this->staff()->uuid)->assertOk();
    }

    // ── criterion 2: exports never run inline ────────────────────────────────────────

    #[Test]
    public function requesting_an_export_queues_it_and_returns_immediately(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->admin());

        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])
            ->assertCreated()->json('data');

        /*
         * No inline path exists at all — not a size threshold, which is a decision somebody
         * eventually tunes wrong on the day the data grows.
         */
        $this->assertSame('queued', $export['status']);
        $this->assertNull($export['row_count']);
        $this->assertFalse($export['is_downloadable']);

        Queue::assertPushed(BuildReportExport::class);
    }

    #[Test]
    public function a_queued_export_cannot_be_downloaded_yet(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->admin());
        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])
            ->assertCreated()->json('data.id');

        // NOT FOUND rather than "not ready": distinguishing them would confirm the export exists
        // and what state it is in.
        $this->getJson("/api/v1/admin/exports/{$export}/download")->assertNotFound();
    }

    #[Test]
    public function the_job_produces_a_file_on_the_private_disk(): void
    {
        Sanctum::actingAs($this->staff());
        $this->caseFor($this->client());

        Sanctum::actingAs($this->admin());
        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])
            ->assertCreated()->json('data.id');

        $row = ReportExport::query()->where('uuid', $export)->firstOrFail();

        $this->assertSame('ready', $row->status);
        $this->assertNotNull($row->stored_file_id);

        // Never the `public` disk, and no durable URL anywhere in the payload.
        Storage::disk('object-storage')->assertExists((string) $row->stored_file_id);

        $body = $this->getJson('/api/v1/admin/exports')->assertOk()->content();
        $this->assertStringNotContainsString((string) $row->stored_file_id, $body);
    }

    // ── criterion 3: person-level costs a permission ─────────────────────────────────

    #[Test]
    public function a_person_level_export_needs_its_own_permission(): void
    {
        Sanctum::actingAs($this->staff());

        // Front-line staff read the dashboard and export aggregates.
        $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])->assertCreated();

        /*
         * An aggregate leaves the building as a statistic; a person-level export leaves as a copy
         * of a caseload, and once it is on a laptop none of this system's authorization applies
         * to it any more.
         */
        $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest'])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest'])
            ->assertCreated()
            ->assertJsonPath('data.is_person_level', true);
    }

    #[Test]
    public function an_export_is_downloadable_only_by_the_person_who_asked(): void
    {
        Sanctum::actingAs($this->admin());
        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])
            ->assertCreated()->json('data.id');

        $this->get("/api/v1/admin/exports/{$export}/download")->assertOk();

        /*
         * An export is a copy shaped by ONE person's scope at ONE moment; handing it to a
         * colleague with a different scope would hand them rows they could not have queried.
         */
        $other = Account::factory()->staff()->create();
        $this->grantRole($other, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($other);

        $this->getJson("/api/v1/admin/exports/{$export}/download")->assertNotFound();
    }

    #[Test]
    public function an_expired_export_stops_downloading_but_keeps_its_record(): void
    {
        Sanctum::actingAs($this->admin());
        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest'])
            ->assertCreated()->json('data.id');

        $this->get("/api/v1/admin/exports/{$export}/download")->assertOk();

        // A person-level file lives 24 hours: a link that works for a month is a permanent copy
        // of a caseload behind a URL somebody bookmarked.
        $this->travel(25)->hours();

        $this->getJson("/api/v1/admin/exports/{$export}/download")->assertNotFound();

        // The row survives, because the record that an export happened is what an audit needs.
        $this->assertSame(1, ReportExport::query()->where('uuid', $export)->count());
    }

    #[Test]
    public function a_person_level_export_expires_sooner_than_an_aggregate(): void
    {
        Sanctum::actingAs($this->admin());

        $aggregate = $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])
            ->assertCreated()->json('data.expires_at');
        $personLevel = $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest'])
            ->assertCreated()->json('data.expires_at');

        $this->assertTrue($personLevel < $aggregate);
    }

    #[Test]
    public function the_request_records_what_the_asker_was_allowed_to_see(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        // A real uuid: `batch_id` is compared against a uuid column, and 'some-batch' is now a 422.
        $batchId = (string) Str::uuid7();

        $this->postJson('/api/v1/admin/exports', [
            'report' => 'release-manifest',
            'filters' => ['batch_id' => $batchId],
        ])->assertCreated();

        $row = ReportExport::query()->firstOrFail();

        /*
         * Snapshotted because permissions change. A person-level export produced last March by
         * somebody who has since moved offices must still be explicable, and their current
         * permissions are not the answer.
         */
        $this->assertContains('report.export-person-level', $row->permission_context['permissions']);
        $this->assertArrayHasKey('scope', $row->permission_context);
        $this->assertSame($batchId, $row->filters['batch_id']);
    }

    #[Test]
    public function requesting_and_downloading_are_audited_separately(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $export = $this->postJson('/api/v1/admin/exports', ['report' => 'release-manifest'])
            ->assertCreated()->json('data.id');

        $this->get("/api/v1/admin/exports/{$export}/download")->assertOk();

        /*
         * An export somebody queued and never fetched is a different fact from one that left the
         * building, and after an incident the second is the one that matters.
         */
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'report.person-level-export-requested',
            'entity_id' => $export,
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'report.person-level-export-downloaded',
            'entity_id' => $export,
        ]);
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function reporting_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->postJson('/api/v1/admin/exports', [])->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_reaches_none_of_this(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->postJson('/api/v1/admin/exports', ['report' => 'case-summary'])->assertForbidden();
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
            'first_name' => 'Rep'.$n,
            'middle_name' => null,
            'last_name' => 'Orted',
            'birth_date' => '1981-07-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }

    private function approvedCase(): string
    {
        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($this->client());

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])->assertOk();

        return $case;
    }
}
