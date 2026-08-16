<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived renditions of a stored file (ADR 0033).
 *
 * A VARIANT IS NEVER THE ORIGINAL. Every row here points at bytes this system **re-encoded** from
 * a decoded pixel buffer — never a copy, never a trimmed original. That distinction is the whole
 * of the EXIF guarantee: stripping metadata is a step somebody can miss or a format can outgrow,
 * whereas re-encoding from pixels has no metadata to carry because none survives the decode.
 *
 * A PUBLIC VARIANT EXISTS ONLY AFTER PUBLICATION. There is no row on this table with
 * `visibility = 'public'` until the module owning the content says the content is published, and
 * withdrawing publication deletes both the row and the object. The original never moves and never
 * touches the public bucket, not even briefly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Same module, so a real foreign key. A variant of a deleted file is meaningless.
            $table->foreignId('stored_file_id')->constrained('stored_files')->cascadeOnDelete();

            // thumbnail | web
            $table->string('variant', 24);

            /*
             * private | public
             *
             * Recorded on the row rather than inferred from the disk name, so "is this object
             * publicly reachable" is answerable by a query rather than by knowing which disk maps
             * to which bucket. `NoSensitiveMediaInPublicBucketTest` reads this column.
             */
            $table->string('visibility', 16);

            $table->string('disk', 32);
            $table->string('storage_key', 255);

            $table->string('mime_type', 96);
            $table->unsignedInteger('byte_size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // Of the DERIVED bytes, not the original. Two derivations of the same source at the
            // same settings produce the same hash, which is how a re-run is recognised as a no-op.
            $table->string('content_hash', 64);

            $table->timestampTz('generated_at');
            $table->timestampsTz();

            /*
             * One rendition per file per variant per visibility. A public thumbnail and a private
             * thumbnail are different objects with different consequences, and both may exist.
             */
            $table->unique(['stored_file_id', 'variant', 'visibility'], 'uniq_media_variants_rendition');
            $table->index(['visibility', 'generated_at'], 'idx_media_variants_visibility');
        });

        Schema::table('stored_files', function (Blueprint $table): void {
            /*
             * The file this one duplicates, if the same bytes were already uploaded.
             *
             * NOT A REFUSAL. The master command asks for checksum detection of *accidental*
             * duplicates, and refusing the second upload would be wrong: a resident who
             * re-uploads the same certificate against a second requirement is doing something
             * legitimate, and a household sharing one scanned barangay clearance is normal. What
             * the office wants is to be *told*, so a console can say "this is the same file you
             * sent on Tuesday" rather than silently accumulating identical objects.
             */
            $table->uuid('duplicate_of_file_id')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropColumn('duplicate_of_file_id');
        });

        Schema::dropIfExists('media_variants');
    }
};
