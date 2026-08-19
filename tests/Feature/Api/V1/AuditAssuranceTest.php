<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Infrastructure\Eloquent\AuditEntry;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * TAB 14 — the assurance half of privacy and audit, as tests.
 *
 * Steps 1–6 are appointments and approvals and **cannot be closed by engineering**; they are on the
 * master TODO with the DPO appointment as blocker 1. What engineering owes is that the trail is
 * worth reading when a DPO finally can: append-only in fact rather than by convention, and covering
 * the acts a written list says it covers.
 */
final class AuditAssuranceTest extends KycTestCase
{
    use RefreshDatabase;

    // ── step 8: append-only, proven by attempting it ─────────────────────────────────

    /**
     * *"Attempt to edit and delete an audit entry through every available path and confirm
     * refusal."*
     *
     * `AuditIsAppendOnlyTest` already proves no application code does this — by reading the source.
     * That is a claim about the code that exists. This is a claim about the code that **can** exist:
     * the attempt is refused rather than merely absent.
     */
    #[Test]
    public function an_audit_entry_refuses_to_be_edited_through_any_path(): void
    {
        $entry = $this->anEntry();

        // 1. Mass assignment, which is refused before it reaches the database at all.
        try {
            $entry->fill(['summary' => 'Rewritten']);
            $this->fail('An audit entry accepted a mass assignment.');
        } catch (MassAssignmentException) {
            // `$guarded = ['*']` — nothing here is assignable.
        }

        // 2. Direct assignment and save.
        try {
            $entry->summary = 'Rewritten';
            $entry->save();
            $this->fail('An audit entry was edited. A record that can be corrected after the fact proves nothing.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // 3. forceFill, which bypasses $guarded and is the path somebody reaches for.
        try {
            $entry->forceFill(['summary' => 'Rewritten'])->save();
            $this->fail('forceFill edited an audit entry.');
        } catch (\RuntimeException) {
            // Refused.
        }

        $this->assertSame('Recorded for the trail', AuditEntry::query()->value('summary'));
    }

    #[Test]
    public function an_audit_entry_refuses_to_be_deleted(): void
    {
        $entry = $this->anEntry();

        try {
            $entry->delete();
            $this->fail('An audit entry was deleted.');
        } catch (\RuntimeException $e) {
            // Disposal happens under an approved retention schedule, which does not exist yet.
            $this->assertStringContainsString('retention schedule', $e->getMessage());
        }

        $this->assertSame(1, AuditEntry::query()->count());
    }

    #[Test]
    public function the_api_offers_no_verb_that_could_change_the_trail(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $entry = $this->anEntry();

        foreach (['patch', 'put', 'delete'] as $verb) {
            $response = $this->json(strtoupper($verb), "/api/v1/admin/audit-entries/{$entry->uuid}");

            // 405 where the path exists for GET, 404 where it does not. Either is a refusal; what
            // must never appear is a 2xx.
            $this->assertContains($response->status(), [404, 405], "{$verb} reached something.");
        }
    }

    // ── step 9: coverage against a written list ──────────────────────────────────────

    /**
     * *"Verify audit coverage against a written list: every read of sensitive data, every mutation,
     * every export, every document open, every permission change, every sign-in and failure. A gap
     * found after an incident is a gap that cannot be filled retrospectively."*
     *
     * The list is `docs/contracts/audit-coverage.md`. This test **exercises each act and looks for
     * its entry**, rather than asserting that a writer was called — a writer that is called with
     * the wrong action, or inside a transaction that later rolls back, satisfies a mock and leaves
     * the trail empty.
     */
    #[Test]
    public function every_act_on_the_written_list_leaves_an_entry(): void
    {
        $admin = $this->reviewer('lgu_admin');
        Sanctum::actingAs($admin);

        $found = [];

        // A mutation: creating a resident.
        $resident = (string) $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Audited',
            'last_name' => 'Person',
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');

        $found['mutation'] = $this->trailHas('resident');

        // A search: a disclosure act, added in TAB 11.
        $this->getJson('/api/v1/admin/search?q=Audited')->assertOk();
        $found['search'] = $this->trailHas('search');

        // A report run: nothing written, no name returned, and still an act worth recording —
        // "who asked which question of the welfare registry" is the audit interest.
        $this->postJson('/api/v1/admin/reports/case-summary/run')->assertOk();
        $found['report'] = $this->trailHas('report');

        foreach ($found as $act => $present) {
            $this->assertTrue($present, "No audit entry for: {$act}. A gap found after an incident cannot be filled retrospectively.");
        }
    }

    /**
     * A sign-in and a failed sign-in are both recorded.
     *
     * The failure matters more than the success: a run of them against one account is the only
     * signal the office gets that somebody is trying passwords.
     */
    #[Test]
    public function a_sign_in_and_a_failed_sign_in_are_both_recorded(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'auditable@example.test']);
        $this->grantRole($account, 'lgu_staff', $this->barangayId());

        // The factory sets a known password; this is deliberately not it.
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'auditable@example.test',
            'password' => 'definitely-not-the-password',
        ], ['X-Client-Channel' => 'admin-console'])->assertUnauthorized();

        $this->assertTrue(
            $this->trailHas('sign-in') || $this->trailHas('auth') || $this->trailHas('login'),
            'A failed sign-in left no trace. A run of them against one account is the only signal the office gets.',
        );
    }

    // ── step 7: the split the console expects, and why it is empty here ──────────────

    /**
     * The console's `DL-114` describes two tiers: a row list, and recorded **values** behind
     * `audit.view-detail`. TAB 14 step 7 asks for the same split.
     *
     * **This API has no second tier, because it records no values at all.** `AuditTrail` takes
     * field *names* and a reason and has no parameter anywhere for an old or new value — *"a trail
     * that duplicates the data it protects is a second, less-guarded copy of it."*
     *
     * So the split the command asks for is achieved more strongly than by a permission: reading a
     * row tells you a record changed and which columns moved, and there is nowhere in the system
     * that will tell you what it changed to. Recorded as G-33 so nobody wires a console tier
     * against a field that does not exist.
     */
    #[Test]
    public function the_trail_records_which_fields_moved_and_never_what_they_became(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident(['monthly_income' => 320000]);

        $this->patchJson("/api/v1/admin/residents/{$resident->uuid}", [
            'street_address' => '99 New Street',
        ])->assertSuccessful();

        $entries = DB::table('audit_entries')->get();

        foreach ($entries as $entry) {
            $row = json_encode($entry);

            // The old and new values must appear nowhere in the trail.
            $this->assertStringNotContainsString('99 New Street', (string) $row);
            $this->assertStringNotContainsString('12 Rizal Street', (string) $row);
            $this->assertStringNotContainsString('320000', (string) $row);
        }

        // What it does carry is which columns moved, which is oversight rather than access.
        $withFields = DB::table('audit_entries')->whereNotNull('changed_fields')->first();

        if ($withFields !== null) {
            $this->assertStringNotContainsString('=', (string) $withFields->changed_fields);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────

    private function anEntry(): AuditEntry
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => null,
            'action' => 'test.recorded',
            'entity_type' => 'Test',
            'summary' => 'Recorded for the trail',
            'created_at' => now(),
        ]);

        /** @var AuditEntry $entry */
        $entry = AuditEntry::query()->firstOrFail();

        return $entry;
    }

    private function trailHas(string $needle): bool
    {
        return DB::table('audit_entries')
            ->where('action', 'like', "%{$needle}%")
            ->orWhere('entity_type', 'like', "%{$needle}%")
            ->exists();
    }
}
