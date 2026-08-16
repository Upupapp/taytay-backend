<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views (ADR 0027).
 *
 * NO SEARCH INDEX IS CREATED HERE, and the reasoning is worth recording because the master command
 * asks for one.
 *
 * It asks for PostgreSQL full-text/trigram capability "as appropriate". A driver-guarded migration
 * creating trigram indexes on PostgreSQL and skipping them on SQLite was written first and
 * removed: `InfrastructureAlignmentTest::migrations_stay_portable_postgresql` forbids raw
 * `DB::statement` in a migration, and that rule has held since TAB 01 for a good reason — the
 * moment one migration is allowed a raw statement, the next one is allowed a slightly less
 * guarded one.
 *
 * The index is also **unmeasured optimisation**, which ADR 0026 §1 already declined for
 * materialised views on the same grounds: Taytay's caseload is thousands of rows, and search runs
 * as a `LIKE` scan in single-digit milliseconds. When there is a measurement, the index goes in as
 * an operational change with the exact SQL recorded in gap G-35 — it has no behavioural effect, so
 * it never needs to be a migration at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->uuid('owner_subject_id');
            $table->string('entity', 48);
            $table->string('name', 120);

            /*
             * The filter set, as a validated structure.
             *
             * A permitted JSON use under ADR 0008 §13 — with a twist that matters: it is
             * **validated against a declared grammar before it is stored**, not merely on the way
             * out. A saved view is executed later, by whoever loads it, and a filter that was
             * never checked at write time is a stored query waiting for somebody to run it
             * (ADR 0027 §3).
             */
            $table->json('filters');

            /*
             * Which columns the user chose to show. Presentation only — nothing reads this to
             * decide what may be disclosed, and the projection withholds what it withholds
             * regardless.
             */
            $table->json('columns')->nullable();

            $table->string('sort', 64)->nullable();

            /*
             * A shared view is visible to other staff, and requires a permission to create.
             *
             * The sharing is of the FILTER, never of the results: two people opening the same
             * shared view see different rows, because each query is scoped to the person running
             * it. A shared view that carried its author's scope would be a way to hand somebody a
             * caseload they cannot otherwise reach.
             */
            $table->boolean('is_shared')->default(false);

            $table->timestampsTz();

            $table->unique(['owner_subject_id', 'entity', 'name'], 'uniq_saved_views_name');
            $table->index(['entity', 'is_shared'], 'idx_saved_views_shared');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
