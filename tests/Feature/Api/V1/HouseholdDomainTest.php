<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\FamilyMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentRelationship;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 09, as tests.
 *
 * The three this file exists for:
 *
 *  1. **Multiple families per household are supported.** A household is who sleeps under one
 *     roof; a family is who belongs to one another, and Philippine households routinely hold
 *     several.
 *  2. **Moving a resident does not erase historical memberships.** "Who lived here when the
 *     October relief was released" must stay answerable after the family moves in November.
 *  3. **A citizen household response exposes no member case detail.** Sharing a roof is not
 *     consent to be looked up.
 */
final class HouseholdDomainTest extends KycTestCase
{
    use RefreshDatabase;

    // ── structure ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_household_holds_several_families_at_once(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();

        $first = $this->postJson("/api/v1/admin/households/{$household->uuid}/families", ['label' => 'Dela Cruz'])
            ->assertCreated()->json('data.id');
        $second = $this->postJson("/api/v1/admin/households/{$household->uuid}/families", ['label' => 'Reyes'])
            ->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);

        // Relief goods are distributed per household and grants per family; if these two
        // counts cannot differ, one of them is permanently wrong.
        $this->getJson("/api/v1/admin/households/{$household->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'data.families');
    }

    #[Test]
    public function member_count_is_derived_and_never_drifts(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $a = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);
        $b = $this->existingResident(['first_name' => 'Ben', 'last_name' => 'Lim', 'birth_date' => '1982-05-05']);

        $this->addMember($household, $a);
        $this->addMember($household, $b);

        $this->getJson("/api/v1/admin/households/{$household->uuid}")
            ->assertOk()
            ->assertJsonPath('data.member_count', 2);

        $this->deleteJson("/api/v1/admin/households/{$household->uuid}/members/{$b->uuid}", [
            'end_reason' => 'moved-out',
        ])->assertOk();

        // There is no stored counter to forget to decrement, which is the whole point.
        $this->getJson("/api/v1/admin/households/{$household->uuid}")
            ->assertOk()
            ->assertJsonPath('data.member_count', 1);
    }

    #[Test]
    public function the_head_of_a_household_must_live_in_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $outsider = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        // A head who does not live there is either a data error or an attempt to attach an
        // outsider to a household's assistance.
        $this->postJson("/api/v1/admin/households/{$household->uuid}/head", [
            'resident_id' => (string) $outsider->uuid,
        ])->assertStatus(409);

        $this->addMember($household, $outsider);

        $this->postJson("/api/v1/admin/households/{$household->uuid}/head", [
            'resident_id' => (string) $outsider->uuid,
        ])->assertOk()->assertJsonPath('data.head.id', (string) $outsider->uuid);
    }

    #[Test]
    public function removing_the_head_clears_headship_rather_than_leaving_a_ghost(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $head = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $this->addMember($household, $head);
        $this->postJson("/api/v1/admin/households/{$household->uuid}/head", ['resident_id' => (string) $head->uuid])
            ->assertOk();

        $this->deleteJson("/api/v1/admin/households/{$household->uuid}/members/{$head->uuid}", [
            'end_reason' => 'moved-out',
        ])->assertOk();

        // Otherwise the LGU keeps addressing letters to somebody at an address they left.
        $this->getJson("/api/v1/admin/households/{$household->uuid}")
            ->assertOk()
            ->assertJsonPath('data.head', null);
    }

    // ── membership is effective-dated ─────────────────────────────────────────────────

    #[Test]
    public function adding_a_member_twice_does_not_double_count_them(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $this->addMember($household, $resident);
        $this->addMember($household, $resident);

        $this->assertSame(1, HouseholdMembership::query()
            ->where('household_id', $household->id)
            ->whereNull('effective_to')
            ->count());
    }

    #[Test]
    public function a_resident_cannot_belong_to_two_households_at_once(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $first = $this->household();
        $second = $this->household();
        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $this->addMember($first, $resident);

        // Refused rather than silently transferred: a move is a decision with a date and a
        // reason, and performing one as a side effect of "add member" loses both.
        $this->postJson("/api/v1/admin/households/{$second->uuid}/members", [
            'resident_id' => (string) $resident->uuid,
        ])->assertStatus(409);
    }

    #[Test]
    public function a_transfer_closes_the_old_membership_and_keeps_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $from = $this->household();
        $to = $this->household();
        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $this->addMember($from, $resident);

        $this->postJson("/api/v1/admin/households/{$to->uuid}/transfers", [
            'resident_id' => (string) $resident->uuid,
            'reason' => 'transferred',
        ])->assertOk();

        $history = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->orderBy('id')
            ->get();

        // Two rows: the old residence survives with an end date, the new one is open.
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->effective_to);
        $this->assertSame('transferred', $history[0]->end_reason);
        $this->assertNull($history[1]->effective_to);

        // The question the whole design exists to answer.
        $this->getJson("/api/v1/admin/residents/{$resident->uuid}/households")
            ->assertOk()
            ->assertJsonCount(2, 'data.history');
    }

    #[Test]
    public function a_transfer_leaves_the_resident_in_exactly_one_household(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $from = $this->household();
        $to = $this->household();
        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $this->addMember($from, $resident);
        $this->postJson("/api/v1/admin/households/{$to->uuid}/transfers", [
            'resident_id' => (string) $resident->uuid,
            'reason' => 'transferred',
        ])->assertOk();

        // A half-transfer would leave a real person belonging to no household, invisible to
        // every household-based distribution until somebody noticed.
        $open = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->get();

        $this->assertCount(1, $open);
        $this->assertSame((int) $to->id, (int) $open[0]->household_id);
    }

    #[Test]
    public function leaving_a_household_ends_family_membership_inside_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);
        $this->addMember($household, $resident);

        $familyId = $this->postJson("/api/v1/admin/households/{$household->uuid}/families", ['label' => 'Lim'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/families/{$familyId}/members", [
            'resident_id' => (string) $resident->uuid,
        ])->assertCreated();

        $this->deleteJson("/api/v1/admin/households/{$household->uuid}/members/{$resident->uuid}", [
            'end_reason' => 'moved-out',
        ])->assertOk();

        // Left open, the person keeps appearing on a family roster at an address they no
        // longer live at — and keeps drawing the grant attached to it.
        $this->assertSame(0, FamilyMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->count());
    }

    #[Test]
    public function a_resident_must_live_in_the_household_before_joining_one_of_its_families(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->household();
        $outsider = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        $familyId = $this->postJson("/api/v1/admin/households/{$household->uuid}/families", ['label' => 'Lim'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/families/{$familyId}/members", [
            'resident_id' => (string) $outsider->uuid,
        ])->assertStatus(409);
    }

    // ── relationships ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_resident_cannot_be_related_to_themselves(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']);

        // Unchecked, this is the commonest slip on a relationship screen, and it produces a
        // cycle every later household-graph traversal has to defend against.
        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/relationships", [
            'related_resident_id' => (string) $resident->uuid,
            'type' => 'parent',
        ])->assertStatus(400);
    }

    #[Test]
    public function the_inverse_of_a_recorded_relationship_is_refused_as_a_duplicate(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$parent, $child] = $this->twoResidents();

        $this->postJson("/api/v1/admin/residents/{$parent->uuid}/relationships", [
            'related_resident_id' => (string) $child->uuid,
            'type' => 'parent',
        ])->assertCreated();

        // Staff record "Ana is mother of Ben" on Ana's screen and "Ben is son of Ana" on
        // Ben's. Both stored, the registry holds one fact twice — usually with two different
        // effective dates, so neither can be trusted.
        $this->postJson("/api/v1/admin/residents/{$child->uuid}/relationships", [
            'related_resident_id' => (string) $parent->uuid,
            'type' => 'child',
        ])->assertStatus(409);

        // …and the same row a second time is equally refused.
        $this->postJson("/api/v1/admin/residents/{$parent->uuid}/relationships", [
            'related_resident_id' => (string) $child->uuid,
            'type' => 'parent',
        ])->assertStatus(409);
    }

    #[Test]
    public function the_inverse_is_derived_on_read_rather_than_stored(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$parent, $child] = $this->twoResidents();

        $this->postJson("/api/v1/admin/residents/{$parent->uuid}/relationships", [
            'related_resident_id' => (string) $child->uuid,
            'type' => 'parent',
        ])->assertCreated();

        // One row exists…
        $this->assertSame(1, ResidentRelationship::query()->count());

        // …and the child's screen still reads correctly, flagged as computed so a client
        // knows an "end this" action must act on the stored direction.
        $this->getJson("/api/v1/admin/residents/{$child->uuid}/relationships")
            ->assertOk()
            ->assertJsonPath('data.relationships.0.type', 'child')
            ->assertJsonPath('data.relationships.0.derived', true);
    }

    #[Test]
    public function a_relationship_response_never_leaks_an_internal_primary_key(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$parent, $child] = $this->twoResidents();

        $this->postJson("/api/v1/admin/residents/{$parent->uuid}/relationships", [
            'related_resident_id' => (string) $child->uuid,
            'type' => 'parent',
        ])->assertCreated();

        $payload = $this->getJson("/api/v1/admin/residents/{$parent->uuid}/relationships")
            ->assertOk()->json('data.relationships.0');

        // Sequential integers leak volume and let a caller walk the table (conventions §6).
        $this->assertArrayNotHasKey('related_resident_pk', $payload);
    }

    #[Test]
    public function ending_a_relationship_keeps_the_row(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$a, $b] = $this->twoResidents();

        $id = $this->postJson("/api/v1/admin/residents/{$a->uuid}/relationships", [
            'related_resident_id' => (string) $b->uuid,
            'type' => 'spouse',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/admin/residents/{$a->uuid}/relationships/{$id}", [
            'end_reason' => 'separated',
        ])->assertOk()->assertJsonPath('data.end_reason', 'separated');

        // A separation and "this never happened" are different claims, and only one is true.
        $this->assertSame(1, ResidentRelationship::query()->count());

        $this->getJson("/api/v1/admin/residents/{$a->uuid}/relationships")
            ->assertOk()
            ->assertJsonCount(0, 'data.relationships');
    }

    // ── the citizen view ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_sees_their_household_without_their_co_members_case_detail(): void
    {
        [$account, $me] = $this->activeCitizenWithResident();

        $neighbour = $this->existingResident([
            'first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Santos', 'birth_date' => '1975-02-02',
        ]);
        $neighbour->forceFill(['monthly_income_centavos' => 900000])->save();

        $household = $this->household();
        $this->addMemberDirectly($household, $me);
        $this->addMemberDirectly($household, $neighbour);

        Sanctum::actingAs($account);

        $payload = $this->getJson('/api/v1/me/household')->assertOk()->json('data');

        $members = collect($payload['members']);
        $other = $members->firstWhere('is_self', false);

        // Names, yes — they live together and know each other's names.
        $this->assertSame('Ben Santos', $other['name']);

        // Everything that would tell them something about the LGU's assessment of that
        // person, no. A boarder must not learn the landlady is a VAWC survivor, or that the
        // man next door has an assistance request pending.
        $this->assertArrayNotHasKey('verification_tier', $other);
        $this->assertArrayNotHasKey('birth_date', $other);
        $this->assertArrayNotHasKey('monthly_income_centavos', $other);
        $this->assertArrayNotHasKey('sectors', $other);

        // Nor the LGU's field assessment of the home itself.
        $this->assertArrayNotHasKey('verification_status', $payload);
        $this->assertArrayNotHasKey('profile_completeness', $payload);
        $this->assertArrayNotHasKey('dwelling_type', $payload);
    }

    #[Test]
    public function a_parent_may_see_the_onboarding_status_of_a_child_they_are_responsible_for(): void
    {
        [$account, $me] = $this->activeCitizenWithResident();

        $child = $this->existingResident([
            'first_name' => 'Ella', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'birth_date' => '2015-09-09',
        ]);

        $household = $this->household();
        $this->addMemberDirectly($household, $me);
        $this->addMemberDirectly($household, $child);

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/residents/{$me->uuid}/relationships", [
            'related_resident_id' => (string) $child->uuid,
            'type' => 'parent',
        ])->assertCreated();

        Sanctum::actingAs($account);
        $members = collect($this->getJson('/api/v1/me/household')->assertOk()->json('data.members'));

        $entry = $members->firstWhere('name', 'Ella Dela Cruz');

        // The narrow, stated exception: a parent needs to know whether their child's
        // onboarding is finished. Resolved from recorded kinship, never inferred from
        // co-residence — living with a child is not the same as being their parent.
        $this->assertSame('parent', $entry['relationship_to_me']);
        $this->assertArrayHasKey('verification_tier', $entry);
        $this->assertSame('2015-09-09', $entry['birth_date']);
    }

    #[Test]
    public function a_citizen_sees_family_membership_only_for_their_own_family(): void
    {
        [$account, $me] = $this->activeCitizenWithResident();

        $household = $this->household();
        $this->addMemberDirectly($household, $me);

        $mine = Family::query()->create(['household_id' => $household->id, 'label' => 'Dela Cruz']);
        Family::query()->create(['household_id' => $household->id, 'label' => 'Santos']);

        FamilyMembership::query()->create([
            'family_id' => $mine->id,
            'resident_id' => $me->id,
            'effective_from' => now()->toDateString(),
        ]);

        Sanctum::actingAs($account);

        $families = collect($this->getJson('/api/v1/me/household')->assertOk()->json('data.families'));

        // That the household holds two units is a fact about their home. Which co-resident
        // belongs to which is a fact about those people.
        $this->assertCount(2, $families);
        $this->assertTrue($families->firstWhere('label', 'Dela Cruz')['is_mine']);
        $this->assertFalse($families->firstWhere('label', 'Santos')['is_mine']);
    }

    #[Test]
    public function a_citizen_with_no_household_is_told_so(): void
    {
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/me/household')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function household_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/households')->assertUnauthorized();
        $this->getJson('/api/v1/me/household')->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_holds_no_household_management_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/households')->assertForbidden();
        $this->postJson('/api/v1/admin/households', [])->assertForbidden();
    }

    #[Test]
    public function a_household_outside_the_callers_barangay_reads_as_not_found(): void
    {
        $other = $this->household($this->otherBarangayId());

        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        // NOT FOUND, never FORBIDDEN — "exists but not yours" enumerates the municipality.
        $this->getJson("/api/v1/admin/households/{$other->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/admin/households')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function staff_cannot_place_a_household_in_a_barangay_they_do_not_serve(): void
    {
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        // Otherwise the record lands where its own office cannot see it.
        $this->postJson('/api/v1/admin/households', [
            'barangay_id' => $this->otherBarangayId(),
            'street_address' => '9 Elsewhere Street',
        ])->assertNotFound();
    }

    #[Test]
    public function opening_a_household_is_an_audited_read(): void
    {
        $reviewer = $this->reviewer('lgu_admin');
        Sanctum::actingAs($reviewer);

        $household = $this->household();

        $this->getJson("/api/v1/admin/households/{$household->uuid}")->assertOk();

        // The member list is other people's personal data.
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'resident.viewed',
            'entity_id' => (string) $household->uuid,
            'actor_subject_id' => (string) $reviewer->uuid,
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function household(?int $barangayId = null): Household
    {
        return Household::query()->create([
            'barangay_id' => $barangayId ?? $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ]);
    }

    /**
     * @return array{Resident, Resident}
     */
    private function twoResidents(): array
    {
        return [
            $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1980-04-04']),
            $this->existingResident(['first_name' => 'Ben', 'last_name' => 'Lim', 'birth_date' => '2010-06-06']),
        ];
    }

    private function addMember(Household $household, Resident $resident): void
    {
        $this->postJson("/api/v1/admin/households/{$household->uuid}/members", [
            'resident_id' => (string) $resident->uuid,
        ])->assertCreated();
    }

    /**
     * Inserted directly, for citizen-facing tests where no staff actor is signed in.
     */
    private function addMemberDirectly(Household $household, Resident $resident): void
    {
        HouseholdMembership::query()->create([
            'household_id' => $household->id,
            'resident_id' => $resident->id,
            'effective_from' => now()->toDateString(),
        ]);
    }
}
