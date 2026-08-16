<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files, documents and their verification (ADR 0020).
 *
 * FOUR TABLES, FOUR DIFFERENT LIFETIMES — which is why they are not one:
 *
 *  * `stored_files` — bytes on the private disk. Purgeable on a retention schedule.
 *  * `documents` — the thing a requirement asks for. Lives as long as its owner does.
 *  * `document_versions` — **append-only**. Every version ever presented, including the ones
 *    replaced. This is the table the acceptance criterion is about.
 *  * `file_access_grants` — one permission to read one file once. Minutes.
 *
 * Collapsing versions into the document would be the single mistake that matters here. The
 * superseded version is the evidence of what the office actually saw when it decided: a request
 * approved in March on the strength of a certificate replaced in June must still be explicable
 * in December, and an overwriting model makes that permanently unanswerable.
 *
 * NO BYTES IN THE DATABASE. Files live on the private `object-storage` disk (Article 8.5) and
 * reach a reader through an authorization-gated stream. Putting scans in a column would put them
 * in every backup, replica and dump, and would make retention deletion a `VACUUM` problem.
 *
 * NO FOREIGN KEY TO THE OWNING RECORD. `owner_type`/`owner_id` are an identifier pair resolved
 * by the owning module, because a document may belong to a welfare case requirement today and a
 * service application tomorrow, and a real FK would force this low-level module to know about
 * every module above it (Article 2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * WHERE THE BYTES ARE. The disk name is stored alongside the key because the answer
             * changes between environments — local development writes to `local`, everything
             * else to `object-storage` — and a path with no disk is only resolvable by whoever
             * remembers which environment wrote it.
             */
            $table->string('disk', 32);
            $table->string('storage_key', 255);

            /*
             * TWO FILENAMES, ON PURPOSE.
             *
             * `original_name` is what the caller called it, kept only to show back to the person
             * who uploaded it so they can tell two picks apart. It is **never** used to build a
             * path, choose a type or set a header without re-encoding: a caller-chosen filename
             * is caller-chosen path input, and that is how an upload becomes a write somewhere
             * it should not reach.
             *
             * `storage_key` is generated here from a UUID and the extension of the *verified*
             * type. Nothing the caller sent contributes a single character to it.
             */
            $table->string('original_name', 255);

            /*
             * The VERIFIED type — read from the file's own leading bytes, never the declared
             * `Content-Type` or the extension, both of which the caller supplies.
             */
            $table->string('mime_type', 96);
            $table->unsignedInteger('byte_size');

            /*
             * SHA-256 of the contents. Three jobs: detecting that a "replacement" is the same
             * file again, proving at any later date that the object served is the object stored,
             * and giving retention deletion something to verify against.
             */
            $table->string('content_hash', 64);

            $table->enum('classification', [
                'public-reference', 'operational', 'personal', 'sensitive',
            ]);

            // Malware scanning: pending is NOT clean. See ScanStatus for why this is not a
            // boolean and what each state permits.
            $table->enum('scan_status', ['pending', 'clean', 'infected', 'skipped'])->default('pending');
            $table->string('scan_detail', 255)->nullable();
            $table->timestampTz('scanned_at')->nullable();

            // Set for images once the metadata job has read them; null for a PDF and for
            // anything not yet processed.
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();

            // Identity's account UUID. No FK — the uploader may be deactivated later and the
            // evidence of who uploaded must outlive the account.
            $table->uuid('uploaded_by')->nullable();

            /*
             * Set when the object is purged under retention but the row is kept. The record that
             * a file existed, what it was and who supplied it is itself evidence, and deleting
             * the row would erase the fact that anything was ever provided.
             */
            $table->timestampTz('purged_at')->nullable();

            $table->timestampsTz();

            $table->unique(['disk', 'storage_key'], 'uniq_stored_files_location');
            $table->index('content_hash', 'idx_stored_files_hash');
            $table->index(['scan_status', 'created_at'], 'idx_stored_files_scan_queue');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * The owning record, by identifier only.
             *
             * `owner_type` is a short vocabulary owned by this module ('welfare.case-requirement'
             * today), and `owner_id` is that record's UUID. The owning module resolves it and
             * decides who may read — this module stores and never authorises, because only the
             * owner knows whether a given caller may see a given case.
             */
            $table->string('owner_type', 48);
            $table->uuid('owner_id');

            // What kind of document this slot holds — 'barangay-certificate', 'philsys-id'.
            // Open vocabulary: the list is programme policy and changes without a deploy.
            $table->string('document_type', 48);

            $table->timestampsTz();

            /*
             * One document per slot. A requirement asking for a barangay certificate has one
             * document with many versions, not many documents — otherwise "which one is current"
             * has no answer and both a reviewer and an auditor have to guess.
             */
            $table->unique(['owner_type', 'owner_id', 'document_type'], 'uniq_documents_slot');
            $table->index(['owner_type', 'owner_id'], 'idx_documents_owner');
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();

            // 1-based and never reused. The first version is 1 whatever happens afterwards.
            $table->unsignedSmallInteger('version');

            /*
             * Null for `encoded` and `external-verification`, which hold no file by design — the
             * office confirmed the document without keeping a copy, and inventing an empty file
             * would claim evidence it does not have.
             */
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->nullOnDelete();

            $table->enum('source', ['uploaded', 'scanned', 'encoded', 'external-verification']);

            /*
             * THE LAST FOUR CHARACTERS ONLY, and only for sources holding no file.
             *
             * Masked before storage rather than before display, so the full number is never in a
             * backup, replica, dump or query log, and no future endpoint can leak what was never
             * kept (ADR 0020 §4). Where there IS a file, the image is the record and storing the
             * number as well would be a second copy of a government identifier for no gain.
             */
            $table->string('document_number_last4', 8)->nullable();

            $table->date('issued_on')->nullable();

            /*
             * Null means one of two different things, and the pair `expires_on` +
             * `expiry_unknown` keeps them apart: a document that genuinely never expires, and a
             * document whose expiry nobody wrote down. Only the second is somebody's unfinished
             * work, and collapsing both to "fine" loses that.
             */
            $table->date('expires_on')->nullable();
            $table->boolean('expiry_unknown')->default(false);

            // Human verification. Never conflated with the malware scan on the file.
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('verification_note', 255)->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at');

            /*
             * Set when a later version replaced this one. NEVER unset, and the reason is required
             * on replacement — an unexplained supersession leaves a version nobody can account
             * for, which is worse than no history because it looks like one.
             */
            $table->timestampTz('superseded_at')->nullable();
            $table->string('superseded_reason', 255)->nullable();

            /*
             * APPEND-ONLY: no `updated_at`, and no code path in this module updates a row except
             * to stamp supersession and verification. Enforced by test.
             */
            $table->timestampTz('created_at')->nullable();

            $table->unique(['document_id', 'version'], 'uniq_document_versions_number');
            $table->index(['document_id', 'superseded_at'], 'idx_document_versions_current');
        });

        Schema::create('file_access_grants', function (Blueprint $table): void {
            $table->id();

            /*
             * THE HANDLE. Exchanged for bytes exactly once, within minutes.
             *
             * A grant rather than a signed URL: a signature is valid wherever it is pasted, for
             * as long as it lasts, to whoever holds it, and nothing records that it was used. A
             * row can be single-use, revoked, and — the reason it matters here — is the anchor
             * for the audit entry that says this person read this document at this time
             * (Article 5.4).
             */
            $table->uuid()->unique();

            $table->foreignId('stored_file_id')->constrained('stored_files')->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->cascadeOnDelete();

            // Identity's account UUID. The grant is issued TO somebody; presenting it as anybody
            // else is refused even while it is unexpired.
            $table->uuid('issued_to');

            // Why it was issued — 'view', 'download', 'preview', 'share'. Recorded because
            // "opened it to check a date" and "took a copy out of the office" are different acts.
            $table->string('purpose', 24);

            /*
             * Whether this copy is marked as leaving the office. The seam for the
             * redaction-ready preview the master command asks for and the console's
             * `redactedForSharing` flag expects.
             */
            $table->boolean('redacted_for_sharing')->default(false);

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['issued_to', 'expires_at'], 'idx_file_access_grants_holder');
            $table->index('expires_at', 'idx_file_access_grants_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_grants');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('stored_files');
    }
};
