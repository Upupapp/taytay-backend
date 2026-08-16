<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programme enrolment (ADR 0019).
 *
 * FOUR ROLES, ONE PERSON. The master command asks these to be distinguishable, and they are —
 * without a second person row anywhere:
 *
 *  * **Resident** — the canonical person. `residents`, owned by ResidentProfile.
 *  * **Applicant** — whoever filed the request. `assistance_intakes.submitted_by`, an *account*,
 *    because a daughter may apply for her mother and the audit trail must say which of them
 *    typed it.
 *  * **Beneficiary** — whoever receives. `program_enrollments.resident_id`.
 *  * **Enrollee** — a person *or a household* on a programme's roll. Same row, with
 *    `household_id` set for household-scoped programmes.
 *
 * NO BENEFICIARY TABLE. That is the point of the acceptance criterion "one resident can have
 * many enrolments without duplicate resident records". A `beneficiaries` table would be a
 * second place a person exists, it would drift from the canonical record after the first name
 * correction, and duplicate detection would then have two populations to reconcile instead of
 * one. Everything here references `residents` by identifier.
 *
 * ENROLMENT IS EFFECTIVE-DATED, like household membership (ADR 0014 §2) and vulnerability
 * factors (ADR 0015 §1). Exit closes a row; it never deletes one. "Was this household on the
 * roll when the October tranche was released" has to stay answerable after they leave in
 * November — and that is precisely the question asked when somebody alleges a payment went to
 * a person who should not have had one.
 *
 * Personal-data classification: **sensitive**. An enrolment row says a named person receives
 * welfare assistance from a named programme.
 *
 * NO CROSS-MODULE FOREIGN KEYS: `resident_id`, `household_id` and `program_id` reference other
 * modules by identifier only (Article 2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // ServiceCatalog's programme UUID. No FK — cross-module.
            $table->uuid('program_id');
            $table->string('program_code', 32);

            /*
             * The beneficiary. ResidentProfile's canonical resident UUID.
             *
             * Repointed by the `ResidentMerged` listener when two residents turn out to be one
             * person, so duplicate detection continues to operate on the canonical resident and
             * never on a parallel beneficiary population (ADR 0019 §4).
             */
            $table->uuid('resident_id');

            /*
             * Set when the programme enrols households rather than individuals — relief
             * distribution is per household, 4Ps grants are per family.
             *
             * `resident_id` stays populated even then: the household still has a named contact
             * on the roll, and a household with no attributable person is one nobody can be
             * asked about.
             */
            $table->uuid('household_id')->nullable();

            // The case that produced this enrolment, where one did. Null for a bulk enrolment
            // or a legacy import, and that difference is worth being able to see.
            $table->uuid('source_case_id')->nullable();

            $table->enum('status', ['active', 'suspended', 'exited'])->default('active');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            /*
             * Why they joined and why they left, in an open vocabulary.
             *
             * `exit_reason` matters more than it looks: "graduated", "moved out of Taytay",
             * "no longer eligible" and "found to be duplicate" are four completely different
             * facts about a person, and a boolean `is_active` collapses all of them into one
             * that answers none of the questions anybody actually asks afterwards.
             */
            $table->string('entry_reason', 64)->nullable();
            $table->string('exit_reason', 64)->nullable();
            $table->string('note', 255)->nullable();

            $table->uuid('enrolled_by')->nullable();
            $table->uuid('exited_by')->nullable();

            /*
             * A DERIVED COLUMN THAT EXISTS SOLELY TO CARRY THE INVARIANT INTO THE DATABASE.
             *
             * Holds `program_id` while the enrolment is open and NULL once it closes. Maintained
             * by ProgramEnrollment's saving hook, never written by hand — it is a cache of
             * `effective_to IS NULL`, labelled and derivable per Article 6.1.
             *
             * The invariant that matters is **at most one OPEN enrolment per programme per
             * resident**, because two means the same person counted twice in every payment run.
             * The natural way to say that is a partial unique index, which is not portable to
             * SQLite. This is: both PostgreSQL and SQLite treat NULLs in a unique index as
             * DISTINCT, so closed rows never collide with anything however many there are, while
             * two open rows on one programme collide immediately.
             *
             * The obvious alternative — unique on (program_id, resident_id, effective_from) —
             * was tried first and is WRONG in two directions at once. It permits unlimited open
             * enrolments as long as the start dates differ, which is the actual defect; and it
             * forbids two legitimate same-day rows, which broke both a same-day correction
             * (enrol, exit, re-enrol) and the merge collapse, where two records really were
             * separately enrolled on the same day and one must survive as closed history.
             */
            $table->uuid('open_key')->nullable();

            $table->timestampsTz();

            $table->unique(['resident_id', 'open_key'], 'uniq_program_enrollments_open');
            $table->index(['resident_id', 'effective_to'], 'idx_program_enrollments_resident_open');
            $table->index(['program_id', 'status'], 'idx_program_enrollments_program_status');
            $table->index(['program_id', 'effective_to'], 'idx_program_enrollments_program_open');
            $table->index('household_id', 'idx_program_enrollments_household');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_enrollments');
    }
};
