<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical resident registry's stewardship tables (ADR 0013).
 *
 * `residents` (migration 2026_08_14_020100) holds who a person *is now*. These tables hold
 * everything about how that row came to say what it says: what it used to say, who changed
 * it, what the resident asked to have corrected, which other row turned out to be the same
 * person, and which accounts are allowed to act for them.
 *
 * WHY THIS IS NOT JUST AN AUDIT LOG. `audit_entries` records that something happened, in
 * one sentence, deliberately without the data (Article 5.5). That is right for
 * accountability and useless for operations: a clerk restoring a mis-merged record needs
 * the previous value, not a note that a value changed. These tables are operational
 * history, scoped to one module, and they are why a merge is reversible in practice.
 *
 * Personal-data classification: **sensitive**, same as `residents`. `resident_aliases` in
 * particular holds names — that is its entire purpose — so it inherits every rule that
 * applies to the canonical row.
 *
 * NO JSON COLUMNS ANYWHERE HERE (ADR 0008 §13). A proposed correction and a preserved
 * alias are both application state that gets filtered, counted and authorized on; a bag of
 * key/values would be unqueryable the first time somebody asks "how many address
 * corrections are pending in Muzon".
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every material change to a canonical resident, append-only.
         *
         * Created, verified, deactivated, corrected, merged away — one row each, carrying
         * the before and after of the single field that moved. This is the "status history"
         * an admin screen renders, and the evidence trail behind a disputed benefit.
         */
        Schema::create('resident_status_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            // Open vocabulary: created, verification-changed, field-corrected, deactivated,
            // reactivated, merged-into, absorbed. New stewardship actions arrive with every
            // later TAB and must not require a check-constraint rewrite (ADR 0008 §5).
            $table->string('event', 48);

            /*
             * Which field moved, and to what. Null for events that are not a field change
             * (a merge, for instance, is recorded as one event plus its own row in
             * `resident_merges`).
             *
             * `previous_value` and `new_value` are short text because they hold one field:
             * a name part, a barangay id, a tier. They are NOT a serialised record.
             */
            $table->string('field', 64)->nullable();
            $table->string('previous_value', 191)->nullable();
            $table->string('new_value', 191)->nullable();

            $table->string('reason', 255)->nullable();

            // Identity account UUID. No FK — cross-module reference (Article 2.2).
            $table->uuid('actor_subject_id')->nullable();

            $table->timestampTz('occurred_at');

            // Append-only: created_at and nothing else. There is no `updated_at` because
            // there is no legitimate reason to edit a history row (ADR 0008 §8).
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['resident_id', 'occurred_at'], 'idx_resident_events_resident_time');
            $table->index('event', 'idx_resident_events_event');
        });

        /*
         * Names this resident has also been known by.
         *
         * Two sources, one purpose. A merge writes the absorbed record's name here, and a
         * name correction writes the superseded name. Both exist so that SEARCH STILL
         * FINDS THE PERSON: a clerk holding a three-year-old paper form types the old name,
         * and if the registry has quietly forgotten it, the clerk concludes the resident is
         * not enrolled and creates the duplicate this whole design exists to prevent.
         */
        Schema::create('resident_aliases', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            $table->string('first_name', 96);
            $table->string('middle_name', 96)->nullable();
            $table->string('last_name', 96);
            $table->string('suffix', 16)->nullable();
            $table->date('birth_date')->nullable();

            // 'merge' | 'correction'. Closed in practice but left as a string: an alias
            // imported from a legacy registry is coming in a later TAB.
            $table->string('source', 32);

            // The merge or correction that produced this alias, by public uuid.
            $table->uuid('source_reference')->nullable();

            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();

            // Same alias recorded twice tells a clerk nothing and inflates every search
            // result. The guard is the name tuple, not the source: two different merges can
            // legitimately contribute the same former name once.
            $table->unique(
                ['resident_id', 'first_name', 'middle_name', 'last_name', 'suffix'],
                'uniq_resident_aliases'
            );
            $table->index(['last_name', 'first_name'], 'idx_resident_aliases_name');
        });

        /*
         * Two canonical rows that may be the same person.
         *
         * Distinct from `resident_match_candidates`, which links a KYC *case* to residents
         * during onboarding. This links resident to resident, and it is how a duplicate
         * that slipped through — or arrived by bulk import — gets found and resolved
         * afterwards.
         */
        Schema::create('resident_duplicate_pairs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * Normalised ordering: `lower_resident_id` is always the smaller primary key.
             * Without that, (A,B) and (B,A) are two rows describing one question, and two
             * reviewers can reach opposite conclusions about the same pair.
             */
            $table->foreignId('lower_resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('higher_resident_id')->constrained('residents')->cascadeOnDelete();

            $table->string('rule', 64);
            $table->enum('confidence', ['partial', 'strong', 'exact']);

            $table->enum('decision', ['undecided', 'same-person', 'different-person'])
                ->default('undecided');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();

            $table->timestampsTz();

            // The duplicate-relationship guard (ADR 0008 §6). Both columns are NOT NULL, so
            // this constraint actually bites on PostgreSQL.
            $table->unique(['lower_resident_id', 'higher_resident_id'], 'uniq_resident_duplicate_pairs');
            $table->index('decision', 'idx_resident_duplicate_pairs_decision');
        });

        /*
         * An executed merge, append-only.
         *
         * The survivor keeps its uuid and every reference that pointed at the absorbed row
         * is repointed at it. The absorbed row is soft-deleted, never destroyed: a merge
         * decided in error must be traceable, and a hard delete makes the mistake
         * unprovable as well as unrecoverable.
         *
         * The reassignment counts are recorded because they are the only cheap way to
         * answer "did that merge actually move the credentials" months later.
         */
        Schema::create('resident_merges', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('survivor_resident_id')->constrained('residents')->restrictOnDelete();
            $table->foreignId('absorbed_resident_id')->constrained('residents')->restrictOnDelete();

            // The pair a reviewer ruled on. Nullable so a merge can be recorded even if the
            // pair row is later cleaned up.
            $table->uuid('duplicate_pair_id')->nullable();

            $table->string('reason', 255);

            $table->unsignedInteger('reassigned_accounts')->default(0);
            $table->unsignedInteger('reassigned_credentials')->default(0);
            $table->unsignedInteger('reassigned_kyc_cases')->default(0);
            $table->unsignedInteger('reassigned_sectors')->default(0);

            $table->uuid('merged_by')->nullable();
            $table->timestampTz('merged_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('survivor_resident_id', 'idx_resident_merges_survivor');
            $table->unique('absorbed_resident_id', 'uniq_resident_merges_absorbed');
        });

        /*
         * A resident asking for their own record to be fixed.
         *
         * The reason this exists at all: a citizen must be able to correct their personal
         * data (RA 10173 §16(d), the right to rectification), but they must not be able to
         * silently rewrite the identity fields the LGU verified — name and birth date are
         * exactly what a fraudulent claim would change. So the citizen proposes, a reviewer
         * with `resident.manage` disposes, and both halves are recorded.
         */
        Schema::create('resident_correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            // The account that asked. Cross-module reference, no FK (Article 2.2).
            $table->uuid('requested_by')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'withdrawn'])
                ->default('pending');

            $table->string('note', 500)->nullable();

            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();

            $table->timestampsTz();

            $table->index(['resident_id', 'status'], 'idx_resident_corrections_resident_status');
            $table->index('status', 'idx_resident_corrections_status');
        });

        /*
         * The individual fields one correction request proposes to change.
         *
         * A child table rather than a JSON blob so that "pending address corrections" is an
         * indexed query and an approval can apply one field while refusing another later.
         */
        Schema::create('resident_correction_fields', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_correction_request_id')
                ->constrained('resident_correction_requests')
                ->cascadeOnDelete();

            $table->string('field', 64);

            // The value at the time of the request, kept so a reviewer can see what the
            // resident was looking at — the record may have moved on since.
            $table->string('current_value', 191)->nullable();
            $table->string('proposed_value', 191)->nullable();

            $table->timestampsTz();

            // One proposal per field per request: two rows for `street_address` in one
            // request is a request with no defined outcome.
            $table->unique(
                ['resident_correction_request_id', 'field'],
                'uniq_resident_correction_fields'
            );
        });

        /*
         * Which accounts may act for which resident, and the history of that.
         *
         * `accounts.resident_id` is the fast current answer Identity keeps for itself. THIS
         * is the reviewable record: who linked them, when, on what authority, and — when a
         * link is withdrawn — that it ever existed. An account quietly repointed at another
         * resident with no trace is the single worst outcome this registry can produce, so
         * the link gets its own history rather than living only as a mutable column.
         *
         * Holding a link still grants nothing on its own: authorization remains a separate
         * decision (ADR 0002).
         */
        Schema::create('account_resident_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            // Identity's account UUID. No FK — cross-module (Article 2.2).
            $table->uuid('account_id');

            // 'kyc-approval' | 'staff-link' | 'merge'. How the link came to be, because
            // "a reviewer typed it in" and "onboarding produced it" carry different weight
            // in a dispute.
            $table->string('origin', 32);

            $table->enum('status', ['active', 'revoked'])->default('active');

            $table->uuid('linked_by')->nullable();
            $table->timestampTz('linked_at');

            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['account_id', 'status'], 'idx_account_resident_links_account');
            $table->index(['resident_id', 'status'], 'idx_account_resident_links_resident');
        });
    }

    public function down(): void
    {
        // Reverse creation order: children before the tables they reference.
        Schema::dropIfExists('account_resident_links');
        Schema::dropIfExists('resident_correction_fields');
        Schema::dropIfExists('resident_correction_requests');
        Schema::dropIfExists('resident_merges');
        Schema::dropIfExists('resident_duplicate_pairs');
        Schema::dropIfExists('resident_aliases');
        Schema::dropIfExists('resident_status_events');
    }
};
