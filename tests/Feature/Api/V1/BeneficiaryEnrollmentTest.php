<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Application\ResidentMergeService;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use Modules\Shared\Application\ActorContext;
use Modules\Welfare\Domain\EnrollmentStatus;
use Modules\Welfare\Infrastructure\Eloquent\ProgramEnrollment;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 14, as tests.
 *
 * The three this file exists for:
 *
 *  1. **One resident can hold many enrolments without a duplicate resident record** — there is
 *     no beneficiary person row anywhere, by design.
 *  2. **Historical enrolments remain queryable after exit.**
 *  3. **A citizen can read only their own authorised history.**
 *
 * Plus the defect this TAB found and closed: a resident merge left welfare cases attached to a
 * soft-deleted resident, because the merge service repointed only the consumers that existed
 * when it was written.
 */
final class BeneficiaryEnrollmentTest extends KycTestCase
{
    use RefreshDatabase;

    // ── one person, many enrolments, no duplicate rows ────────────────────────────────

    #[Test]
    public function one_resident_holds_many_enrolments_without_a_second_person_row(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->applicant();
        $first = $this->program('AICS');
        $second = $this->program('RELIEF');

        $this->enroll($first, $resident);
        $this->enroll($second, $resident);

        $this->assertSame(2, ProgramEnrollment::query()->where('resident_id', $resident->uuid)->count());

        /*
         * The point of the criterion. A `beneficiaries` table would be a second place a person
         * exists; it would drift from the canonical record after the first name correction, and
         * duplicate detection would have two populations to reconcile instead of one.
         */
        $this->assertSame(1, Resident::query()->where('uuid', $resident->uuid)->count());
    }

    #[Test]
    public function enrolling_twice_on_one_programme_does_not_double_count_the_beneficiary(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->applicant();
        $program = $this->program('AICS');

        $a = $this->enroll($program, $resident);
        $b = $this->enroll($program, $resident);

        // Two open enrolments is the same person counted twice on every roll, every manifest and
        // every payment run — double payment, arriving quietly.
        $this->assertSame($a, $b);
        $this->assertSame(1, ProgramEnrollment::query()->whereNull('effective_to')->count());
    }

    #[Test]
    public function the_database_itself_refuses_a_second_open_enrolment(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->applicant();
        $program = $this->program('AICS');
        $first = ProgramEnrollment::query()->where('uuid', $this->enroll($program, $resident))->firstOrFail();

        /*
         * The service returning the existing row is good manners; this is the guarantee. A write
         * path added later that forgets to ask still cannot put one person on a roll twice.
         */
        $this->expectException(QueryException::class);

        ProgramEnrollment::query()->create([
            'program_id' => $first->program_id,
            'program_code' => $first->program_code,
            'resident_id' => $first->resident_id,
            'status' => EnrollmentStatus::Active,
            'effective_from' => '2027-01-01',
        ]);
    }

    #[Test]
    public function a_resident_may_rejoin_a_programme_they_left_on_the_same_day(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->applicant();
        $program = $this->program('AICS');

        $first = $this->enroll($program, $resident);
        $this->postJson("/api/v1/admin/enrollments/{$first}/exit", ['exit_reason' => 'entered-in-error'])->assertOk();

        // A clerk who enrols the wrong person, exits them and enrols the right one does all three
        // within a minute. A key on the start date would have refused the third step.
        $second = $this->enroll($program, $resident);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, ProgramEnrollment::query()->whereNull('effective_to')->count());
    }

    #[Test]
    public function a_retired_programme_accepts_no_new_enrolments_but_keeps_its_existing_ones(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program('OLD');
        $existing = $this->applicant();
        $this->enroll($program, $existing);

        $this->postJson("/api/v1/admin/programs/{$program->uuid}/status", ['status' => 'retired'])->assertOk();

        $this->postJson('/api/v1/admin/enrollments', [
            'program_id' => (string) $program->uuid,
            'resident_id' => (string) $this->applicant()->uuid,
        ])->assertStatus(409);

        // People already on a closed programme still have a history; removing them would erase
        // what they received. Only the door closes.
        $this->assertSame(1, ProgramEnrollment::query()->whereNull('effective_to')->count());
    }

    // ── exit keeps history ────────────────────────────────────────────────────────────

    #[Test]
    public function an_exited_enrolment_remains_queryable(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->applicant();
        $program = $this->program('AICS');
        $enrollment = $this->enroll($program, $resident);

        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/exit", [
            'exit_reason' => 'moved-out-of-taytay',
        ])->assertOk()->assertJsonPath('data.status', 'exited');

        $history = $this->getJson("/api/v1/admin/residents/{$resident->uuid}/assistance-history")
            ->assertOk()->json('data');

        // The acceptance criterion, and the only way to see that somebody was removed from a
        // programme and by whom.
        $this->assertCount(1, $history['enrollments']);
        $this->assertSame('moved-out-of-taytay', $history['enrollments'][0]['exit_reason']);
        $this->assertNotNull($history['enrollments'][0]['effective_to']);
    }

    #[Test]
    public function an_exit_must_state_why(): void
    {
        Sanctum::actingAs($this->admin());

        $enrollment = $this->enroll($this->program('AICS'), $this->applicant());

        /*
         * "Graduated", "moved out", "no longer eligible" and "found to be duplicate" are four
         * different facts about a person. An unexplained exit is indistinguishable from all of
         * them later — including from an unauthorised removal.
         */
        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/exit", [])->assertStatus(422);
    }

    #[Test]
    public function an_ended_enrolment_cannot_be_revived(): void
    {
        Sanctum::actingAs($this->admin());

        $enrollment = $this->enroll($this->program('AICS'), $this->applicant());
        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/exit", ['exit_reason' => 'graduated'])->assertOk();

        // Reviving would rewrite a period the beneficiary was genuinely off the roll, and any
        // release made meanwhile would silently look authorised.
        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/status", ['status' => 'active'])
            ->assertStatus(409);
    }

    #[Test]
    public function a_suspension_is_reversible_without_ending_the_enrolment(): void
    {
        Sanctum::actingAs($this->admin());

        $enrollment = $this->enroll($this->program('AICS'), $this->applicant());

        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/status", [
            'status' => 'suspended',
            'note' => 'Under review for a possible double claim.',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        // A household under review is neither receiving nor removed. Forcing that into
        // exit-and-rejoin fills the roll with fragments every time somebody is queried and
        // cleared.
        $this->postJson("/api/v1/admin/enrollments/{$enrollment}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, ProgramEnrollment::query()->count());
    }

    #[Test]
    public function the_roll_can_be_asked_who_was_enrolled_on_a_past_date(): void
    {
        Sanctum::actingAs($this->admin());

        $program = $this->program('RELIEF');
        $stayed = $this->applicant();
        $left = $this->applicant();

        $this->enroll($program, $stayed, ['effective_from' => '2026-01-01']);
        $leftEnrollment = $this->enroll($program, $left, ['effective_from' => '2026-01-01']);

        $this->postJson("/api/v1/admin/enrollments/{$leftEnrollment}/exit", [
            'exit_reason' => 'graduated',
            'effective_to' => '2026-03-31',
        ])->assertOk();

        // "Who was on this roll when the October tranche went out" — the question a release
        // audit actually asks, answered from the effective dates rather than today's roll.
        $this->getJson("/api/v1/admin/enrollments?program_id={$program->uuid}&as_of=2026-02-15")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/admin/enrollments?program_id={$program->uuid}&as_of=2026-06-15")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── the merge defect this TAB closed ──────────────────────────────────────────────

    #[Test]
    public function a_resident_merge_moves_welfare_cases_to_the_survivor(): void
    {
        Sanctum::actingAs($this->admin());

        [$survivor, $absorbed] = $this->duplicatePair();

        $case = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $absorbed->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');

        $this->mergeInto($survivor, $absorbed);

        /*
         * The defect: before this listener existed, the case survived and the person it was
         * about did not. The applicant's own `me/cases` would have gone empty while staff
         * continued working the file — and nothing failed loudly, which is why it needed looking
         * for rather than waiting for.
         */
        $this->assertSame(
            (string) $survivor->uuid,
            (string) WelfareCase::query()->where('uuid', $case)->firstOrFail()->resident_id,
        );

        $this->assertDatabaseHas('assistance_intakes', ['resident_id' => (string) $survivor->uuid]);
    }

    #[Test]
    public function a_merge_does_not_leave_the_survivor_enrolled_twice_on_one_programme(): void
    {
        Sanctum::actingAs($this->admin());

        [$survivor, $absorbed] = $this->duplicatePair();
        $program = $this->program('AICS');

        $this->enroll($program, $survivor);
        $this->enroll($program, $absorbed);

        $this->mergeInto($survivor, $absorbed);

        /*
         * Moving both would leave the survivor counted twice on every payment run — the
         * double-payment state, arriving through the one path that bypasses `enroll()`. The
         * overlap is collapsed instead.
         */
        $open = ProgramEnrollment::query()
            ->where('resident_id', (string) $survivor->uuid)
            ->whereNull('effective_to')
            ->get();

        $this->assertCount(1, $open);

        // …and the absorbed one survives as history, with a reason that says what happened.
        $this->assertDatabaseHas('program_enrollments', [
            'resident_id' => (string) $survivor->uuid,
            'exit_reason' => 'merged-duplicate-enrolment',
        ]);
    }

    #[Test]
    public function a_merged_applicant_can_still_see_their_own_records(): void
    {
        [$account, $absorbed] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($absorbed);
        $this->driveToApproved($case);

        // The survivor is a second record of the same person, held by nobody in particular.
        $survivor = $this->existingResident([
            'first_name' => $absorbed->first_name,
            'middle_name' => null,
            'last_name' => $absorbed->last_name,
            'birth_date' => $absorbed->birth_date instanceof \DateTimeInterface
                ? $absorbed->birth_date->format('Y-m-d')
                : (string) $absorbed->birth_date,
            'street_address' => '99 Merge Street',
        ]);

        Sanctum::actingAs($this->admin());
        $this->mergeInto($survivor, $absorbed);

        /*
         * THE WHOLE POINT. Repointing the case is worthless while the citizen still reads through
         * a stale `accounts.resident_id` — their records would have moved to the survivor and
         * they would be the only party unable to see any of them, while staff read a complete and
         * correct file. Two defects one layer apart, and only the pair of them is a fix.
         */
        Sanctum::actingAs($account);
        $this->getJson('/api/v1/me/cases')->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(1, $this->getJson('/api/v1/me/assistance-history')->assertOk()->json('data.received'));
    }

    #[Test]
    public function a_merge_carries_non_overlapping_enrolments_across(): void
    {
        Sanctum::actingAs($this->admin());

        [$survivor, $absorbed] = $this->duplicatePair();

        $this->enroll($this->program('AICS'), $survivor);
        $this->enroll($this->program('RELIEF'), $absorbed);

        $this->mergeInto($survivor, $absorbed);

        // Different programmes are not a duplicate; the survivor legitimately holds both.
        $this->assertSame(2, ProgramEnrollment::query()
            ->where('resident_id', (string) $survivor->uuid)
            ->whereNull('effective_to')
            ->count());
    }

    // ── assistance history ────────────────────────────────────────────────────────────

    #[Test]
    public function the_history_reports_granted_cases_and_not_open_ones(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->applicant();

        $open = $this->caseFor($resident);
        $granted = $this->caseFor($resident);

        $this->driveToApproved($granted);

        Sanctum::actingAs($this->admin());
        $history = $this->getJson("/api/v1/admin/residents/{$resident->uuid}/assistance-history")
            ->assertOk()->json('data');

        $caseIds = array_column($history['granted'], 'case_id');
        $this->assertContains($granted, $caseIds);
        $this->assertNotContains($open, $caseIds);
    }

    #[Test]
    public function an_approved_case_with_no_release_reports_nothing_received(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->applicant();
        $case = $this->caseFor($resident);
        $this->driveToApproved($case);

        Sanctum::actingAs($this->admin());
        $granted = $this->getJson("/api/v1/admin/residents/{$resident->uuid}/assistance-history")
            ->assertOk()->json('data.granted.0');

        /*
         * TAB 14 published this key as null so TAB 18 could fill it without a client change, and
         * TAB 18 did. What it now asserts is the stronger property the placeholder was standing
         * in for: **approval is not receipt.**
         *
         * A case approved for ₱5,000 with no release has received nothing, and the history says
         * zero rather than the approved figure — reporting what was approved would tell a family
         * they were given money they never saw (ADR 0023 §7).
         */
        $this->assertArrayHasKey('released_amount_centavos', $granted);
        $this->assertSame(0, $granted['released_amount_centavos']);
        $this->assertSame('PHP', $granted['currency']);
    }

    // ── the citizen view ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_reads_only_their_own_received_assistance(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        [$other] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);
        $this->driveToApproved($case);

        Sanctum::actingAs($account);
        $mine = $this->getJson('/api/v1/me/assistance-history')->assertOk()->json('data.received');
        $this->assertCount(1, $mine);

        // Resolved from the token; there is no identifier in the contract to tamper with.
        Sanctum::actingAs($other);
        $this->assertCount(0, $this->getJson('/api/v1/me/assistance-history')->assertOk()->json('data.received'));
    }

    #[Test]
    public function the_citizen_history_omits_staff_operational_fields(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($resident);
        $this->driveToApproved($case);

        Sanctum::actingAs($account);
        $entry = $this->getJson('/api/v1/me/assistance-history')->assertOk()->json('data.received.0');

        // Additive projection, like every other citizen view here: a field is absent until
        // somebody decides it belongs.
        foreach (['barangay_id', 'program_id', 'case_id', 'assigned_to', 'priority'] as $field) {
            $this->assertArrayNotHasKey($field, $entry);
        }

        $this->assertArrayHasKey('reference', $entry);
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function enrolling_requires_more_than_reading_the_roll(): void
    {
        Sanctum::actingAs($this->staff());

        // Front-line staff answer "am I enrolled?" at the counter. Putting a name on a roll is
        // money-adjacent and is not theirs.
        $this->getJson('/api/v1/admin/enrollments')->assertOk();

        $this->postJson('/api/v1/admin/enrollments', [
            'program_id' => (string) $this->program('AICS')->uuid,
            'resident_id' => (string) $this->applicant()->uuid,
        ])->assertForbidden();
    }

    #[Test]
    public function a_beneficiary_outside_the_callers_barangay_cannot_be_enrolled(): void
    {
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        $elsewhere = $this->applicant(['barangay_id' => $this->otherBarangayId()]);

        // Otherwise a clerk could put somebody from another barangay onto a roll they cannot
        // then see or audit.
        $this->postJson('/api/v1/admin/enrollments', [
            'program_id' => (string) $this->program('AICS')->uuid,
            'resident_id' => (string) $elsewhere->uuid,
        ])->assertNotFound();
    }

    #[Test]
    public function a_citizen_holds_no_enrolment_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/enrollments')->assertForbidden();
        $this->postJson('/api/v1/admin/enrollments', [])->assertForbidden();
    }

    #[Test]
    public function enrolment_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/enrollments')->assertUnauthorized();
        $this->getJson('/api/v1/me/assistance-history')->assertUnauthorized();
    }

    #[Test]
    public function enrolling_is_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->enroll($this->program('AICS'), $this->applicant());

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'enrollment.created',
            'actor_subject_id' => (string) $admin->uuid,
        ]);
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

    private function program(string $code): Program
    {
        $program = Program::query()->firstOrCreate(['code' => $code], [
            'name' => $code.' programme',
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'published',
            'is_citizen_visible' => true,
            'eligibility_guidance_version' => '1',
        ]);

        ProgramRequirement::query()->firstOrCreate(
            ['program_id' => $program->id, 'code' => 'valid-id', 'template_version' => '1'],
            [
                'label' => 'Valid identification',
                'obligation' => 'required',
                'citizen_instructions' => 'Bring any government-issued ID.',
            ],
        );

        return $program;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function applicant(array $overrides = []): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident(array_merge([
            'first_name' => 'Ben'.$n,
            'middle_name' => null,
            'last_name' => 'Eficiary',
            'birth_date' => '1984-04-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function enroll(Program $program, Resident $resident, array $extra = []): string
    {
        return $this->postJson('/api/v1/admin/enrollments', $extra + [
            'program_id' => (string) $program->uuid,
            'resident_id' => (string) $resident->uuid,
        ])->assertCreated()->json('data.id');
    }

    /**
     * Two residents sharing an identity fingerprint, so the merge service will accept the pair.
     *
     * @return array{Resident, Resident}
     */
    private function duplicatePair(): array
    {
        return [
            $this->existingResident(['first_name' => 'Dup', 'middle_name' => null, 'last_name' => 'Licate']),
            $this->existingResident([
                'first_name' => 'Dup', 'middle_name' => null, 'last_name' => 'Licate',
                'street_address' => '48 Bonifacio Street',
            ]),
        ];
    }

    private function mergeInto(Resident $survivor, Resident $absorbed): void
    {
        $service = app(ResidentMergeService::class);
        $pair = $service->recordPair($survivor, $absorbed, 'name-and-birth-date', 'exact');
        $pair->forceFill(['decision' => 'same-person', 'decided_at' => now()])->save();

        $service->merge($survivor, $absorbed, $this->actorFor($this->admin()), 'Duplicate enrolment.', $pair->refresh());
    }

    private function actorFor(Account $account): ActorContext
    {
        return ActorContext::authenticated((string) $account->uuid);
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
     * Drives a case to `approved`, respecting separation of duties along the way.
     */
    private function driveToApproved(string $case): void
    {
        Sanctum::actingAs($this->staff());

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'approved'])->assertOk();
    }
}
