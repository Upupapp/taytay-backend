<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The family read side — TAB 07's first four no-counterpart rows.
 *
 * The command asks for authorization tests **written as attacks**: *"wrong role, wrong barangay,
 * another worker's case, an identifier that exists but is not yours."* Half this file is that, and
 * the wrong-barangay case is the one that matters most here, because a family is the one record
 * with **no barangay of its own** — it borrows one from the household it sits in. Every scoping
 * bug of that shape looks like working code right up until a barangay focal person opens a family
 * from a barangay they do not hold.
 */
final class FamilyReadTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the reads exist and answer ───────────────────────────────────────────────────

    #[Test]
    public function a_family_lists_with_a_member_count_derived_rather_than_stored(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $this->familyIn($household, 'The Dela Cruz family', [$head]);

        $row = $this->getJson('/api/v1/admin/families')->assertOk()->json('data.0');

        $this->assertSame('The Dela Cruz family', $row['label']);
        $this->assertSame(1, $row['member_count'], 'The count is derived from open memberships; there is none to drift.');
        $this->assertArrayNotHasKey('household_pk', $row);
    }

    #[Test]
    public function a_family_detail_carries_its_members_and_the_links_inside_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $family = $this->familyIn($household, 'The Dela Cruz family', [$head]);
        $this->postJson("/api/v1/admin/families/{$family}/head", ['resident_id' => $head])->assertSuccessful();

        $detail = $this->getJson("/api/v1/admin/families/{$family}")->assertOk()->json('data');

        $this->assertSame($head, $detail['head_resident_id']);
        $this->assertCount(1, $detail['members']);
        $this->assertSame('head', $detail['members'][0]['role']);
        $this->assertArrayHasKey('relationships', $detail);
    }

    /**
     * The gap this projection refuses to paper over.
     *
     * `family_memberships` has no role column, so exactly two roles are knowable: the resident
     * named as head, and everybody else. The console's vocabulary has six. Reporting an elder as a
     * child because the code needed a value would be a false statement about a family, sitting in
     * a record the office relies on — so the four it cannot know are never emitted (gap G-22).
     */
    #[Test]
    public function a_member_role_is_never_guessed(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $other = $this->residentIn($household, 'Ana', 'Dela Cruz');

        $family = $this->familyIn($household, 'The Dela Cruz family', [$head, $other]);
        $this->postJson("/api/v1/admin/families/{$family}/head", ['resident_id' => $head])->assertSuccessful();

        $roles = collect($this->getJson("/api/v1/admin/families/{$family}")->assertOk()->json('data.members'))
            ->pluck('role')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['head', 'other-member'], $roles);
    }

    /**
     * A divergence, pinned as a test so nobody has to rediscover it.
     *
     * This API allows **one** open family membership per resident, and refuses the second with a
     * reason grounded in money: two memberships make a person countable twice in per-family
     * grants, which is the duplicate-resident double-payment problem one level down.
     *
     * The console's port is explicitly plural — `familiesOf`, *"people overlap"* — and treats a
     * grandmother counted with her own family and with her daughter's as the ordinary case.
     *
     * Both are coherent; they cannot both be executed. Recorded as G-24 for the office to rule on.
     * The endpoint stays a collection meanwhile, because changing the wire shape would bake in the
     * answer nobody has given yet.
     */
    #[Test]
    public function a_second_open_family_membership_is_refused_and_the_read_stays_a_collection(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $this->familyIn($household, 'First family', [$head]);
        $second = $this->familyIn($household, 'Second family');

        $this->postJson("/api/v1/admin/families/{$second}/members", ['resident_id' => $head])
            ->assertStatus(409);

        $families = $this->getJson("/api/v1/admin/residents/{$head}/families")->assertOk()->json('data');

        $this->assertCount(1, $families, 'One open membership is all the invariant permits today.');
    }

    #[Test]
    public function kinship_history_is_assembled_from_the_effective_dated_rows_that_already_exist(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $other = $this->residentIn($household, 'Ana', 'Dela Cruz');
        $family = $this->familyIn($household, 'The Dela Cruz family', [$other]);
        $this->deleteJson("/api/v1/admin/families/{$family}/members/{$other}", ['end_reason' => 'moved-out'])->assertSuccessful();

        $kinds = collect($this->getJson("/api/v1/admin/residents/{$other}/kinship-history")->assertOk()->json('data'))
            ->pluck('kind')
            ->all();

        $this->assertContains('member-joined', $kinds);
        $this->assertContains('member-left', $kinds, 'Ending a membership sets an end date; it never deletes the row.');
    }

    #[Test]
    public function every_collection_here_is_paginated(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$household, $head] = $this->householdWithHead();
        $this->familyIn($household, 'The Dela Cruz family', [$head]);

        foreach ([
            '/api/v1/admin/families',
            "/api/v1/admin/residents/{$head}/families",
            "/api/v1/admin/residents/{$head}/kinship-history",
        ] as $path) {
            $meta = $this->getJson($path)->assertOk()->json('meta');

            $this->assertArrayHasKey('pagination', $meta, "{$path} returned an unbounded collection.");
        }
    }

    // ── attacks ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_role_without_resident_view_is_refused_every_family_read(): void
    {
        [$household, $head] = $this->seedAsAdmin();

        // The Data Protection Officer holds no operational permission at all, deliberately.
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        foreach ([
            '/api/v1/admin/families',
            "/api/v1/admin/residents/{$head}/families",
            "/api/v1/admin/residents/{$head}/kinship-history",
        ] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    /**
     * The attack this endpoint exists to survive.
     *
     * A family carries no barangay. A scoping rule that forgot that would let a barangay focal
     * person read every family in the municipality while the household endpoint next to it
     * correctly refused them — and nothing on the screen would look wrong.
     */
    #[Test]
    public function a_barangay_scoped_caller_cannot_read_a_family_in_another_barangay(): void
    {
        [$household, $head] = $this->seedAsAdmin();
        $family = $this->familyIn($household, 'The Dela Cruz family');

        Sanctum::actingAs($this->scopedTo($this->otherBarangayId()));

        $this->getJson('/api/v1/admin/families')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/families/{$family}")->assertNotFound();
    }

    /**
     * An identifier that exists but is not yours must be indistinguishable from one that does not
     * exist. Two different refusals confirm the record is real, which is itself a disclosure.
     */
    #[Test]
    public function a_family_outside_scope_refuses_exactly_as_a_missing_one_does(): void
    {
        [$household, $head] = $this->seedAsAdmin();
        $family = $this->familyIn($household, 'The Dela Cruz family');

        Sanctum::actingAs($this->scopedTo($this->otherBarangayId()));

        $real = $this->getJson("/api/v1/admin/families/{$family}")->assertNotFound();
        $fictional = $this->getJson('/api/v1/admin/families/01a00000-0000-7000-8000-000000000000')->assertNotFound();

        $this->assertSame($real->json('error.code'), $fictional->json('error.code'));
        $this->assertSame($real->json('error.message'), $fictional->json('error.message'));
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_none_of_it(): void
    {
        foreach ([
            '/api/v1/admin/families',
            '/api/v1/admin/families/01a00000-0000-7000-8000-000000000000',
            '/api/v1/admin/residents/01a00000-0000-7000-8000-000000000000/families',
            '/api/v1/admin/residents/01a00000-0000-7000-8000-000000000000/kinship-history',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    // ── fixtures, built by calling the API ───────────────────────────────────────────

    /** @return array{0: string, 1: string} household uuid, head resident uuid */
    private function householdWithHead(): array
    {
        $head = $this->addResident('Maria', 'Dela Cruz');

        $household = (string) $this->postJson('/api/v1/admin/households', [
            'head_resident_id' => $head,
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');

        /*
         * Naming somebody as head does NOT enrol them as a member — `households.head_resident_id`
         * is a pointer and `household_memberships` is the fact, and creating the household writes
         * only the first. Recorded as G-23; here the fixture just does what a real caller has to
         * do, because pretending otherwise would test a state the API cannot reach.
         */
        $this->postJson("/api/v1/admin/households/{$household}/members", ['resident_id' => $head])
            ->assertSuccessful();

        return [$household, $head];
    }

    /** @return array{0: string, 1: string} */
    private function seedAsAdmin(): array
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        return $this->householdWithHead();
    }

    /**
     * A family, created empty and then given the members it needs.
     *
     * `POST .../families` takes a label and nothing else: a family starts with no members and no
     * head, and joining one requires already belonging to the household. Both are the API's own
     * invariants, so these fixtures follow them rather than reaching past them — a fixture that
     * inserted rows directly would prove a shape no caller can actually produce.
     *
     * @param  list<string>  $members  resident uuids, already in the household
     */
    private function familyIn(string $household, string $label, array $members = []): string
    {
        $family = (string) $this->postJson("/api/v1/admin/households/{$household}/families", [
            'label' => $label,
        ])->assertCreated()->json('data.id');

        foreach ($members as $resident) {
            $this->postJson("/api/v1/admin/families/{$family}/members", ['resident_id' => $resident])
                ->assertSuccessful();
        }

        return $family;
    }

    /** A new resident, joined to the household so they can join its families. */
    private function residentIn(string $household, string $first, string $last): string
    {
        $resident = $this->addResident($first, $last);

        $this->postJson("/api/v1/admin/households/{$household}/members", ['resident_id' => $resident])
            ->assertSuccessful();

        return $resident;
    }

    private function addResident(string $first, string $last): string
    {
        return (string) $this->postJson('/api/v1/admin/residents', [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');
    }

    /** A caller with every permission `lgu_admin` holds, confined to one barangay. */
    private function scopedTo(int $barangayId): Account
    {
        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'lgu_admin', $barangayId);

        return $account;
    }
}
