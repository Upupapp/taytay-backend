<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * A ROLE GRANTED NOW MUST BE IN FORCE NOW.
 *
 * `DatabaseRoleAssignmentRepository::authorityForMany()` filters assignments on
 * `valid_from <= now()`, and `valid_from` is written by the column default, `CURRENT_TIMESTAMP`.
 * While that column had precision 0, PostgreSQL ROUNDED the default rather than truncating it: a
 * row written at 14:16:45.548 was stored as 14:16:46 — half a second in the FUTURE — so the
 * assignment matched nothing and its holder was refused a permission they demonstrably held.
 *
 * SQLite truncates the same default to 14:16:45 and the comparison holds, which is why the suite
 * was green on the test driver for as long as the defect existed. Found by running the suite on
 * PostgreSQL, where `ApiSecurityTest` failed IN ISOLATION while passing in the full run — a test
 * that passes in the suite and fails alone is a finding, not a flake.
 *
 * ── WHY THIS IS A SCHEMA ASSERTION AND NOT A BEHAVIOURAL ONE ───────────────────────
 *
 * Two behavioural versions of this test were written and both were discarded for being green
 * against the reverted fix — recorded here because each failed for a different reason and both
 * reasons are easy to walk back into.
 *
 * The first set `valid_from` explicitly from a frozen clock. Laravel serialises a timestamp as
 * `Y-m-d H:i:s`, dropping the microseconds before the value reaches the database, so an explicit
 * write cannot reproduce a ROUNDING defect at all. Only the column default can, because only it
 * is generated inside the database at full precision.
 *
 * The second used the default and wrote twelve rows, on the reasoning that twelve independent
 * sub-second fractions could not all land below .5. They are not independent: PostgreSQL's
 * `CURRENT_TIMESTAMP` is the TRANSACTION's start time, identical for every row in it, and
 * `RefreshDatabase` puts the whole test in one transaction. Twelve rows are one coin flip, and
 * the flip is biased towards passing because the transaction starts before the assertion runs.
 *
 * So the defect is not reproducible on demand from inside a transactional test, and the guard is
 * the column definition itself. It fails the moment either validity window goes back to whole
 * seconds, whatever the clock is doing.
 */
final class RoleValidityWindowTest extends KycTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A grant made a fraction of a second ago is in force.
     *
     * Deterministic on both drivers, and the shape is the point: `valid_from` is written as a
     * RAW literal carrying microseconds, because a value bound through the query builder loses
     * them on the way in and could not express the case under test. The clock is then frozen a
     * few hundred microseconds later, inside the same second.
     *
     * With the comparison bound at whole-second precision this fails on PostgreSQL (.842084 is
     * after the start of the second) and on SQLite too (the longer string sorts after the
     * shorter), so unlike the schema assertion below it guards both drivers.
     */
    #[Test]
    public function a_grant_made_a_fraction_of_a_second_ago_is_in_force(): void
    {
        $resident = $this->existingResident(['barangay_id' => $this->barangayId()]);

        $clerk = Account::factory()->staff()->create();

        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => (string) $clerk->uuid,
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->barangayId(),
            /*
             * A RAW LITERAL, because a value bound through the query builder is serialised as
             * `Y-m-d H:i:s` and arrives with its microseconds already gone -- which is the very
             * thing under test and cannot be expressed any other way.
             */
            'valid_from' => DB::raw("'2026-08-30 14:20:31.842084'"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 24 milliseconds after the grant, inside the same second. That is the whole difficulty.
        Carbon::setTestNow(Carbon::parse('2026-08-30T14:20:31.866184Z'));

        Sanctum::actingAs($clerk);

        $this->getJson("/api/v1/admin/residents/{$resident->uuid}")->assertOk();
    }

    #[Test]
    public function the_validity_windows_keep_sub_second_precision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'SQLite has no column precision to inspect — it stores the timestamp as text, which '
                .'is exactly why it never reproduced this defect. Meaningful on PostgreSQL only.'
            );
        }

        foreach (['role_assignments', 'staff_barangay_grants'] as $table) {
            foreach (['valid_from', 'valid_until'] as $column) {
                $precision = DB::selectOne(
                    'select datetime_precision as p from information_schema.columns '
                    .'where table_name = ? and column_name = ?',
                    [$table, $column],
                )?->p;

                $this->assertGreaterThan(0, (int) $precision, sprintf(
                    '%s.%s stores whole seconds. PostgreSQL ROUNDS a timestamp to fit, so a row '
                    .'written at .548 is stored half a second in the future and the '
                    .'`valid_from <= now()` filter in the authorization query skips it.',
                    $table,
                    $column,
                ));
            }
        }
    }
}
