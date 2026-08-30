<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TIMESTAMP THE DATABASE GENERATES IS STORED AT THE PRECISION IT WAS GENERATED.
 *
 * PostgreSQL ROUNDS a timestamp to fit a column's precision rather than truncating it, so a
 * `CURRENT_TIMESTAMP` default written into a precision-0 column stores a value that never
 * happened — up to half a second in the future. ADR 0049 fixed the two columns where that was
 * actively breaking authorization. These are the rest of them.
 *
 * No comparison bug is known in any of these: the audit of every `now()` comparison in the code
 * found that not one targets a database-defaulted column, and the two read paths where
 * intra-second ordering could matter — the audit trail and the governance register — already
 * tie-break on `id`. This is not a fix for a live defect.
 *
 * It is here because the class should not survive its instance. `audit_entries` is an append-only
 * legal record under Article 5.4, and stamping it half a second after the fact is wrong on its own
 * terms; and the next `where('created_at', '<=', now())` somebody writes over one of these columns
 * would reintroduce ADR 0049 exactly. `SchemaPrecisionTest` now asserts the invariant so the
 * question does not have to be re-derived.
 *
 * WIDENING ONLY, and PostgreSQL only for the reasons ADR 0049 records: precision is a concept
 * SQLite lacks, and `->change()` there rebuilds the table from the Blueprint and drops constraints
 * the Blueprint does not restate.
 */
return new class extends Migration
{
    /**
     * Every column whose value the DATABASE writes. Laravel-written timestamps are unaffected:
     * they arrive already truncated to the second, so there is nothing to round.
     *
     * @var array<string, list<string>>
     */
    private const STAMPED = [
        'audit_entries' => ['created_at'],
        'credential_verifications' => ['created_at'],
        'kyc_case_transitions' => ['created_at'],
        'resident_aliases' => ['created_at'],
        'resident_merges' => ['created_at'],
        'resident_status_events' => ['created_at'],
        'welfare_case_eligibility_checks' => ['created_at'],
        'welfare_case_eligibility_results' => ['created_at'],
        'welfare_case_events' => ['created_at'],
        'welfare_case_transitions' => ['created_at'],
    ];

    public function up(): void
    {
        $this->setPrecision(6);
    }

    /**
     * A REAL MIRROR. Narrowing re-rounds the stored values, which is the defect itself — so this
     * direction is lossy by nature and is written out plainly rather than left inert.
     */
    public function down(): void
    {
        $this->setPrecision(0);
    }

    private function setPrecision(int $precision): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::STAMPED as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $precision): void {
                foreach ($columns as $column) {
                    $blueprint->timestampTz($column, $precision)->useCurrent()->change();
                }
            });
        }
    }
};
