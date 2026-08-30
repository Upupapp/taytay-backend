<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A STAFF MEMBER HELD NO PERMISSIONS FOR UP TO HALF A SECOND AFTER BEING GRANTED THEM.
 *
 * `role_assignments.valid_from` and `staff_barangay_grants.valid_from` are written by the column
 * default, `CURRENT_TIMESTAMP`, into a column of precision 0. PostgreSQL does not truncate a
 * timestamp to fit that precision — it ROUNDS. A row written at 14:16:45.548 is therefore stored
 * as 14:16:46, half a second in the FUTURE, and
 * `DatabaseRoleAssignmentRepository::authorityForMany()` filters on `valid_from <= now()` with a
 * microsecond-precision `now()` from PHP. The assignment matches nothing until the wall clock
 * catches up.
 *
 * The effect in production: provision a clerk and authorize them in the same second and the
 * authorization fails closed — a 403 on a permission they demonstrably hold. It is a coin flip on
 * the sub-second fraction, which is exactly why nothing caught it: it reproduces about half the
 * time, and never at all on SQLite, where the same default TRUNCATES to 14:16:45 and the
 * comparison holds.
 *
 * Found by running the suite on PostgreSQL, where `ApiSecurityTest` failed in isolation while
 * passing in the full run. A test that passes in the suite and fails alone is a finding, not a
 * flake.
 *
 * WIDENING ONLY, so this is safe against a populated table: every existing value is representable
 * at the new precision and no row changes meaning. Nothing is renamed or dropped, so Article 6's
 * expand-migrate-contract does not apply.
 *
 * ── WHY THIS RUNS ON POSTGRESQL ONLY ────────────────────────────────────────────────
 *
 * Not an optimisation, and not vendor lock-in under Article 1 -- the portable Blueprint API is
 * still what does the work. Column precision is a concept SQLite does not have: it stores a
 * timestamp as TEXT exactly as given, which is why the defect never appeared there.
 *
 * Running it anyway was actively harmful, and the suite caught it. `->change()` on SQLite
 * REBUILDS the table from the Blueprint, and the rebuild dropped the check constraint behind
 * `role_assignments.scope_type` -- so a migration whose subject was a timestamp silently removed
 * a guard on an authorization column, and `AuthorizationMatrixTest` went red. A schema change
 * that only makes sense on one driver should be asked of one driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->timestampTz('valid_from', 6)->useCurrent()->change();
            $table->timestampTz('valid_until', 6)->nullable()->change();
        });

        Schema::table('staff_barangay_grants', function (Blueprint $table): void {
            $table->timestampTz('valid_from', 6)->useCurrent()->change();
            $table->timestampTz('valid_until', 6)->nullable()->change();
        });
    }

    /**
     * A REAL MIRROR, not an inert stub. Narrowing back to second precision re-rounds the stored
     * values, which is precisely the defect above — so this direction is lossy by nature and is
     * written out plainly rather than pretended away.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->timestampTz('valid_from', 0)->useCurrent()->change();
            $table->timestampTz('valid_until', 0)->nullable()->change();
        });

        Schema::table('staff_barangay_grants', function (Blueprint $table): void {
            $table->timestampTz('valid_from', 0)->useCurrent()->change();
            $table->timestampTz('valid_until', 0)->nullable()->change();
        });
    }
};
