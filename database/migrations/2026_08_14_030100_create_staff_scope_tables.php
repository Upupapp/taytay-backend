<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pieces that make `role_assignments.scope_type` mean something (ADR 0012).
 *
 * TAB 06 shipped a scope column that nothing enforced: a barangay-scoped clerk could read
 * every resident in the municipality. This migration adds the two things enforcement
 * needs — a way to grant a second barangay explicitly, and a way to say whose case a case
 * is.
 *
 * Additive only (Article 6): no existing column changes shape, so this deploys ahead of
 * the code that reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * An explicit, time-bounded grant of one extra barangay to one staff member.
         *
         * A separate table rather than more `role_assignments` rows, because that table is
         * uniquely keyed on (subject_id, role) — deliberately, so a role cannot be held
         * twice — and widening its key to include a nullable barangay would break on
         * PostgreSQL, where NULLs compare distinct (ADR 0008 §5).
         *
         * Separating it also matches how the LGU actually works: "Ana covers San Juan and
         * is helping with Muzon this month" is a grant with an end date, not a change to
         * her job.
         */
        Schema::create('staff_barangay_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Identity account UUID. No FK — cross-module reference (Article 2.2).
            $table->uuid('subject_id');

            $table->foreignId('barangay_id')->constrained('barangays')->restrictOnDelete();

            // Why this person was given access to a barangay that is not theirs. Required:
            // an unexplained cross-barangay grant is exactly what an audit asks about.
            $table->string('reason', 255);

            $table->uuid('granted_by');
            $table->timestampTz('valid_from')->useCurrent();

            // Null means open-ended, which should be rare. A covering grant that nobody
            // remembers to remove is how scope quietly stops meaning anything.
            $table->timestampTz('valid_until')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // One live grant per (person, barangay). Re-granting refreshes the row rather
            // than stacking duplicates that must each be revoked separately.
            $table->unique(['subject_id', 'barangay_id'], 'uniq_staff_barangay_grants');
            $table->index(['subject_id', 'valid_until'], 'idx_staff_barangay_grants_validity');
        });

        /*
         * Who owns this case.
         *
         * `assigned-cases` scope is the narrowest of the three and had nothing to resolve
         * against: without an owner column, "only your own cases" could not be expressed,
         * so the scope silently degraded to something broader.
         */
        Schema::table('kyc_cases', function (Blueprint $table): void {
            // Identity account UUID of the reviewer. No FK — cross-module.
            $table->uuid('assigned_to')->nullable()->after('reviewed_by');

            $table->index(['assigned_to', 'status'], 'idx_kyc_cases_assigned');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_cases', function (Blueprint $table): void {
            $table->dropIndex('idx_kyc_cases_assigned');
            $table->dropColumn('assigned_to');
        });

        Schema::dropIfExists('staff_barangay_grants');
    }
};
