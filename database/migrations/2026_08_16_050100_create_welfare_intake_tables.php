<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistance intake, drafts and assessment (ADR 0017).
 *
 * THREE TABLES FOR THREE DIFFERENT LIFETIMES.
 *
 *  * `assistance_drafts` — work in progress, owned by whoever is typing, expiring on a clock.
 *    Deliberately NOT a case in `draft` status: an abandoned half-filled form is not a request
 *    the office has been asked to act on, and putting it in the case queue would fill the
 *    backlog with things nobody submitted. It also means an unsubmitted draft can be purged
 *    on a retention timer without deleting casework (RA 10173 data minimisation).
 *  * `assistance_intakes` — what was actually submitted, one per case, immutable in substance.
 *  * `assessments` + `assessment_answers` — the social worker's structured findings.
 *
 * INTAKE IS SEPARATE FROM THE CASE. `welfare_cases` holds the lifecycle; this holds what the
 * applicant said. Merging them would put a free-text narrative on the row every staff queue
 * selects, and would make "what did they originally ask for" unanswerable after the first
 * correction.
 *
 * Personal-data classification: **sensitive**. `narrative` is the applicant's own account of
 * their circumstances — often the most revealing text in the whole system.
 *
 * NO CROSS-MODULE FOREIGN KEYS: `resident_id`, `household_id` and every actor column reference
 * other modules by identifier only (Article 2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A form somebody is still filling in.
         *
         * Owned by the account that created it — a citizen resuming on another device, or the
         * clerk who started it at the counter. Ownership is checked on every read and write;
         * a draft contains a narrative the applicant has not yet chosen to submit.
         */
        Schema::create('assistance_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Identity account UUID. No FK — cross-module (Article 2.2).
            $table->uuid('owner_subject_id');

            // ResidentProfile identifier. Nullable while a clerk is still identifying who is
            // in front of them.
            $table->uuid('resident_id')->nullable();
            $table->uuid('household_id')->nullable();

            $table->string('source', 32);

            // The intake fields, as far as they have been filled in. All nullable: a draft is
            // by definition incomplete, and NOT NULL here would force clients to invent
            // placeholder values that then get submitted.
            $table->string('category', 48)->nullable();
            $table->string('urgency', 16)->nullable();
            $table->text('narrative')->nullable();
            $table->uuid('requested_service_id')->nullable();

            /*
             * Proof the applicant was shown the privacy notice, and which version.
             *
             * Required before a self-service submission (ADR 0017 §2). Held on the draft so
             * the acknowledgement travels with the form the applicant was actually looking at,
             * rather than being asserted at the last moment by whatever client posts.
             */
            $table->string('consent_reference', 64)->nullable();
            $table->string('privacy_notice_version', 32)->nullable();

            /*
             * Retention clock. A draft is transient by design; this is what a scheduled purge
             * reads (TAB 31). Storing an expiry rather than inferring one from `updated_at`
             * means the policy is visible in the row instead of buried in a job.
             */
            $table->timestampTz('expires_at');

            // Set when the draft became a case. The draft is kept, not deleted, so a resumed
            // submission can be told "you already sent this" rather than silently starting again.
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_case_id')->nullable();

            $table->timestampsTz();

            $table->index(['owner_subject_id', 'submitted_at'], 'idx_assistance_drafts_owner');
            $table->index('expires_at', 'idx_assistance_drafts_expiry');
        });

        /*
         * What was submitted. One per case.
         *
         * Substantively immutable: a correction to the applicant's own account is a new
         * statement on the case timeline, not a rewrite of what they first said. The office
         * has to be able to show what it was told when it decided.
         */
        Schema::create('assistance_intakes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // One intake per case — the guard is the unique key, not a convention.
            $table->foreignId('welfare_case_id')->unique()->constrained('welfare_cases')->cascadeOnDelete();

            $table->uuid('resident_id');
            $table->uuid('household_id')->nullable();

            $table->string('source', 32);

            // Open vocabulary: presenting needs are defined by DSWD circular and arrive by
            // legislation, so widening a check constraint would be a table rewrite.
            $table->string('category', 48);
            $table->enum('urgency', ['routine', 'priority', 'emergency'])->default('routine');

            /** The applicant's own account, in their words. The most revealing text here. */
            $table->text('narrative');

            $table->uuid('requested_service_id')->nullable();

            // Consent evidence, carried over from the draft.
            $table->string('consent_reference', 64)->nullable();
            $table->string('privacy_notice_version', 32)->nullable();

            // Which draft produced this, where one did. Null for a counter intake typed
            // straight in.
            $table->uuid('draft_id')->nullable();

            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at');

            $table->timestampsTz();

            $table->index('resident_id', 'idx_assistance_intakes_resident');
            $table->index('category', 'idx_assistance_intakes_category');
            $table->index(['urgency', 'submitted_at'], 'idx_assistance_intakes_urgency');
        });

        /*
         * A social worker's structured findings on a case.
         *
         * `template_code` + `template_version` pin the form as it stood when the assessment was
         * made. Without the version, a later edit to the form silently changes what a past
         * assessment appears to have asked — and the answers stop meaning what they meant.
         */
        Schema::create('assessments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            $table->string('template_code', 48);
            $table->string('template_version', 16);

            $table->enum('status', ['in-progress', 'completed'])->default('in-progress');

            /*
             * The recommendation. Nullable until completion, and it is a RECOMMENDATION — the
             * case still needs a human with approval authority to act on it, and that person
             * may not be this assessor (ADR 0016 §6).
             */
            $table->string('recommendation', 32)->nullable();
            $table->string('recommendation_reason', 500)->nullable();

            /** The assessor's professional narrative. Staff-only, always. */
            $table->text('findings')->nullable();

            $table->uuid('assessor_subject_id')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['welfare_case_id', 'status'], 'idx_assessments_case_status');
            $table->index('assessor_subject_id', 'idx_assessments_assessor');
        });

        /*
         * One answer per question, normalised.
         *
         * A JSON blob would be unqueryable the first time somebody asks "how many assessed
         * households reported no income earner this quarter" — which is the question these
         * forms exist to answer (ADR 0008 §13).
         */
        Schema::create('assessment_answers', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();

            $table->string('question_code', 64);
            $table->string('answer_value', 500)->nullable();

            $table->timestampsTz();

            // One answer per question per assessment: two rows for the same question is an
            // assessment with no defined answer.
            $table->unique(['assessment_id', 'question_code'], 'uniq_assessment_answers');
            $table->index('question_code', 'idx_assessment_answers_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('assistance_intakes');
        Schema::dropIfExists('assistance_drafts');
    }
};
