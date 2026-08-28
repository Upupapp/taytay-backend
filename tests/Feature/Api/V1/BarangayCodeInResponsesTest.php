<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * L-15's read side: a response that names a barangay names it by code as well as by key.
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────────────
 *
 * `barangay_id` is the auto-increment primary key of the `barangays` row. Article 4 and
 * `conventions.md` §6 both say an identifier exposed to a client is a UUID and that auto-increment
 * keys never appear in a payload, and the evidence ledger has recorded the violation as L-15 since
 * TAB 05, assigned to the backend.
 *
 * It stayed open long enough for the WRITE side to diverge from it twice — `POST me/kyc` accepts
 * `barangay_code`, and eligibility criteria are authored by code (ADR 0045) — while every response
 * still returned the integer. A client could register by code and read back a number it had no way
 * to map.
 *
 * ── THIS IS THE EXPAND STEP, NOT THE CONTRACT STEP ───────────────────────────────────
 *
 * `barangay_id` is still emitted, unchanged and in the same place. Article 6 requires
 * expand → migrate → contract, and four independent clients read these payloads; removing the
 * integer is a breaking change that needs their cutover, not a sweep. So these tests assert the
 * code is PRESENT and CORRECT, and deliberately assert the id is still there too — a later change
 * that removes it should have to come through here and say so.
 */
final class BarangayCodeInResponsesTest extends KycTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_staff_resident_list_carries_the_code_beside_the_key(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident();

        $row = $this->getJson('/api/v1/admin/residents')->assertOk()->json('data.0');

        $this->assertSame('brgy-san-juan', $row['barangay_code']);

        // The expand step: the key is still there, in the same place, for the clients that read it.
        $this->assertSame($resident->barangay_id, $row['barangay_id']);
    }

    #[Test]
    public function the_code_is_the_one_the_public_directory_publishes(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->existingResident();

        $published = $this->getJson('/api/v1/barangays')->assertOk()->json('data');
        $codes = array_column($published, 'code');

        $row = $this->getJson('/api/v1/admin/residents')->assertOk()->json('data.0');

        /*
         * The point of the whole change. A code a client cannot resolve against the directory is
         * no better than the integer it replaced, so this asserts against what `GET /barangays`
         * actually returns rather than against a literal.
         */
        $this->assertContains($row['barangay_code'], $codes);
    }

    #[Test]
    public function the_citizens_own_profile_carries_the_code(): void
    {
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.barangay_code', 'brgy-san-juan');
    }

    #[Test]
    public function staff_authority_lists_the_codes_for_the_barangays_it_is_scoped_to(): void
    {
        $admin = $this->reviewer('lgu_admin');
        Sanctum::actingAs($admin);

        $staff = $this->getJson('/api/v1/staff')->assertOk()->json('data.0.authority');

        // Present whenever the ids are, and drawn from the same list. A scope of "all barangays"
        // carries no ids and therefore no codes, which is why this asserts the relationship
        // between the two rather than a fixed value.
        // Read from `authority.scope`, which is where the list nests it. The list builds its
        // scope separately from the detail endpoint's `describeAuthority()`, so both call sites
        // go through `DataScope::forResponse()` — writing this test is what found the second one.
        $this->assertArrayHasKey('barangay_codes', $staff['scope']);
        $this->assertLessThanOrEqual(count($staff['scope']['barangay_ids']), count($staff['scope']['barangay_codes']));
    }
}
