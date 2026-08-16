<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field visits, case notes and safeguarding (ADR 0022).
 *
 * WHAT IS DELIBERATELY ABSENT FROM `field_visits`, AND ENFORCED BY TEST:
 *
 * no latitude, no longitude, no coordinates, no check-in, no route, no device-taken arrival
 * timestamp, no geofence, no "worker location". Not one field that records **where a worker was**
 * rather than **what they found**.
 *
 * The master command forbids continuous location tracking, geofencing and background
 * surveillance. Those are easy to refuse as features and easy to acquire as columns — a
 * "visit_location" added in good faith to help a supervisor plan routes is the first half of a
 * system that records where poor families live and who visited them when. So the absence is
 * enforced by `NoLocationTrackingTest` rather than merely intended, exactly as the admin console
 * enforces it with `tools/check-visits.mjs`.
 *
 * What a visit *does* record is the address it was made to — which the household registry already
 * holds — and what happened there.
 *
 * FIVE TABLES:
 *
 *  * `field_visits` — the visit, its purpose, its outcome and its follow-up.
 *  * `field_visit_checklist_items` — what the worker set out to check. A prompt, never a score.
 *  * `visit_observations` — **what was recorded, and whose claim it is.**
 *  * `case_notes` — the running record, held at one of two sensitivities.
 *  * `safeguarding_concerns` — the restricted tier, with its own permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_visits', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('reference_number', 32)->unique();

            // ResidentProfile identifiers. No FK — cross-module (Article 2.2). Repointed on merge.
            $table->uuid('resident_id');
            $table->uuid('household_id')->nullable();

            // A visit may precede any case: a barangay reports a family and somebody goes to look.
            $table->foreignId('welfare_case_id')->nullable()->constrained('welfare_cases')->nullOnDelete();

            $table->string('status', 24)->default('scheduled');
            $table->string('purpose', 32);

            // Identity's account UUID. Whose visit it is, and therefore whose overdue work.
            $table->uuid('assigned_to')->nullable();
            $table->uuid('scheduled_by')->nullable();

            $table->date('scheduled_for');

            /*
             * Roughly when, in the office's own words: "morning", "after 2pm".
             *
             * Free text rather than a time, because that is how visits are actually arranged with
             * a household that has no diary — and a precise `scheduled_at` timestamp would be a
             * false record of a promise nobody made.
             */
            $table->string('scheduled_window', 64)->nullable();

            /*
             * The address visited, COPIED from the household record at scheduling.
             *
             * Copied rather than referenced because a household that moves must not silently
             * rewrite where a past visit was made — the record would then claim the worker went
             * somewhere they did not.
             */
            $table->string('address_visited', 255);

            // What the household needs, in the worker's words. Feeds the follow-up.
            $table->string('service_needs', 500)->nullable();

            // Why the household declined, if they said. Only meaningful for `refused`.
            $table->string('declined_reason', 255)->nullable();

            $table->string('outcome', 500)->nullable();
            $table->string('next_action', 500)->nullable();
            $table->date('follow_up_on')->nullable();

            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['assigned_to', 'status', 'scheduled_for'], 'idx_visits_worker_queue');
            $table->index(['status', 'scheduled_for'], 'idx_visits_overdue');
            $table->index(['resident_id', 'status'], 'idx_visits_resident');
            $table->index('welfare_case_id', 'idx_visits_case');
        });

        Schema::create('field_visit_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_visit_id')->constrained('field_visits')->cascadeOnDelete();

            $table->string('code', 48);
            $table->string('label', 160);

            /*
             * A PROMPT, NEVER A SCORE. The ticks feed the observations a person writes, and
             * nothing derives an eligibility or a vulnerability rating from them. A checklist
             * that totals is a checklist that decides, and this one must not (ADR 0015 §4).
             */
            $table->boolean('checked')->default(false);
            $table->string('note', 255)->nullable();

            $table->unique(['field_visit_id', 'code'], 'uniq_visit_checklist');
        });

        /*
         * ── what was recorded, and whose claim it is ──────────────────────────────────────
         *
         * THE MOST IMPORTANT TABLE IN THIS MIGRATION, and the reason it is a table at all rather
         * than a text column on the visit.
         *
         * Consider three sentences a worker might write in one paragraph:
         *
         *   "The roof is missing sheets over the sleeping area."
         *   "She says her husband has not sent money since March."
         *   "The household appears unable to meet its own food costs."
         *
         * The first is checkable. The second is a report the office is repeating, and may be
         * wrong without anybody lying. The third is a professional judgement a later reader may
         * disagree with. Written as one block of prose they become indistinguishable, and six
         * months on a different worker reads all three as established fact about the family.
         *
         * Nothing here stops a worker recording a judgement. It stops a judgement from being
         * mistaken for something the family said.
         */
        Schema::create('visit_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('field_visit_id')->constrained('field_visits')->cascadeOnDelete();

            $table->enum('kind', ['observed', 'client-said', 'third-party-said', 'worker-assessed']);
            $table->string('body', 1000);

            /*
             * Who said it, for `third-party-said`. Required for that kind and refused for every
             * other: "a neighbour said" with no neighbour named is a rumour the office cannot
             * check and cannot answer for, and an attribution on the worker's own observation
             * would read as though somebody else vouched for it.
             */
            $table->string('attributed_to', 160)->nullable();

            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('recorded_at');

            // Append-only: no updated_at.
            $table->timestampTz('created_at')->nullable();

            $table->index(['field_visit_id', 'kind'], 'idx_observations_kind');
        });

        /*
         * ── the running record ────────────────────────────────────────────────────────────
         *
         * TWO SENSITIVITIES, AND THE SECOND IS NARROW ON PURPOSE.
         *
         * `routine` is the ordinary record — a home visit, a phone call, a document received.
         * Anyone who may open the case may read it.
         *
         * `protected` is safety planning for a VAWC survivor (RA 9262), anything identifying a
         * child in conflict with the law (RA 9344), a third party's disclosure given in
         * confidence, or clinical detail.
         */
        Schema::create('case_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();

            $table->enum('sensitivity', ['routine', 'protected'])->default('routine');
            $table->text('body');

            $table->uuid('author_subject_id')->nullable();

            /*
             * Append-only. A note is a contemporaneous record; editing one changes what the file
             * says the office knew at the time, which is the single most useful property it has
             * in a dispute.
             *
             * A mistake is corrected by a later note, and withdrawal is a stamp rather than a
             * delete — the fact that something was written and retracted is itself part of the
             * record (ADR 0022 §3).
             */
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('withdrawn_reason', 255)->nullable();
            $table->uuid('withdrawn_by')->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['welfare_case_id', 'sensitivity'], 'idx_case_notes_sensitivity');
        });

        /*
         * ── safeguarding ──────────────────────────────────────────────────────────────────
         *
         * A SEPARATE TABLE, NOT A FLAG ON THE CASE. Three reasons, and each on its own would be
         * enough:
         *
         *  * a boolean on `welfare_cases` is selected by every list query in the system, so it
         *    would reach every queue, export and count — the "minimal list-view exposure" the
         *    master command asks for is impossible once the column travels with the row;
         *  * a concern has an author, a date, a category and a review; a flag has none of those,
         *    and "who decided this family is a risk, and when" is the first question asked;
         *  * concerns are closed. A flag that is cleared leaves nothing behind, so a family
         *    carries a suspicion nobody can find the origin of, or loses one that mattered.
         */
        Schema::create('safeguarding_concerns', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->uuid('resident_id');
            $table->foreignId('welfare_case_id')->nullable()->constrained('welfare_cases')->nullOnDelete();

            // What kind of concern, in a short closed vocabulary. Even the category is restricted:
            // "child-protection" against a named family is itself the disclosure.
            $table->string('category', 48);

            $table->enum('status', ['open', 'monitoring', 'closed'])->default('open');

            /*
             * The detail. Read only by a holder of `safeguarding.view`, and never included in any
             * list projection by any endpoint.
             */
            $table->text('detail');

            /*
             * Whether somebody attending this address needs to be told something for their own
             * safety.
             *
             * Held apart from `detail` because it answers a different question and has a wider
             * audience: a worker being sent to a house is entitled to know there is a risk to
             * them without being told a family's protection history. The advisory says what to
             * do; the detail says why.
             */
            $table->string('worker_safety_advisory', 255)->nullable();

            $table->uuid('raised_by')->nullable();
            $table->timestampTz('raised_at');

            $table->uuid('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['resident_id', 'status'], 'idx_safeguarding_resident');
            $table->index(['welfare_case_id', 'status'], 'idx_safeguarding_case');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_concerns');
        Schema::dropIfExists('case_notes');
        Schema::dropIfExists('visit_observations');
        Schema::dropIfExists('field_visit_checklist_items');
        Schema::dropIfExists('field_visits');
    }
};
