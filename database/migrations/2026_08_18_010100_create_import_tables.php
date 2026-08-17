<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging tables for a legacy import (ADR 0040 §3).
 *
 * **NOTHING HERE KNOWS WHAT A LEGACY COLUMN IS CALLED**, and that is the instruction: the master
 * command says to build the framework and explicitly *not* to write one-off migration code against
 * imaginary legacy columns. Taytay has not supplied an export, so any mapping written now would be
 * a guess that somebody later has to recognise as a guess.
 *
 * So a staged row holds its source columns as an opaque payload, and the mapping — when there is a
 * real file to map — is a small class implementing one interface. The table does not change.
 *
 * ── WHY STAGE AT ALL ──────────────────────────────────────────────────────────────────
 *
 * The alternative is a script that reads a CSV and writes residents. It works on the sample file
 * and fails on row 4,812 of the real one, having already written 4,811 residents that nobody can
 * now distinguish from the ones that were there before.
 *
 * Staging separates *reading* from *committing*. Every row is landed, validated and reported on
 * before anything reaches the registry — so a dry run answers "what would this do" honestly, and a
 * bad file is rejected while it is still a file rather than after it is a registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * What this batch is trying to import — `resident`, `household`, whatever the LGU
             * eventually sends. A string rather than an enum, because the vocabulary belongs to a
             * file that does not exist yet and inventing the closed list now would be the guess
             * this design avoids.
             */
            $table->string('target', 48);

            /*
             * PROVENANCE. Where these rows came from, in enough detail that somebody looking at a
             * resident in five years can answer "how did this record get here" — which is the
             * question asked when the record turns out to be wrong.
             */
            $table->string('source_label', 160);
            $table->string('source_filename', 255)->nullable();
            $table->string('source_checksum', 64)->nullable();

            /*
             * received | validating | validated | importing | completed | failed | cancelled
             *
             * `validated` is the resting state after a dry run. A batch sits there indefinitely
             * until somebody decides to commit it, because the decision to write to a resident
             * registry should be a decision rather than the second half of an upload.
             */
            $table->string('status', 24)->default('received');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);

            $table->uuid('requested_by')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestampsTz();

            $table->index(['target', 'status'], 'idx_import_batches_target_status');
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();

            // The line in the source file, so a rejection report names something the operator can
            // find in the spreadsheet in front of them.
            $table->unsignedInteger('source_line');

            /*
             * THE SOURCE ROW, VERBATIM AND OPAQUE.
             *
             * JSON, and on the ADR 0008 §13 allow-list for the same reason `idempotency_keys`
             * holds a response body: it is evidence of what arrived, never filtered, joined or
             * authorized on. Storing it typed would require knowing the legacy schema, which is
             * exactly what this design refuses to guess.
             */
            $table->json('source_payload');

            /*
             * pending | valid | rejected | duplicate | imported
             *
             * `duplicate` is separate from `rejected` because they need different answers: a
             * rejected row is bad data to fix, a duplicate is a record the system already has and
             * the right response is usually to skip it rather than to correct the file.
             */
            $table->string('status', 16)->default('pending');

            /*
             * Why it was rejected, in words an operator can act on. Plural because one row can
             * fail several rules and reporting only the first means three round trips.
             */
            $table->string('rejection_reasons', 1000)->nullable();

            /*
             * THE IDEMPOTENT IMPORT KEY.
             *
             * A stable hash of the source identity, so re-running a batch — or importing an
             * overlapping later export from the same system — recognises a row it has already
             * seen. Without it, the natural response to a half-failed import is to run it again,
             * and that is how one household becomes three.
             */
            $table->string('import_key', 64)->nullable();

            // What it became, once committed. The link that answers "where did this row go".
            $table->uuid('created_entity_id')->nullable();

            $table->timestampsTz();

            /*
             * At most one row per import key per target. Enforced at the database rather than in
             * the importer, because the importer is the thing most likely to be re-run by somebody
             * who is not sure whether the first run finished.
             */
            $table->unique(['import_batch_id', 'source_line'], 'uniq_import_rows_line');
            $table->index(['import_batch_id', 'status'], 'idx_import_rows_status');
            $table->index('import_key', 'idx_import_rows_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
