<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programmes, their requirements and their eligibility guidance (ADR 0018).
 *
 * A PROGRAMME IS A TABLE, NOT CONFIG — unlike services, the ruleset and the assessment forms.
 * The difference is who owns the content and how often it moves: an MSWDO officer opens a
 * relief programme on Tuesday because a storm landed on Monday. A config deploy is the wrong
 * instrument for that, and would make the LGU dependent on a developer to respond to a
 * disaster. Weights and forms move at the pace of policy review; programmes move at the pace
 * of events.
 *
 * ELIGIBILITY GUIDANCE IS ADVISORY, AND THE SCHEMA SAYS SO. `program_eligibility_criteria`
 * holds human-readable rules that produce met / not-met / unknown per criterion, each with a
 * stated reason. There is no score column, no threshold column and no auto-deny flag —
 * deliberately, because the master command forbids this becoming an opaque denial system, and
 * the reliable way to prevent that is to give it nowhere to store an opaque denial.
 *
 * `welfare_case_eligibility_checks` is the audit requirement made real: the guidance version
 * used against a case is pinned at the moment it ran, with every criterion outcome, so a
 * decision defended two years later can be re-derived against the rules that actually applied.
 *
 * Personal-data classification: programmes and requirements are `public`; the eligibility
 * checks are **sensitive**, because a criterion outcome reveals facts about a named person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Quoted on forms and in DSWD reporting. Stable once published.
            $table->string('code', 32)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();

            $table->string('owner_office', 96);

            /*
             * A LABEL, not an amount. "Local funds", "DSWD AICS", "Bayanihan".
             *
             * Deliberately not a budget ledger: this backend tracks welfare operations, not
             * appropriations (ADR 0018 §5). A `budget_remaining` column here would be a second,
             * unreconciled copy of a figure the treasury owns, and the first time it disagreed
             * somebody would trust the wrong one.
             */
            $table->string('funding_source_label', 96)->nullable();

            $table->string('target_population', 191)->nullable();

            $table->string('service_type', 48);
            $table->string('benefit_type', 32);

            /*
             * Whether Taytay decides who receives this.
             *
             * 4Ps and similar national programmes are tracked and referred here, but their
             * eligibility is set by DSWD. Marking them `national` is what stops the guidance
             * engine implying the LGU can admit somebody it cannot (ADR 0018 §4).
             */
            $table->enum('authority', ['local', 'national', 'partner'])->default('local');

            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();

            // The window during which applications are accepted, which is not the same as the
            // window during which the programme exists — a relief operation announces early
            // and opens later.
            $table->timestampTz('applications_open_at')->nullable();
            $table->timestampTz('applications_close_at')->nullable();

            // Working days. A target the office publishes, never a promise the system enforces.
            $table->unsignedSmallInteger('turnaround_target_days')->nullable();

            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');

            /*
             * Whether citizens may see this at all.
             *
             * Separate from `status` on purpose: an internal referral programme can be fully
             * published and operational while remaining invisible to the public catalogue.
             * Collapsing the two would force staff to leave a live programme in `draft` to hide
             * it, and a draft programme accepts no applications.
             */
            $table->boolean('is_citizen_visible')->default(false);

            $table->string('eligibility_guidance_version', 32)->default('1');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'is_citizen_visible'], 'idx_programs_visibility');
            $table->index('authority', 'idx_programs_authority');
            $table->index(['active_from', 'active_to'], 'idx_programs_active_window');
        });

        /** Which channels a programme accepts applications through. */
        Schema::create('program_intake_channels', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('channel', 32);

            $table->timestampsTz();

            $table->unique(['program_id', 'channel'], 'uniq_program_intake_channels');
        });

        /** Who may approve under this programme. A hook for TAB 18's segregation of duties. */
        Schema::create('program_approvers', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();

            // A role name from AccessControl's catalog. Cross-module reference by identifier
            // (Article 2.2) — this table records policy, not a foreign key into authority.
            $table->string('role', 48);

            $table->timestampsTz();

            $table->unique(['program_id', 'role'], 'uniq_program_approvers');
        });

        /*
         * Versioned requirement templates.
         *
         * `template_version` is per programme and bumped whenever the requirement set changes.
         * A case that was returned for a missing document must remain explicable against the
         * requirements that applied when it was returned, not against the ones added since.
         */
        Schema::create('program_requirements', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();

            $table->string('code', 48);
            $table->string('label', 191);

            /*
             * Required / optional / conditional.
             *
             * `conditional` carries `condition_note` in plain language rather than a rule
             * expression. "Required if the applicant is not the patient" is something a clerk
             * reads and applies; encoding it as a machine condition would make a
             * document demand that nobody can explain at the counter.
             */
            $table->enum('obligation', ['required', 'optional', 'conditional'])->default('required');
            $table->string('condition_note', 255)->nullable();

            // Comma-free open vocabulary of accepted document types, one row per type below.
            $table->string('citizen_instructions', 500)->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('template_version', 32)->default('1');

            $table->timestampsTz();

            $table->unique(['program_id', 'code', 'template_version'], 'uniq_program_requirements');
            $table->index(['program_id', 'obligation'], 'idx_program_requirements_obligation');
        });

        /** Accepted document types for a requirement. A row rather than a delimited string. */
        Schema::create('program_requirement_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('program_requirement_id')->constrained('program_requirements')->cascadeOnDelete();
            $table->string('document_type', 48);

            $table->timestampsTz();

            $table->unique(['program_requirement_id', 'document_type'], 'uniq_requirement_documents');
        });

        /*
         * Eligibility guidance criteria.
         *
         * NO SCORE. NO THRESHOLD. NO AUTO-DENY. Each criterion evaluates to met, not-met or
         * unknown against a stated fact, and carries the plain-language reason a clerk will
         * repeat to the applicant. The engine flags likely matches and mismatches; a human
         * decides, and the case lifecycle is the only thing that can refuse anybody.
         *
         * `is_blocking` is the strongest thing here and it still decides nothing — it marks a
         * criterion that, unmet, means the office should look closely, and it is reported as
         * such. It never sets a status.
         */
        Schema::create('program_eligibility_criteria', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();

            $table->string('code', 48);

            /*
             * The fact this criterion reads. A closed set, and deliberately a short one:
             * age-range, barangay, sector, household-size, residency, income-ceiling.
             *
             * NOT the vulnerability score. That is unapproved placeholder weighting
             * (gap G-20), it declares itself decision-support-only, and safeguarding factors
             * contribute nothing to it by design. Letting it decide eligibility would make an
             * unapproved ordering consequential — and would do it one layer removed from
             * anybody who could see it happening (ADR 0018 §3).
             */
            $table->string('fact', 48);

            $table->string('comparator', 16);
            $table->string('value', 191)->nullable();
            $table->string('value_max', 191)->nullable();

            /** What the applicant is told this criterion means. Mandatory. */
            $table->string('citizen_explanation', 255);

            $table->boolean('is_blocking')->default(false);

            $table->string('guidance_version', 32)->default('1');

            $table->timestampsTz();

            $table->unique(['program_id', 'code', 'guidance_version'], 'uniq_program_eligibility');
            $table->index('fact', 'idx_program_eligibility_fact');
        });

        /*
         * An eligibility check that was actually run against a case.
         *
         * THE AUDIT REQUIREMENT MADE REAL: "the eligibility guidance version used in a case is
         * retained". Pinned at the moment it ran, with every criterion outcome, so a decision
         * defended two years later can be re-derived against the rules that actually applied
         * rather than against today's.
         *
         * Append-only: created_at and nothing else. A check that could be edited would be
         * worthless as the evidence it exists to be.
         */
        Schema::create('welfare_case_eligibility_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            // ServiceCatalog's programme UUID. No FK — cross-module (Article 2.2).
            $table->uuid('program_id');
            $table->string('program_code', 32);
            $table->string('guidance_version', 32);

            /*
             * The advisory outcome. `likely-eligible`, `likely-ineligible`, `needs-review`.
             *
             * Named to be unusable as a decision. There is deliberately no `eligible` /
             * `ineligible` value, because the first thing a later feature would do with one is
             * read it as the answer.
             */
            $table->enum('outcome', ['likely-eligible', 'likely-ineligible', 'needs-review']);

            $table->uuid('evaluated_by')->nullable();
            $table->timestampTz('evaluated_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['welfare_case_id', 'evaluated_at'], 'idx_eligibility_checks_case');
            $table->index('program_id', 'idx_eligibility_checks_program');
        });

        /** Per-criterion outcomes for one check. Normalised, so a result is explainable. */
        Schema::create('welfare_case_eligibility_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_eligibility_check_id')
                ->constrained('welfare_case_eligibility_checks')
                ->cascadeOnDelete();

            $table->string('criterion_code', 48);
            $table->string('fact', 48);
            $table->enum('result', ['met', 'not-met', 'unknown']);

            /** Why, in the words the applicant would be given. */
            $table->string('explanation', 255);

            /** The value actually observed, so the outcome can be checked rather than trusted. */
            $table->string('observed_value', 191)->nullable();

            $table->boolean('is_blocking')->default(false);

            $table->timestampTz('created_at')->useCurrent();

            $table->index('welfare_case_eligibility_check_id', 'idx_eligibility_results_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_case_eligibility_results');
        Schema::dropIfExists('welfare_case_eligibility_checks');
        Schema::dropIfExists('program_eligibility_criteria');
        Schema::dropIfExists('program_requirement_documents');
        Schema::dropIfExists('program_requirements');
        Schema::dropIfExists('program_approvers');
        Schema::dropIfExists('program_intake_channels');
        Schema::dropIfExists('programs');
    }
};
