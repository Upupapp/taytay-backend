<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social welfare cases, their transitions, assignments and timeline (ADR 0007, ADR 0016).
 *
 * THE CASE IS THE UNIT OF CASEWORK. One person's request for help, from the counter to the
 * payout, carrying its own state, its own assignee and its own history.
 *
 * `status` is an explicit enumerated state machine, never an inferred boolean pair
 * (CLAUDE.md Article 6). Nothing writes it directly: every move goes through
 * `CaseService::transition()`, which validates the transition map first and records an
 * immutable row in `welfare_case_transitions`.
 *
 * THREE HISTORY TABLES, NOT ONE. They answer different questions and have different
 * retention and visibility:
 *
 *  * `welfare_case_transitions` — what state, when, by whom, why. The evidence behind a
 *    decision, and the only place a `reason` lives.
 *  * `welfare_case_assignments` — who held the file, when. Answers "who was responsible on
 *    the 12th", which is a different question from "what state was it in".
 *  * `welfare_case_events` — the material timeline, including events raised by other modules
 *    (a field visit, a released payout) that have no state of their own.
 *
 * Personal-data classification: **sensitive**. A case links a named person to a request for
 * help, and for `protective` cases its mere existence is the disclosure.
 *
 * NO CROSS-MODULE FOREIGN KEYS. `resident_id`, `household_id`, `assigned_to` and every actor
 * column reference other modules by identifier only (Article 2.2) — ResidentProfile owns
 * residents, Identity owns accounts, and welding the schemas would make the boundary
 * unenforceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * The number quoted over the counter and on paper. Random, not sequential: a
             * sequential case number tells any holder how many cases the LGU has opened and
             * lets them guess their neighbour's.
             */
            $table->string('case_number', 32)->unique();

            $table->string('type', 32);

            // ResidentProfile identifiers. No FK — cross-module (Article 2.2).
            $table->uuid('resident_id');
            $table->uuid('household_id')->nullable();

            /*
             * Denormalised from the resident so the staff queue can be scoped and filtered
             * without asking ResidentProfile for every row.
             *
             * A cache, and labelled as one (ADR 0008 §10). It is authorization-relevant, so
             * it is refreshed whenever a case is written rather than trusted indefinitely; the
             * canonical answer remains the resident's own barangay.
             */
            $table->foreignId('barangay_id')->nullable()->constrained('barangays')->nullOnDelete();

            $table->string('status', 32);
            $table->string('priority', 16)->default('normal');
            // Required when priority is `urgent`: moving somebody ahead of everyone else
            // waiting is a decision that needs a name against it.
            $table->string('priority_reason', 255)->nullable();

            // ServiceCatalog reference, nullable until TAB 13 gives cases a programme.
            $table->uuid('program_id')->nullable();

            // Identity account UUID of the current holder. Nullable — an unassigned case in
            // the queue is a normal and important state, not an error.
            $table->uuid('assigned_to')->nullable();
            $table->timestampTz('assigned_at')->nullable();

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            /*
             * Touched by every material event, so "nothing has happened to this file in three
             * weeks" is an indexed query rather than a scan of the timeline.
             */
            $table->timestampTz('last_activity_at');
            $table->date('next_follow_up_on')->nullable();

            /*
             * Staff-only operational flags. NEVER projected to a citizen (Article 5, ADR 0016
             * §5) — a `needs_home_visit` reaching an applicant tells them they are suspected
             * of something.
             */
            $table->boolean('needs_home_visit')->default(false);
            $table->boolean('is_escalated')->default(false);

            /*
             * Archival is a lifecycle flag, not a state.
             *
             * TAB 01 §E lists "Archived" alongside the operational statuses. Modelling it as
             * one would have meant a terminal state that says nothing about *how* the case
             * ended — a rejected case and a completed case both archive, and collapsing them
             * loses the outcome (ADR 0016 §1).
             */
            $table->timestampTz('archived_at')->nullable();

            $table->uuid('opened_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'priority'], 'idx_welfare_cases_status_priority');
            $table->index(['barangay_id', 'status'], 'idx_welfare_cases_barangay_status');
            $table->index(['assigned_to', 'status'], 'idx_welfare_cases_assignee');
            $table->index('resident_id', 'idx_welfare_cases_resident');
            $table->index('type', 'idx_welfare_cases_type');
            $table->index('last_activity_at', 'idx_welfare_cases_activity');
            $table->index('next_follow_up_on', 'idx_welfare_cases_follow_up');
        });

        /*
         * Immutable status history. Append-only: created_at and nothing else, because a
         * transition log that can be edited is not evidence (ADR 0008 §8).
         */
        Schema::create('welfare_case_transitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            // Null only for the opening row, where there is no previous state.
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            /*
             * TWO REASONS, DELIBERATELY SEPARATE.
             *
             * `reason` is internal — the caseworker's justification, which may reference an
             * assessment, another household member, or a suspicion. `applicant_message` is
             * what the person is told.
             *
             * Collapsing them is how "claimant's account inconsistent with neighbour
             * statements" ends up rendered in a citizen app (ADR 0007, visibility matrix §3).
             */
            $table->string('reason', 500)->nullable();
            $table->string('applicant_message', 500)->nullable();

            $table->uuid('actor_subject_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['welfare_case_id', 'occurred_at'], 'idx_welfare_transitions_case_time');
            $table->index('to_status', 'idx_welfare_transitions_to');
            // Supports the separation-of-duties check: "who endorsed this case" must be
            // answerable cheaply at approval time.
            $table->index(['welfare_case_id', 'to_status'], 'idx_welfare_transitions_case_status');
        });

        /*
         * Who held the file, and when. Effective-dated like membership (ADR 0014 §2) — an
         * open row is the current assignee.
         */
        Schema::create('welfare_case_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            // Identity account UUID. No FK — cross-module.
            $table->uuid('assignee_subject_id');

            // An office or unit label, so a case can be routed to a desk before a person is
            // free to take it. Routing is assignment, never state (ADR 0007 §5).
            $table->string('team', 64)->nullable();

            $table->timestampTz('assigned_at');
            $table->timestampTz('unassigned_at')->nullable();
            $table->string('unassigned_reason', 255)->nullable();

            $table->uuid('assigned_by')->nullable();

            $table->timestampsTz();

            $table->index(['welfare_case_id', 'unassigned_at'], 'idx_welfare_assignments_open');
            $table->index(['assignee_subject_id', 'unassigned_at'], 'idx_welfare_assignments_assignee');
        });

        /*
         * The material timeline.
         *
         * Carries events other modules raise — a field visit, a requirement satisfied, a
         * payout released — which have no state of their own but belong on the file. Written
         * through the Welfare application service, never by another module reaching in.
         *
         * `is_citizen_visible` is a per-row decision made at write time by the code that
         * knows what the event contains. A visibility rule applied at render time gets
         * forgotten by the next endpoint; a column travels with the row.
         */
        Schema::create('welfare_case_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            // Open vocabulary: every later TAB adds event types (assessment.completed,
            // requirement.satisfied, referral.sent, visit.conducted, release.confirmed).
            $table->string('event_type', 64);

            /*
             * A short operator-facing summary. NOT a case narrative — narrative belongs to
             * case notes under their own visibility rules (TAB 17), and putting it here would
             * smuggle staff deliberation into a structure a citizen endpoint reads from.
             */
            $table->string('summary', 255);

            // What a citizen is told, when anything. Null means the event is staff-only even
            // if `is_citizen_visible` were ever flipped by mistake — belt and braces on the
            // one boundary that leaks worst.
            $table->string('citizen_message', 255)->nullable();

            $table->boolean('is_citizen_visible')->default(false);

            $table->uuid('actor_subject_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['welfare_case_id', 'occurred_at'], 'idx_welfare_events_case_time');
            $table->index(['welfare_case_id', 'is_citizen_visible'], 'idx_welfare_events_visible');
            $table->index('event_type', 'idx_welfare_events_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_case_events');
        Schema::dropIfExists('welfare_case_assignments');
        Schema::dropIfExists('welfare_case_transitions');
        Schema::dropIfExists('welfare_cases');
    }
};
