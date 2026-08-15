<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Households, families, effective-dated membership and kinship (ADR 0014).
 *
 * A HOUSEHOLD IS NOT A FAMILY. A household is who sleeps under one roof; a family is who
 * belongs to one another. Philippine households routinely contain several families — a
 * married couple, a widowed parent, a sibling's children — and welfare programmes target
 * each differently: relief goods are distributed per household, 4Ps grants per family.
 * Collapsing the two would make one of those two counts permanently wrong, and the wrong
 * one changes depending on the programme.
 *
 * MEMBERSHIP IS EFFECTIVE-DATED, NOT A CURRENT-STATE COLUMN. `household_memberships` and
 * `family_memberships` carry `effective_from`/`effective_to`. Moving a resident closes one
 * row and opens another; it never edits or deletes the first. The question "who lived here
 * when the October relief was distributed" has to remain answerable after the family moves
 * in November, and a mutable `household_id` on `residents` could not answer it.
 *
 * Personal-data classification: **sensitive**, inherited from `residents`. Composition of a
 * household is itself personal data about every member — it reveals who a person lives with,
 * which for a VAWC survivor is precisely the fact that must not leak.
 *
 * NO JSON COLUMNS (ADR 0008 §13). Utilities and sanitation are reported to DSWD and filtered
 * on; they are normalised columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Human-readable and quotable over the phone. Random rather than sequential: a
            // sequential code tells any holder how many households the LGU has enrolled and
            // lets them guess their neighbour's.
            $table->string('code', 32)->unique();

            $table->foreignId('barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->string('street_address', 191);
            $table->string('purok_or_sitio', 96)->nullable();

            /*
             * The head of the household, by primary key within this module.
             *
             * Nullable: a household whose head has died or moved out still exists and still
             * receives assistance. Forcing a head would make staff invent one, and an
             * invented head is a person the LGU will later address letters to.
             *
             * `nullOnDelete` rather than cascade — losing a head must never delete the
             * household and orphan its members.
             */
            $table->foreignId('head_resident_id')->nullable()->constrained('residents')->nullOnDelete();

            // Structure and legal relation are different facts. A family can own the
            // structure and rent the land, or occupy a concrete house informally.
            $table->enum('dwelling_type', ['concrete', 'semi-concrete', 'light-materials', 'makeshift', 'institutional', 'other'])
                ->default('other');
            $table->enum('tenure_status', ['owner', 'renter', 'sharer', 'caretaker', 'informal-settler', 'other'])
                ->default('other');

            // Open vocabulary: the statutory categories DSWD reports against change by
            // circular, and widening a check constraint would be a table rewrite.
            $table->string('electricity_source', 48)->nullable();
            $table->string('water_source', 48)->nullable();
            $table->string('toilet_facility', 48)->nullable();

            /*
             * Deliberately NOT a `member_count` column.
             *
             * A stored count is a cache of `household_memberships` that drifts the first time
             * a membership is closed by a path that forgets to decrement it — and the drift
             * is invisible, because nothing compares the two. Member count is derived at read
             * time from the open memberships (ADR 0008 §10, ADR 0014 §2).
             */

            $table->enum('verification_status', ['unverified', 'field-verified', 'rejected'])
                ->default('unverified');
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();

            // Cached and derivable: recomputed whenever the record is written, and never the
            // basis of an authorization or eligibility decision.
            $table->unsignedTinyInteger('profile_completeness')->default(0);

            // Dissolution is a state, never a deletion: assistance history references the
            // household that received it (ADR 0008 §3).
            $table->enum('status', ['active', 'dissolved', 'archived'])->default('active');
            $table->string('status_reason', 255)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['barangay_id', 'status'], 'idx_households_barangay_status');
            $table->index('verification_status', 'idx_households_verification');
            $table->index('head_resident_id', 'idx_households_head');
        });

        /*
         * A family unit inside a household.
         *
         * Several per household is the normal case, not the exception — which is why this is
         * its own table rather than a flag on the membership row.
         */
        Schema::create('families', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 32)->unique();

            // Restrict, not cascade: deleting a household out from under its families would
            // silently destroy the units that welfare grants are actually paid to.
            $table->foreignId('household_id')->constrained('households')->restrictOnDelete();

            $table->foreignId('head_resident_id')->nullable()->constrained('residents')->nullOnDelete();

            // "The Dela Cruz family". A label for staff, never an identifier.
            $table->string('label', 96)->nullable();

            $table->enum('verification_status', ['unverified', 'field-verified', 'rejected'])
                ->default('unverified');

            $table->enum('status', ['active', 'dissolved', 'archived'])->default('active');
            $table->string('status_reason', 255)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['household_id', 'status'], 'idx_families_household_status');
        });

        /*
         * Resident ↔ household, effective-dated.
         *
         * An open row (`effective_to IS NULL`) means "lives here now". Closing it and opening
         * another is how a transfer is recorded, so the previous residence survives.
         */
        Schema::create('household_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Why the membership ended. 'transferred', 'moved-out', 'deceased', 'corrected'.
            // Open vocabulary — a correction and a real move are different facts, and a
            // boolean could not tell a data-entry fix from a family actually leaving.
            $table->string('end_reason', 48)->nullable();

            $table->uuid('recorded_by')->nullable();

            $table->timestampsTz();

            /*
             * The duplicate-relationship guard (ADR 0008 §6).
             *
             * Keyed on the start date rather than on the pair alone, because the same
             * resident legitimately returns to the same household later and that must be a
             * second row. `effective_to` is deliberately OUT of the key: it is nullable, and
             * PostgreSQL treats NULLs as distinct, so including it would allow unlimited
             * duplicate open rows — exactly what this constraint exists to stop.
             *
             * "At most one OPEN membership per resident across all households" cannot be
             * expressed portably as a partial index, so it is enforced in
             * HouseholdMembershipService and proven by test.
             */
            $table->unique(['household_id', 'resident_id', 'effective_from'], 'uniq_household_memberships');
            $table->index(['resident_id', 'effective_to'], 'idx_household_memberships_resident_open');
            $table->index(['household_id', 'effective_to'], 'idx_household_memberships_household_open');
        });

        /** Resident ↔ family, same effective-dated shape. */
        Schema::create('family_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('end_reason', 48)->nullable();

            $table->uuid('recorded_by')->nullable();

            $table->timestampsTz();

            $table->unique(['family_id', 'resident_id', 'effective_from'], 'uniq_family_memberships');
            $table->index(['resident_id', 'effective_to'], 'idx_family_memberships_resident_open');
            $table->index(['family_id', 'effective_to'], 'idx_family_memberships_family_open');
        });

        /*
         * Kinship between two residents.
         *
         * ONE DIRECTED ROW PER RELATIONSHIP. "A is parent of B" is stored; "B is child of A"
         * is *derived* on read, never stored. Storing both would let the two disagree after
         * one is edited, and there is no principled way to decide which is then correct.
         *
         * The database cannot validate real family structures and this schema does not
         * pretend otherwise: it prevents self-relations and exact duplicates, and leaves
         * everything else — a person with three recorded guardians, a spouse relationship
         * that outlives a separation nobody reported — to human review. A schema that tried
         * to enforce more would start rejecting real families.
         */
        Schema::create('resident_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('related_resident_id')->constrained('residents')->cascadeOnDelete();

            // Read as "<resident> is the <type> of <related_resident>".
            $table->enum('type', [
                'parent', 'child', 'guardian', 'ward', 'spouse', 'partner',
                'sibling', 'dependent', 'provider', 'other',
            ]);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('end_reason', 48)->nullable();

            $table->string('note', 255)->nullable();

            $table->uuid('recorded_by')->nullable();

            $table->timestampsTz();

            // One directed row per kind of tie between two people. A second identical row is
            // never new information.
            $table->unique(['resident_id', 'related_resident_id', 'type'], 'uniq_resident_relationships');
            $table->index(['related_resident_id', 'type'], 'idx_resident_relationships_inverse');
            $table->index('effective_to', 'idx_resident_relationships_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_relationships');
        Schema::dropIfExists('family_memberships');
        Schema::dropIfExists('household_memberships');
        Schema::dropIfExists('families');
        Schema::dropIfExists('households');
    }
};
