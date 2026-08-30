<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `report_exports.stored_file_id` holds a storage KEY, and was declared `uuid`.
 *
 * ---
 *
 * **THE EXPORT FEATURE COULD NOT WORK IN PRODUCTION.** `BuildReportExport` writes
 * `exports/2026/08/<uuid>.csv` into this column, `ReportController::download()` passes the value
 * straight to `$disk->get()`, and `PurgeExpiredExports` deletes by it. Every one of those treats
 * it as a path, which is what it is.
 *
 * PostgreSQL refuses it: `invalid input syntax for type uuid: "exports/2026/08/….csv"`. Nine
 * export tests fail there. **All nine pass on SQLite**, whose dynamic typing stores the string in
 * a column declared `uuid` without complaint — and the suite runs on SQLite, so nothing said.
 * Production is PostgreSQL (Article 1).
 *
 * Found by running the suite against a real PostgreSQL 18 for the first time. The runbook has
 * always said the SQLite suite "does not prove PostgreSQL behaviour"; this is what that sentence
 * was worth.
 *
 * ── WHY A TYPE CHANGE AND NOT A RENAME ───────────────────────────────────────────────
 *
 * The name is also wrong — it is a key, not an id — but renaming a column is
 * expand → migrate → contract across three call sites and a client-visible projection, and this
 * change is a production outage fix. The type is corrected here; the name is left as a separate,
 * smaller decision rather than bundled into an urgent one.
 *
 * Safe on a populated table: every existing value is already a path string, and no value in this
 * column has ever been a valid uuid — on PostgreSQL no row could have been written at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('stored_file_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * PostgreSQL will not cast text to uuid implicitly — `cannot be cast automatically to
         * type uuid` — so the schema builder's `change()` is not enough here and
         * `MigrationSafetyTest::the_whole_migration_set_rolls_back_and_migrates_up_again` caught
         * that within a minute of the up() being written.
         *
         * An explicit `USING` clause makes the rollback run on an EMPTY column, which is the only
         * state a rollback test exercises. **It will still fail on real data, and that is
         * deliberate** — every value this column is designed to hold is a path, going back to
         * `uuid` re-creates the outage, and a `down()` that discarded the paths to succeed would
         * be worse than one that refuses.
         */
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'alter table report_exports alter column stored_file_id type uuid using nullif(stored_file_id, \'\')::uuid'
            );

            return;
        }

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->uuid('stored_file_id')->nullable()->change();
        });
    }
};
