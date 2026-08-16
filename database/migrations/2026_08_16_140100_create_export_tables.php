<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report exports (ADR 0026).
 *
 * AN EXPORT IS A COPY OF THE DATABASE THAT LEAVES THE APPLICATION'S CONTROL. Once a CSV of every
 * beneficiary in a barangay is on somebody's laptop, none of this system's authorization applies
 * to it any more — no scope, no audit, no revocation.
 *
 * So the row records more than a file. It records **who asked, what they asked for, what they were
 * allowed to see at the moment they asked, and when the copy expires** — because "why does this
 * spreadsheet exist and who made it" is the question asked after it turns up somewhere it should
 * not be, and by then the requester's permissions may have changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('report', 64);
            $table->string('format', 8);

            /*
             * The filters, kept verbatim.
             *
             * One of the three permitted JSON uses under ADR 0008 §13: an opaque record of what
             * was asked, never filtered or joined on. Reconstructing "which barangays did this
             * export cover" from separate columns would mean a column per filter and a migration
             * every time a report gains one.
             */
            $table->json('filters');

            /*
             * WHAT THE REQUESTER WAS ALLOWED TO SEE AT THE MOMENT THEY ASKED.
             *
             * Snapshotted because permissions change. A person-level export produced last March
             * by somebody who then moved offices must still be explicable, and "they had
             * `report.export.person-level` and a scope of Barangay Dolores on 14 March" is the
             * only honest answer once their current permissions are different.
             */
            $table->json('permission_context');

            $table->uuid('requested_by');
            $table->timestampTz('requested_at');

            $table->enum('status', ['queued', 'running', 'ready', 'failed', 'expired'])->default('queued');

            /*
             * Whether the file contains rows about named individuals.
             *
             * Set from the report definition rather than inspected afterwards, and it decides the
             * permission required to produce it AND the retention. An aggregate export is a
             * statistic; a person-level one is a copy of a caseload.
             */
            $table->boolean('is_person_level')->default(false);

            $table->unsignedInteger('row_count')->nullable();

            // Files module UUID. No FK — cross-module (Article 2.2). Null until the job finishes.
            $table->uuid('stored_file_id')->nullable();

            /*
             * Exports expire, and short.
             *
             * A download link that works forever is a permanent copy of a caseload sitting behind
             * a URL somebody bookmarked. The file is purged at expiry; the ROW survives, because
             * the record that an export happened is the part an audit needs.
             */
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('purged_at')->nullable();

            $table->string('failure_reason', 255)->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['requested_by', 'status'], 'idx_exports_requester');
            $table->index(['status', 'expires_at'], 'idx_exports_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
