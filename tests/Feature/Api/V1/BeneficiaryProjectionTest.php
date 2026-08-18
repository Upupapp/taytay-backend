<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use PHPUnit\Framework\Attributes\Test;

/**
 * The beneficiary registry — TAB 07's three `BeneficiaryRepository` rows.
 *
 * The command's constraint is the thing under test: *"A projection, never an entity … store no
 * flag."* So the tests that matter are the ones proving a standing **changes when the record
 * changes**, with no job in between, and that nothing anywhere issues a beneficiary identifier.
 */
final class BeneficiaryProjectionTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the projection ───────────────────────────────────────────────────────────────

    #[Test]
    public function everyone_on_the_registry_is_a_constituent_and_nothing_more_by_default(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        $row = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data');

        $this->assertSame(['constituent'], $row['standings']);
        $this->assertSame(0, $row['assistance_event_count']);
        $this->assertSame(0, $row['total_released_centavos']);
    }

    /**
     * The point of the whole exercise: no stored flag, so no window in which it is wrong.
     *
     * A standing written to a column would be correct until the next case change and then wrong
     * until a job ran — and the window is exactly when it matters, on the morning somebody checks
     * whether a family has already been helped.
     */
    #[Test]
    public function a_standing_appears_the_moment_the_record_supports_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        $before = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data.standings');
        $this->assertNotContains('beneficiary', $before);

        $this->grantedCaseFor((string) $resident->uuid);

        $after = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data.standings');

        $this->assertContains('beneficiary', $after, 'Derived at read time — there is no job to wait for.');
    }

    #[Test]
    public function the_four_standings_are_not_exclusive(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        $this->grantedCaseFor((string) $resident->uuid);
        $this->openCaseFor((string) $resident->uuid);
        $this->enrolmentFor((string) $resident->uuid, '4PS');

        $standings = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data.standings');

        sort($standings);

        $this->assertSame(['applicant', 'beneficiary', 'constituent', 'enrollee'], $standings);
    }

    /**
     * `DL-93`, held at the projection: goods are counted, never valued.
     *
     * Nobody at the MSWDO priced that sack of rice. Summing an in-kind release in as zero would
     * put "given, worth nothing" into a figure somebody reports upward.
     */
    #[Test]
    public function an_in_kind_release_is_counted_beside_the_money_and_never_summed_into_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->grantedCaseFor((string) $resident->uuid);

        $this->release($case, (string) $resident->uuid, kind: 'cash', centavos: 250000);
        $this->release($case, (string) $resident->uuid, kind: 'in-kind', centavos: null, sequence: 2);

        $row = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data');

        $this->assertSame(250000, $row['total_released_centavos']);
        $this->assertSame(1, $row['in_kind_release_count']);
    }

    #[Test]
    public function a_scheduled_release_is_a_plan_and_is_not_reported_as_money_received(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();
        $case = $this->grantedCaseFor((string) $resident->uuid);

        $this->release($case, (string) $resident->uuid, kind: 'cash', centavos: 500000, status: 'ready');

        $this->assertSame(
            0,
            $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data.total_released_centavos'),
            'Counting a plan as a payout tells a screen somebody was paid on the day it was written down.',
        );
    }

    /**
     * The command: *"The console's domain has no [beneficiary id] and must not acquire one."*
     */
    #[Test]
    public function no_beneficiary_identifier_is_ever_issued(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        $row = $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertOk()->json('data');

        foreach (['id', 'beneficiary_id', 'uuid'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, 'A second identifier for one person is how two records of them come to exist.');
        }

        $this->assertSame((string) $resident->uuid, $row['resident_id']);

        $this->assertFalse(
            DB::getSchemaBuilder()->hasTable('beneficiaries'),
            'The registry is a projection. A table for it would be a second copy of facts that already have owners.',
        );
    }

    #[Test]
    public function an_open_duplicate_review_is_reported_as_a_boolean_and_nothing_more(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $one = $this->existingResident();
        $two = $this->existingResident(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'birth_date' => '1990-01-15']);

        $this->duplicatePair($one->id, $two->id);

        $row = $this->getJson("/api/v1/admin/beneficiaries/{$one->uuid}")->assertOk()->json('data');

        $this->assertTrue($row['has_open_duplicate_review']);

        // The pair, the rule and the band are the review queue's business. "Possible duplicate of
        // somebody" is a claim the registry screen has no need to substantiate.
        foreach (['duplicate_of', 'duplicate_pair_id', 'match_rule', 'confidence'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    // ── findings ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_finding_carries_the_rule_and_the_verdict_and_no_field_values(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $one = $this->existingResident();
        $two = $this->existingResident(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'birth_date' => '1990-01-15']);

        $this->duplicatePair($one->id, $two->id, decision: 'different-person', note: 'Different birth barangay on the certificates.');

        $finding = $this->getJson("/api/v1/admin/residents/{$one->uuid}/duplicate-findings")->assertOk()->json('data.0');

        $this->assertSame('different-person', $finding['decision']);
        $this->assertNotEmpty($finding['reason']);
        $this->assertSame((string) $two->uuid, $finding['other_resident_id']);

        // DL-73: agreement between fields, never the values.
        foreach (['first_name', 'last_name', 'birth_date', 'values', 'matched_values'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $finding);
        }
    }

    #[Test]
    public function an_undecided_pair_is_not_a_finding(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $one = $this->existingResident();
        $two = $this->existingResident(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'birth_date' => '1990-01-15']);

        $this->duplicatePair($one->id, $two->id);

        $this->getJson("/api/v1/admin/residents/{$one->uuid}/duplicate-findings")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── attacks ──────────────────────────────────────────────────────────────────────

    /**
     * The permission choice this endpoint makes, tested rather than asserted in a comment.
     *
     * A resident row says a person exists. A beneficiary row says what this office has done for
     * them — money received, programme rolls, an open request. Guarding it as an ordinary resident
     * read would make every holder of the registry a reader of everybody's assistance history.
     */
    #[Test]
    public function resident_view_alone_does_not_open_the_beneficiary_registry(): void
    {
        $resident = $this->seedAsAdmin();

        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'barangay_link');

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/beneficiaries')->assertForbidden();
        $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertForbidden();
    }

    #[Test]
    public function findings_are_refused_to_a_caller_who_may_read_the_registry_but_not_administer_identity(): void
    {
        $resident = $this->seedAsAdmin();

        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'social_worker');

        Sanctum::actingAs($account);

        $this->getJson("/api/v1/admin/residents/{$resident->uuid}/duplicate-findings")->assertForbidden();
    }

    #[Test]
    public function a_barangay_scoped_caller_sees_only_their_own_barangays_beneficiaries(): void
    {
        $resident = $this->seedAsAdmin();

        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'lgu_admin', $this->otherBarangayId());

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/beneficiaries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/beneficiaries/{$resident->uuid}")->assertNotFound();
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_none_of_it(): void
    {
        foreach ([
            '/api/v1/admin/beneficiaries',
            '/api/v1/admin/beneficiaries/01a00000-0000-7000-8000-000000000000',
            '/api/v1/admin/residents/01a00000-0000-7000-8000-000000000000/duplicate-findings',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    #[Test]
    public function both_collections_are_paginated(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        foreach ([
            '/api/v1/admin/beneficiaries',
            "/api/v1/admin/residents/{$resident->uuid}/duplicate-findings",
        ] as $path) {
            $this->assertArrayHasKey('pagination', $this->getJson($path)->assertOk()->json('meta'));
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────

    private function seedAsAdmin(): Resident
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        return $this->existingResident();
    }

    private function grantedCaseFor(string $residentUuid): int
    {
        return $this->welfareCase($residentUuid, 'approved');
    }

    private function openCaseFor(string $residentUuid): int
    {
        return $this->welfareCase($residentUuid, 'assessment');
    }

    private function welfareCase(string $residentUuid, string $status): int
    {
        return (int) DB::table('welfare_cases')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'case_number' => 'WC-'.strtoupper(Str::random(10)),
            'type' => 'aics',
            'resident_id' => $residentUuid,
            'barangay_id' => $this->barangayId(),
            'status' => $status,
            'priority' => 'normal',
            'opened_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function release(int $caseId, string $residentUuid, string $kind, ?int $centavos, string $status = 'released', int $sequence = 1): void
    {
        DB::table('releases')->insert([
            'uuid' => (string) Str::uuid7(),
            'reference_number' => 'RL-'.strtoupper(Str::random(10)),
            'welfare_case_id' => $caseId,
            'resident_id' => $residentUuid,
            'sequence' => $sequence,
            'kind' => $kind,
            'amount_centavos' => $centavos,
            'in_kind_description' => $kind === 'in-kind' ? 'One sack of rice' : null,
            'currency' => 'PHP',
            'release_mode' => 'cash-pickup',
            'status' => $status,
            'released_at' => $status === 'released' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enrolmentFor(string $residentUuid, string $programCode): void
    {
        DB::table('program_enrollments')->insert([
            'uuid' => (string) Str::uuid7(),
            'program_id' => (string) Str::uuid7(),
            'program_code' => $programCode,
            'resident_id' => $residentUuid,
            'status' => 'active',
            'effective_from' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function duplicatePair(int $lower, int $higher, string $decision = 'undecided', ?string $note = null): void
    {
        DB::table('resident_duplicate_pairs')->insert([
            'uuid' => (string) Str::uuid7(),
            'lower_resident_id' => min($lower, $higher),
            'higher_resident_id' => max($lower, $higher),
            'rule' => 'name-and-birth-date',
            'confidence' => 'strong',
            'decision' => $decision,
            'decided_at' => $decision === 'undecided' ? null : now(),
            'decision_note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
