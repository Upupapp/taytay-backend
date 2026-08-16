<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a case still owes, and what the office asked for (ADR 0020 §6).
 *
 * TWO TABLES FOR TWO DIFFERENT OBLIGATIONS, which is the distinction the admin console draws and
 * is right to draw:
 *
 *  * `welfare_case_requirements` — the slots this case must fill. Derived from the programme's
 *    requirement template at intake, then owned by the case.
 *  * `document_requests` — the office telling the applicant to bring something. Owed by the
 *    *applicant*, not by staff, which is why it is not a case task: a task is late when staff
 *    are late, a request when the applicant is.
 *
 * WHY THE REQUIREMENTS ARE COPIED RATHER THAN READ THROUGH. A programme's requirement list is
 * versioned and changes; a case must stay explicable against the list in force when it was
 * opened. Reading through to the live template would silently rewrite what an applicant was
 * asked for, and a case approved under three requirements would later appear to have skipped a
 * fourth that did not exist at the time (the same reasoning as the pinned guidance version in
 * ADR 0018).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_case_requirements', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            /*
             * Copied from the programme's requirement template, with the version that produced
             * them, so the case can always be read against the rules it was actually judged by.
             */
            $table->string('requirement_code', 64);
            $table->string('label', 160);
            $table->string('template_version', 16);
            $table->enum('obligation', ['required', 'optional', 'conditional']);

            // Kept with the requirement because it is what a clerk reads out at the counter, and
            // a copy that lags the template is better than a live one that changes mid-case.
            $table->string('citizen_instructions', 500)->nullable();

            /*
             * A CONDITIONAL REQUIREMENT WAITS FOR A HUMAN.
             *
             * The software states the condition and never evaluates it. Deciding that somebody
             * does NOT need a document is exactly as consequential as deciding that they do —
             * it is the step that can quietly waive a safeguard — so it is a recorded decision
             * with an author and a mandatory reason, not an inference.
             */
            $table->enum('applicability', ['undecided', 'applies', 'does-not-apply'])->default('undecided');
            $table->string('applicability_reason', 255)->nullable();
            $table->uuid('applicability_decided_by')->nullable();
            $table->timestampTz('applicability_decided_at')->nullable();

            /*
             * The Files module's document UUID. No FK — cross-module (Article 2.2).
             *
             * Null until something is presented. The version history, verification state and the
             * file itself all live in Files; this column is only the pointer that says which
             * slot they belong to.
             */
            $table->uuid('document_id')->nullable();

            $table->timestampsTz();

            $table->unique(['welfare_case_id', 'requirement_code'], 'uniq_case_requirements');
            $table->index(['welfare_case_id', 'obligation'], 'idx_case_requirements_obligation');
        });

        Schema::create('document_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();
            $table->foreignId('welfare_case_requirement_id')->constrained('welfare_case_requirements')->cascadeOnDelete();

            $table->enum('state', ['open', 'answered', 'withdrawn'])->default('open');

            // How the applicant was actually told. Recorded because "we called them" and "we
            // told them at the counter" are different claims to have to stand behind.
            $table->enum('channel', ['in-person', 'sms', 'phone-call', 'barangay-relay']);

            /*
             * WHAT THE APPLICANT WAS TOLD, IN THE WORDS USED. Mandatory.
             *
             * Before this existed, "we told them to bring the barangay certificate" lived in
             * somebody's memory. That fails the applicant twice: a different clerk asks again on
             * their next visit, and if they say nobody told them, the office has nothing to
             * check. An empty message is worse than no record — it looks like the office
             * followed up when it cannot show what it said.
             */
            $table->string('message', 500);

            $table->date('needed_by')->nullable();

            $table->uuid('requested_by')->nullable();
            $table->timestampTz('requested_at');

            // Set when the state leaves `open`. Never unset.
            $table->timestampTz('closed_at')->nullable();
            $table->string('withdrawn_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['welfare_case_id', 'state'], 'idx_document_requests_case');
            $table->index(['state', 'needed_by'], 'idx_document_requests_overdue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('welfare_case_requirements');
    }
};
