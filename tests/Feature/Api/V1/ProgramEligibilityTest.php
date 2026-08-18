<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentSector;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramEligibilityCriterion;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use Modules\Welfare\Infrastructure\Eloquent\CaseEligibilityCheck;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 13, as tests.
 *
 * The three this file exists for:
 *
 *  1. **Policy updates do not require rewriting controllers** — a programme, its requirements
 *     and its guidance are rows, and changing them changes behaviour.
 *  2. **Inactive and internal programmes are not exposed publicly** — filtered at the query, so
 *     they are absent from the rows and from the total.
 *  3. **The eligibility guidance version used in a case is retained for audit** — pinned at
 *     evaluation, with every criterion outcome.
 *
 * And the one that spans TABs: **guidance flags, it never decides.**
 */
final class ProgramEligibilityTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the public catalogue ──────────────────────────────────────────────────────────

    #[Test]
    public function the_public_catalogue_shows_only_published_and_citizen_visible_programmes(): void
    {
        $visible = $this->program(['code' => 'AICS', 'name' => 'AICS'], publish: true, citizenVisible: true);
        $internal = $this->program(['code' => 'INTERNAL', 'name' => 'Internal referral'], publish: true, citizenVisible: false);
        $draft = $this->program(['code' => 'DRAFT', 'name' => 'Not yet announced']);

        $payload = $this->getJson('/api/v1/programs')->assertOk()->json();

        $codes = array_column($payload['data'], 'code');
        $this->assertSame(['AICS'], $codes);

        /*
         * Absent from the total as well as the rows. A count that included the internal
         * programme would tell an anonymous caller how many programmes the LGU runs that it has
         * not announced.
         */
        $this->assertSame(1, $payload['meta']['pagination']['total']);

        // …and a guessed id is NOT FOUND, never FORBIDDEN — a 403 confirms it exists.
        $this->getJson("/api/v1/programs/{$internal->uuid}")->assertNotFound();
        $this->getJson("/api/v1/programs/{$draft->uuid}")->assertNotFound();
    }

    #[Test]
    public function staff_see_drafts_through_the_same_endpoint(): void
    {
        $this->program(['code' => 'DRAFT', 'name' => 'Not yet announced']);

        Sanctum::actingAs($this->admin());

        // One controller, one service, two audiences. What a caller sees is decided by their
        // permissions, never by which URL they used.
        $this->getJson('/api/v1/programs')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'DRAFT')
            ->assertJsonPath('data.0.status', 'draft');
    }

    #[Test]
    public function a_programme_cannot_be_published_without_requirements(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'BARE', 'name' => 'Bare programme'], withRequirement: false);

        /*
         * A published programme with no requirements tells an applicant to bring nothing, and
         * then the office asks for documents at the counter. That is the commonest way a person
         * makes two trips they cannot afford.
         */
        $this->postJson("/api/v1/admin/programs/{$program->uuid}/status", ['status' => 'published'])
            ->assertStatus(409);
    }

    #[Test]
    public function the_citizen_detail_carries_requirements_and_worded_conditions_but_no_thresholds(): void
    {
        $program = $this->program(['code' => 'AICS', 'name' => 'AICS'], publish: true, citizenVisible: true);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'You must be 60 or older.', 'is_blocking' => true,
        ]);

        $data = $this->getJson("/api/v1/programs/{$program->uuid}")->assertOk()->json('data');

        $this->assertSame(['You must be 60 or older.'], $data['conditions']);
        $this->assertNotEmpty($data['requirements']);

        /*
         * The words, never the numbers. Publishing the comparator and threshold would turn an
         * assistance programme into a form to be gamed, and the people who would game it
         * successfully are not the ones it exists for.
         */
        $body = json_encode($data);
        $this->assertStringNotContainsString('at-least', (string) $body);
        $this->assertStringNotContainsString('is_blocking', (string) $body);
    }

    #[Test]
    public function a_national_programme_says_the_lgu_does_not_decide_it(): void
    {
        $program = $this->program(
            ['code' => '4PS', 'name' => 'Pantawid Pamilyang Pilipino Program', 'authority' => 'national'],
            publish: true,
            citizenVisible: true,
        );

        // The LGU tracks and refers; DSWD sets eligibility. An applicant deciding whether to
        // travel to an office deserves to know the LGU does not control the answer.
        $this->getJson("/api/v1/programs/{$program->uuid}")
            ->assertOk()
            ->assertJsonPath('data.decided_by', 'national');
    }

    // ── policy lives in rows ──────────────────────────────────────────────────────────

    #[Test]
    public function changing_guidance_changes_the_outcome_without_touching_code(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SENIOR', 'name' => 'Senior support'], publish: true, citizenVisible: true);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'You must be 60 or older.', 'is_blocking' => true,
        ]);

        // Born 1990 — under 60.
        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));

        $this->assertSame('likely-ineligible', $this->runCheck($case, $program)['outcome']);

        // Same code, different row.
        $program->criteria()->update(['value' => '30']);

        $this->assertSame('likely-eligible', $this->runCheck($case, $program)['outcome']);
    }

    #[Test]
    public function a_new_guidance_version_copies_the_criteria_forward(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '18',
            'citizen_explanation' => 'You must be an adult.',
        ]);

        $this->postJson("/api/v1/admin/programs/{$program->uuid}/guidance-versions", ['version' => '2'])
            ->assertOk()
            ->assertJsonPath('data.eligibility_guidance_version', '2');

        // Copied forward rather than edited in place, so a check pinned to version 1 still
        // resolves to the rules that actually applied to it.
        $this->assertSame(2, $program->criteria()->count());
    }

    // ── the audit requirement ─────────────────────────────────────────────────────────

    #[Test]
    public function the_guidance_version_used_is_pinned_and_survives_a_later_change(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '18',
            'citizen_explanation' => 'You must be an adult.',
        ]);

        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));
        $check = $this->runCheck($case, $program);

        $this->assertSame('1', $check['guidance_version']);

        // The office moves on.
        $this->postJson("/api/v1/admin/programs/{$program->uuid}/guidance-versions", ['version' => '2'])->assertOk();

        /*
         * The acceptance criterion: a decision defended two years later must be re-derivable
         * against the rules that actually applied, not against today's.
         */
        $stored = CaseEligibilityCheck::query()->where('uuid', $check['id'])->firstOrFail();
        $this->assertSame('1', (string) $stored->guidance_version);
    }

    #[Test]
    public function every_criterion_outcome_is_recorded_with_its_observed_value(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SENIOR', 'name' => 'Senior support']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'You must be 60 or older.', 'is_blocking' => true,
        ]);

        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));
        $check = $this->runCheck($case, $program);

        $result = $check['results'][0];

        // A caseworker must be able to check the outcome rather than trust it — the difference
        // between guidance and an oracle.
        $this->assertSame('not-met', $result['result']);
        $this->assertSame('You must be 60 or older.', $result['explanation']);
        $this->assertNotNull($result['observed_value']);
        $this->assertTrue($check['is_advisory']);
    }

    // ── guidance flags; it never decides ──────────────────────────────────────────────

    #[Test]
    public function a_likely_ineligible_outcome_does_not_move_the_case(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SENIOR', 'name' => 'Senior support']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'You must be 60 or older.', 'is_blocking' => true,
        ]);

        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));
        $before = WelfareCase::query()->where('uuid', $case)->firstOrFail()->status->value;

        $this->assertSame('likely-ineligible', $this->runCheck($case, $program)['outcome']);

        /*
         * Refusal is CaseStatus::Rejected, which needs `request.reject`, a mandatory reason and
         * a human. Nothing here approaches it.
         */
        $after = WelfareCase::query()->where('uuid', $case)->firstOrFail();
        $this->assertSame($before, $after->status->value);
        $this->assertSame('normal', $after->priority->value);
    }

    #[Test]
    public function an_unknown_fact_sends_the_case_to_a_human_rather_than_refusing_it(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'LOWINC', 'name' => 'Low income support']);
        $this->addCriterion($program, [
            'code' => 'income', 'fact' => 'monthly-income', 'comparator' => 'at-most', 'value' => '1000000',
            'citizen_explanation' => 'Household income must be below PHP 10,000 a month.', 'is_blocking' => true,
        ]);

        // No income recorded for this applicant.
        $case = $this->caseFor($this->applicant());

        $check = $this->runCheck($case, $program);

        /*
         * A missing income figure means nobody has asked yet, not that the applicant earns too
         * much. Treating absence as failure would turn every incomplete record into a refusal —
         * and incomplete records belong overwhelmingly to the people least able to complete them.
         */
        $this->assertSame('needs-review', $check['outcome']);
        $this->assertSame('unknown', $check['results'][0]['result']);
    }

    #[Test]
    public function an_unknown_fact_outranks_a_clear_mismatch(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'MIXED', 'name' => 'Mixed criteria']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'You must be 60 or older.', 'is_blocking' => true,
        ]);
        $this->addCriterion($program, [
            'code' => 'income', 'fact' => 'monthly-income', 'comparator' => 'at-most', 'value' => '1000000',
            'citizen_explanation' => 'Household income must be below PHP 10,000 a month.', 'is_blocking' => true,
        ]);

        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));

        // The unknown might be the thing that explains the mismatch, so a human looks.
        $this->assertSame('needs-review', $this->runCheck($case, $program)['outcome']);
    }

    #[Test]
    public function a_non_blocking_mismatch_is_never_a_mismatch_verdict(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SOFT', 'name' => 'Soft criteria']);
        $this->addCriterion($program, [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
            'citizen_explanation' => 'This programme is aimed at seniors.', 'is_blocking' => false,
        ]);

        $case = $this->caseFor($this->applicant(['birth_date' => '1990-01-15']));

        $this->assertSame('needs-review', $this->runCheck($case, $program)['outcome']);
    }

    #[Test]
    public function a_programme_with_no_guidance_needs_review_rather_than_admitting_everybody(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'NORULES', 'name' => 'No guidance yet']);
        $case = $this->caseFor($this->applicant());

        // A programme with no criteria is not one everybody qualifies for. It is one nobody has
        // written rules for yet, which is a question for staff.
        $this->assertSame('needs-review', $this->runCheck($case, $program)['outcome']);
    }

    // ── the facts guidance may read ───────────────────────────────────────────────────

    #[Test]
    public function the_vulnerability_score_is_not_an_available_eligibility_fact(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);

        /*
         * The convergence point of G-20 and this TAB. The score is unapproved placeholder
         * weighting that declares itself decision-support-only; letting it decide eligibility
         * would make an unapproved ordering consequential, one layer removed from anybody who
         * could see it happening.
         */
        $this->postJson("/api/v1/admin/programs/{$program->uuid}/eligibility-criteria", [
            'code' => 'score',
            'fact' => 'vulnerability-score',
            'comparator' => 'at-least',
            'value' => '50',
            'citizen_explanation' => 'You must be sufficiently vulnerable.',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_criterion_cannot_be_stored_without_an_explanation(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);

        // A criterion nobody can explain to the person it excludes is the opaque denial itself,
        // so there is no way to store one.
        $this->postJson("/api/v1/admin/programs/{$program->uuid}/eligibility-criteria", [
            'code' => 'age', 'fact' => 'age', 'comparator' => 'at-least', 'value' => '60',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_comparator_the_fact_does_not_support_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);

        $this->postJson("/api/v1/admin/programs/{$program->uuid}/eligibility-criteria", [
            'code' => 'barangay', 'fact' => 'barangay', 'comparator' => 'at-least', 'value' => '1',
            'citizen_explanation' => 'You must live in Taytay.',
        ])->assertStatus(422);
    }

    #[Test]
    public function safeguarding_sectors_are_not_visible_to_eligibility_guidance(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SECTOR', 'name' => 'Sector programme']);
        $this->addCriterion($program, [
            'code' => 'sector', 'fact' => 'sector', 'comparator' => 'is-one-of', 'value' => 'vawc-survivor|senior-citizen',
            'citizen_explanation' => 'This programme is for specific sectors.', 'is_blocking' => true,
        ]);

        $applicant = $this->applicant();
        ResidentSector::query()->create(['resident_id' => $applicant->id, 'sector' => 'vawc-survivor']);

        $case = $this->caseFor($applicant);
        $check = $this->runCheck($case, $program);

        /*
         * A criterion reading `vawc-survivor` would leak protection status to everyone who can
         * see a guidance result — the same disclosure ADR 0015 §4 keeps out of the vulnerability
         * score, arriving by a different route. The sector is simply not among the facts, so the
         * criterion reads `unknown`.
         */
        $this->assertSame('unknown', $check['results'][0]['result']);
        $this->assertNull($check['results'][0]['observed_value']);
    }

    #[Test]
    public function sector_membership_is_matched_across_a_residents_tags(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program(['code' => 'SENIORS', 'name' => 'Seniors']);
        $this->addCriterion($program, [
            'code' => 'sector', 'fact' => 'sector', 'comparator' => 'is-one-of', 'value' => 'senior-citizen|pwd',
            'citizen_explanation' => 'For senior citizens and persons with disability.', 'is_blocking' => true,
        ]);

        $applicant = $this->applicant();
        ResidentSector::query()->create(['resident_id' => $applicant->id, 'sector' => 'solo-parent']);
        ResidentSector::query()->create(['resident_id' => $applicant->id, 'sector' => 'pwd']);

        // A resident carries several tags; "is one of" is satisfied by any one of them.
        $this->assertSame('likely-eligible', $this->runCheck($this->caseFor($applicant), $program)['outcome']);
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function authoring_a_programme_requires_the_manage_permission(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_staff'));

        // Front-line staff read the catalogue to advise applicants; authoring is not theirs.
        $this->postJson('/api/v1/admin/programs', [
            'code' => 'X', 'name' => 'X', 'owner_office' => 'MSWDO',
            'service_type' => 'financial', 'benefit_type' => 'cash',
        ])->assertForbidden();

        $this->getJson('/api/v1/programs')->assertOk();
    }

    #[Test]
    public function there_is_no_citizen_eligibility_endpoint(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        // "You are likely ineligible" reads as a refusal however it is worded, and it is not
        // one — nobody has decided anything.
        $this->getJson('/api/v1/me/eligibility')->assertNotFound();
    }

    #[Test]
    public function running_a_check_is_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $program = $this->program(['code' => 'AICS', 'name' => 'AICS']);
        $case = $this->caseFor($this->applicant());

        $this->runCheck($case, $program);

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'eligibility.checked',
            'entity_id' => $case,
            'actor_subject_id' => (string) $admin->uuid,
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function program(
        array $attributes,
        bool $publish = false,
        bool $citizenVisible = false,
        bool $withRequirement = true,
    ): Program {
        $program = Program::query()->create($attributes + [
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'draft',
            'is_citizen_visible' => $citizenVisible,
            'eligibility_guidance_version' => '1',
        ]);

        if ($withRequirement) {
            ProgramRequirement::query()->create([
                'program_id' => $program->id,
                'code' => 'valid-id',
                'label' => 'Valid government identification',
                'obligation' => 'required',
                'citizen_instructions' => 'Bring any government-issued ID.',
                'template_version' => '1',
            ]);
        }

        if ($publish) {
            $program->forceFill(['status' => 'published'])->save();
        }

        return $program->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addCriterion(Program $program, array $attributes): void
    {
        ProgramEligibilityCriterion::query()->create($attributes + [
            'program_id' => $program->id,
            'guidance_version' => $program->eligibility_guidance_version,
            'is_blocking' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function applicant(array $overrides = []): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident(array_merge([
            'first_name' => 'App'.$n,
            'middle_name' => null,
            'last_name' => 'Licant',
            'birth_date' => '1985-05-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT),
        ], $overrides));
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
     * @return array<string, mixed>
     */
    private function runCheck(string $case, Program $program): array
    {
        return $this->postJson("/api/v1/admin/assistance-requests/{$case}/eligibility-checks", [
            'program_id' => (string) $program->refresh()->uuid,
        ])->assertCreated()->json('data');
    }
}
