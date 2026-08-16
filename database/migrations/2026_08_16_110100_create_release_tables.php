<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release and distribution tracking (ADR 0023).
 *
 * THIS IS OPERATIONAL TRACKING, NOT A LEDGER. The master command draws the boundary and it is
 * worth restating in the schema: there are no journal entries here, no double-entry, no account
 * codes, no bank posting and no reconciliation state. `funding_source` is a **label** a social
 * worker types so a report can be grouped by it — it is not a chart-of-accounts reference, and
 * nothing in this system may start behaving as though it were.
 *
 * The question these tables answer is "did this family actually receive what was approved for
 * them, and who handed it over". That is a welfare question. What the municipality's books say is
 * the treasury's, and inventing a shadow ledger here would produce two sets of figures that
 * disagree — with this one having no auditor.
 *
 * MONEY IS INTEGER CENTAVOS, NOT DECIMAL. See ADR 0023 §1: the master command asks for
 * "fixed-precision decimal columns" and the constitution (Article 4) requires integer minor
 * units. Both forbid floating point and both are exact; the constitution outranks the task
 * instruction, and every other money column in this system and in the Angular client is already
 * centavos. The conflict is recorded rather than silently resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * A distribution run: "AICS payout, Barangay Dolores, 12 August".
             *
             * Exists because releases genuinely happen in batches — a hall, a table, a queue of
             * families and one manifest — and because "who was on the list that day" is the
             * question asked when somebody says they were missed.
             */
            $table->string('reference_number', 32)->unique();
            $table->string('name', 160);
            $table->date('scheduled_for');
            $table->string('location', 255)->nullable();

            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');

            $table->uuid('opened_by')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'scheduled_for'], 'idx_release_batches_schedule');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Quoted by a beneficiary querying what they received. Carries nothing about them.
            $table->string('reference_number', 32)->unique();

            /*
             * ── the trace ─────────────────────────────────────────────────────────────────
             *
             * THE ACCEPTANCE CRITERION: a released record traces to an approved case, a
             * programme and a beneficiary. All three are mandatory except the programme, which is
             * null only for assistance given outside any programme — and even then the case is
             * required, because a release with no case is money leaving with no approval behind
             * it.
             */
            $table->foreignId('welfare_case_id')->constrained('welfare_cases')->cascadeOnDelete();
            $table->uuid('resident_id');
            $table->uuid('program_id')->nullable();
            $table->string('program_code', 32)->nullable();

            /*
             * WHO APPROVED IT, snapshotted at creation.
             *
             * Not read through to the case at release time: the record must say who authorised
             * this specific payment, and a later reassignment or a second approval on the case
             * must not rewrite it. It is also what segregation of duties is checked against —
             * see ADR 0023 §3.
             */
            $table->uuid('approved_by')->nullable();
            $table->string('approval_reference', 64)->nullable();

            // Which instalment of this case's assistance. Assigned inside a lock; a case may
            // legitimately have a schedule, and each part is its own release.
            $table->unsignedSmallInteger('sequence')->default(1);

            $table->enum('kind', ['cash', 'in-kind']);

            /*
             * INTEGER CENTAVOS. Never a float, never a decimal string parsed at the boundary.
             *
             * Null for an in-kind release, where there is no amount handed over — a relief pack
             * has a notional value, and recording that value as though it were money released
             * would put a peso figure against a family that received rice.
             */
            $table->unsignedBigInteger('amount_centavos')->nullable();

            // ISO-4217, explicit. Constant today; a column rather than an assumption, because an
            // amount with no currency is the field every integration eventually guesses at.
            $table->string('currency', 3)->default('PHP');

            // What was actually handed over, for in-kind. Null for cash.
            $table->string('in_kind_description', 255)->nullable();

            $table->string('release_mode', 32);

            /*
             * A LABEL, NOT AN ACCOUNT CODE. Typed by a social worker so a report can be grouped
             * by it. Nothing joins on it, nothing validates it against a chart of accounts, and
             * nothing in this system may start treating it as a posting reference.
             */
            $table->string('funding_source', 120)->nullable();

            $table->foreignId('release_batch_id')->nullable()->constrained('release_batches')->nullOnDelete();
            $table->date('scheduled_for')->nullable();
            $table->string('release_location', 255)->nullable();

            $table->string('status', 24)->default('ready');

            // Who handed it over. Checked against `approved_by` at release time.
            $table->uuid('released_by')->nullable();
            $table->timestampTz('released_at')->nullable();

            /*
             * ── acknowledgement ───────────────────────────────────────────────────────────
             *
             * WHO ACTUALLY TOOK IT, which is frequently not the beneficiary: an elderly person
             * sends a daughter, a bedridden patient sends a neighbour. Recording only "released"
             * loses the one fact a dispute turns on.
             *
             * NO BIOMETRIC IS STORED. `acknowledgement_method` records that a signature or a
             * thumbmark was taken on the paper manifest; the mark itself stays on the paper. A
             * thumbprint image in this database would be biometric data held for a purpose that
             * does not need it (RA 10173, Article 5.2).
             */
            $table->string('acknowledged_by_name', 160)->nullable();
            $table->string('acknowledged_relationship', 64)->nullable();
            $table->enum('acknowledgement_method', ['signature', 'thumbmark', 'digital-confirmation', 'witnessed'])->nullable();
            $table->timestampTz('acknowledged_at')->nullable();

            // Why it did not happen. One column per outcome would invite two to be set at once.
            $table->string('outcome_reason', 255)->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            /*
             * ONE RELEASE PER INSTALMENT PER CASE.
             *
             * The business constraint the master command asks for. It does not prevent a genuine
             * schedule — that is sequence 1, 2, 3 — but it does prevent two rows claiming to be
             * the same instalment, which is the shape a double payment takes.
             */
            $table->unique(['welfare_case_id', 'sequence'], 'uniq_releases_instalment');

            $table->index(['status', 'scheduled_for'], 'idx_releases_schedule');
            $table->index(['release_batch_id', 'status'], 'idx_releases_batch');
            $table->index(['resident_id', 'status'], 'idx_releases_beneficiary');
        });

        /*
         * Append-only. Every movement, with who and why.
         *
         * Money is the one place where "what happened to this record" must be reconstructable
         * without inference, so the transitions are their own table rather than a status column
         * and a hope.
         */
        Schema::create('release_transitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('release_id')->constrained('releases')->cascadeOnDelete();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 255)->nullable();

            $table->uuid('actor_subject_id')->nullable();
            $table->timestampTz('occurred_at');

            // No updated_at: append-only.
            $table->timestampTz('created_at')->nullable();

            $table->index(['release_id', 'occurred_at'], 'idx_release_transitions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_transitions');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('release_batches');
    }
};
