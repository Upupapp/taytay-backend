<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Reporting\Application\MetricsService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Four more TAB 07 rows: requirement templates, programme utilisation (×2), and the data
 * classification register.
 */
final class ProgramAndGovernanceReadTest extends KycTestCase
{
    use RefreshDatabase;

    // ── requirement templates ────────────────────────────────────────────────────────

    #[Test]
    public function every_published_version_of_a_requirement_is_readable(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $program = $this->program();

        $this->publishRequirement($program, 'barangay-cert', 'Barangay certificate');
        $this->publishRequirement($program, 'barangay-cert', 'Barangay certificate of indigency');

        $versions = collect($this->getJson("/api/v1/admin/programs/{$program}/requirement-templates")->assertOk()->json('data'));

        $this->assertCount(2, $versions, 'Republishing writes a second row; the superseded wording is the evidence of what was asked for.');

        // Newest first.
        $this->assertSame('2', $versions[0]['template_version']);
        $this->assertTrue($versions[0]['requirements'][0]['is_current']);
        $this->assertFalse($versions[1]['requirements'][0]['is_current']);
    }

    /**
     * The defect TAB 07 found while building the read.
     *
     * `currentRequirements()` had no version filter despite its name, so a programme detail listed
     * both rows and showed the same requirement twice — once with the wording the office had
     * already replaced.
     */
    #[Test]
    public function a_programme_detail_shows_each_requirement_once_at_its_latest_wording(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $program = $this->program();

        $this->publishRequirement($program, 'barangay-cert', 'Barangay certificate');
        $this->publishRequirement($program, 'barangay-cert', 'Barangay certificate of indigency');

        $requirements = $this->getJson("/api/v1/programs/{$program}")->assertOk()->json('data.requirements');

        $this->assertCount(1, $requirements);
        $this->assertSame('Barangay certificate of indigency', $requirements[0]['label']);
    }

    #[Test]
    public function reading_the_template_history_needs_catalogue_administration(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $program = $this->program();

        // Holds program.view — enough for the catalogue, not for its history.
        $account = Account::factory()->staff()->create();
        // Holds `program.view` and not `program.manage`: enough for the catalogue, not its history.
        $this->grantRole($account, 'lgu_staff');

        Sanctum::actingAs($account);

        $this->getJson("/api/v1/admin/programs/{$program}/requirement-templates")->assertForbidden();
    }

    // ── utilisation ──────────────────────────────────────────────────────────────────

    #[Test]
    public function utilisation_answers_for_the_catalogue_and_for_one_programme(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $program = $this->program();

        $all = $this->getJson('/api/v1/admin/programs/utilization')->assertOk();
        $this->assertNull($all->json('data.program_id'));
        $this->assertIsArray($all->json('data.rows'));

        $one = $this->getJson("/api/v1/admin/programs/{$program}/utilization")->assertOk();
        $this->assertSame($program, $one->json('data.program_id'));
    }

    /**
     * Suppression is inherited, not reimplemented.
     *
     * A second implementation of "how much has gone out" is a second answer, and the two disagree
     * the first time one of them forgets that an in-kind release has no peso value.
     */
    #[Test]
    public function utilisation_reports_the_same_suppression_rule_as_every_other_aggregate(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $body = $this->getJson('/api/v1/admin/programs/utilization')->assertOk();

        $this->assertSame(MetricsService::MINIMUM_CELL, $body->json('meta.suppression.minimum_cell'));
        $this->assertSame('withheld', $body->json('meta.suppression.method'));
    }

    // ── classifications ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_register_classifies_every_category_that_has_a_retention_period(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $body = $this->getJson('/api/v1/admin/privacy/classifications')->assertOk();

        $categories = collect($body->json('data.categories'))->keyBy('key');

        $this->assertNotEmpty($categories);

        foreach ($categories as $category) {
            // A category with a period and no classification is reported, not skipped. A gap in a
            // privacy register is the finding.
            $this->assertFalse($category['unclassified'], "{$category['key']} has a retention period and no classification.");
            $this->assertNotEmpty($category['holds']);
            $this->assertNotNull($category['retention_days']);
        }
    }

    /**
     * The category the register exists for.
     *
     * RA 9262 and RA 9344 material is the one place where retention and protection point in
     * opposite directions, and the protective answer wins — so it carries the highest
     * classification and the shortest period in the same row.
     */
    #[Test]
    public function safeguarding_is_sensitive_personal_and_kept_shortest(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $categories = collect($this->getJson('/api/v1/admin/privacy/classifications')->assertOk()->json('data.categories'))
            ->keyBy('key');

        $this->assertSame('sensitive-personal', $categories['safeguarding']['classification']);

        $this->assertLessThan(
            $categories['resident']['retention_days'],
            $categories['safeguarding']['retention_days'],
            'Holding safeguarding material longer than the case needs is itself the risk.',
        );
    }

    #[Test]
    public function the_register_names_nobody(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $body = $this->getJson('/api/v1/admin/privacy/classifications')->assertOk();

        // Reference data about nobody: no resident, no case, no number about a person.
        $this->assertStringNotContainsString('resident_id', (string) $body->getContent());
        $this->assertStringNotContainsString('@', (string) $body->getContent());
    }

    #[Test]
    public function the_register_says_it_is_not_yet_approved(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $body = $this->getJson('/api/v1/admin/privacy/classifications')->assertOk();

        $this->assertFalse($body->json('data.approved'));

        // Stated in the payload, not just in a docblock: a console rendering this table must be
        // able to say in the interface that nothing here is confirmed yet.
        $this->assertNotEmpty($body->json('data.notice'));
    }

    #[Test]
    public function the_register_is_refused_to_an_administrator_who_does_not_govern_privacy(): void
    {
        $account = Account::factory()->staff()->create();
        // An administrator, refused — which is the sharper version of this rule: privacy
        // governance is the DPO's, and `lgu_admin` holds everything else.
        $this->grantRole($account, 'lgu_admin');

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/privacy/classifications')->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_none_of_these(): void
    {
        foreach ([
            '/api/v1/admin/privacy/classifications',
            '/api/v1/admin/programs/utilization',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    // ── fixtures, built through the API ──────────────────────────────────────────────

    private function program(): string
    {
        return (string) $this->postJson('/api/v1/admin/programs', [
            'code' => 'AICS',
            'name' => 'Assistance to Individuals in Crisis Situations',
            'owner_office' => 'MSWDO',
            'service_type' => 'financial-assistance',
            'benefit_type' => 'cash',
            'authority' => 'national',
        ])->assertCreated()->json('data.id');
    }

    /** Publishing the same code twice appends a version; the API assigns the number. */
    private function publishRequirement(string $program, string $code, string $label): void
    {
        $this->postJson("/api/v1/admin/programs/{$program}/requirements", [
            'code' => $code,
            'label' => $label,
            'obligation' => 'required',
            'citizen_instructions' => 'Ask your barangay hall for this.',
        ])->assertSuccessful();
    }
}
