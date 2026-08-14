<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the foundation schema against a real database rather than reading migration
 * source. A unique index that was declared but never enforced looks identical to one that
 * works, right up until production has two rows.
 *
 * Runs on the suite's SQLite connection; the same assertions were executed against
 * PostgreSQL 16 during TAB 04 (see docs/runbooks/local-development.md § 6a).
 */
final class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_foundation_tables_exist(): void
    {
        foreach (['barangays', 'role_assignments', 'audit_entries', 'idempotency_keys'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing foundation table `{$table}`.");
        }
    }

    #[Test]
    public function a_duplicate_role_assignment_is_refused(): void
    {
        $subject = (string) Str::uuid7();

        $this->insertRoleAssignment($subject, 'lgu_admin');

        // The same person cannot hold the same role twice. Without this the second click
        // of "assign role" doubles the row and every count, revocation and audit answer
        // that follows is wrong.
        $this->expectException(QueryException::class);

        $this->insertRoleAssignment($subject, 'lgu_admin');
    }

    #[Test]
    public function the_same_person_may_hold_two_different_roles(): void
    {
        $subject = (string) Str::uuid7();

        $this->insertRoleAssignment($subject, 'lgu_staff');
        $this->insertRoleAssignment($subject, 'auditor');

        $this->assertSame(2, DB::table('role_assignments')->where('subject_id', $subject)->count());
    }

    #[Test]
    public function two_people_may_hold_the_same_role(): void
    {
        $this->insertRoleAssignment((string) Str::uuid7(), 'lgu_admin');
        $this->insertRoleAssignment((string) Str::uuid7(), 'lgu_admin');

        $this->assertSame(2, DB::table('role_assignments')->where('role', 'lgu_admin')->count());
    }

    #[Test]
    public function a_scope_wide_assignment_still_cannot_be_duplicated(): void
    {
        // The case a naive unique key gets wrong: with `barangay_id` inside the key and no
        // barangay set, PostgreSQL treats the NULLs as distinct and allows unlimited
        // duplicates — for exactly the municipality-wide grants that matter most.
        $subject = (string) Str::uuid7();

        $this->insertRoleAssignment($subject, 'mswdo_head');

        $this->expectException(QueryException::class);

        $this->insertRoleAssignment($subject, 'mswdo_head');
    }

    #[Test]
    public function an_idempotency_key_cannot_be_replayed_for_the_same_caller_and_endpoint(): void
    {
        $subject = (string) Str::uuid7();

        $this->insertIdempotencyKey($subject, 'POST /api/v1/me/assistance-requests', 'key-abc');

        // A retried submission on a dropped mobile connection must not create a second
        // application (conventions §7).
        $this->expectException(QueryException::class);

        $this->insertIdempotencyKey($subject, 'POST /api/v1/me/assistance-requests', 'key-abc');
    }

    #[Test]
    public function the_same_key_on_a_different_endpoint_is_a_different_record(): void
    {
        $subject = (string) Str::uuid7();

        $this->insertIdempotencyKey($subject, 'POST /api/v1/me/assistance-requests', 'key-abc');
        $this->insertIdempotencyKey($subject, 'POST /api/v1/me/verification/submissions', 'key-abc');

        $this->assertSame(2, DB::table('idempotency_keys')->where('subject_id', $subject)->count());
    }

    #[Test]
    public function a_barangay_code_is_unique(): void
    {
        $this->insertBarangay('brgy-san-juan', 'San Juan');

        $this->expectException(QueryException::class);

        $this->insertBarangay('brgy-san-juan', 'San Juan Duplicate');
    }

    #[Test]
    public function the_audit_trail_has_no_column_to_record_a_change(): void
    {
        // Append-only is structural: there is nowhere to write a modification, so a
        // careless `->update()` fails rather than quietly rewriting evidence.
        $this->assertTrue(Schema::hasColumn('audit_entries', 'created_at'));
        $this->assertFalse(Schema::hasColumn('audit_entries', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('audit_entries', 'deleted_at'));
    }

    #[Test]
    public function an_audit_entry_records_the_actor_without_pointing_at_their_account(): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => (string) Str::uuid7(),
            'actor_label' => 'MSWDO Head',
            'action' => 'viewed',
            'entity_type' => 'ResidentProfile.Resident',
            'entity_id' => (string) Str::uuid7(),
            'summary' => 'Viewed resident record',
            'request_id' => 'req-123',
            'client_channel' => 'admin-console',
            'created_at' => now(),
        ]);

        // The trail must survive the account it names being deleted, so the reference is
        // an identifier with no foreign key.
        $this->assertSame(1, DB::table('audit_entries')->count());
    }

    private function insertRoleAssignment(string $subjectId, string $role): void
    {
        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => $subjectId,
            'role' => $role,
            'scope_type' => 'all-barangays',
            'valid_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertIdempotencyKey(string $subjectId, string $endpoint, string $key): void
    {
        DB::table('idempotency_keys')->insert([
            'uuid' => (string) Str::uuid7(),
            'idempotency_key' => $key,
            'subject_id' => $subjectId,
            'endpoint' => $endpoint,
            'request_fingerprint' => hash('sha256', 'payload'),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBarangay(string $code, string $name): void
    {
        DB::table('barangays')->insert([
            'uuid' => (string) Str::uuid7(),
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
