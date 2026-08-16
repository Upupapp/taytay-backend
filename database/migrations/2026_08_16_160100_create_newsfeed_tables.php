<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsfeed publishing (ADR 0028).
 *
 * THE ONLY TABLE IN THIS SYSTEM WHOSE CONTENTS ARE MEANT TO BE READ BY PEOPLE WHO ARE NOT ITS
 * SUBJECT. Everything else here holds records *about* somebody; a newsfeed post holds an
 * announcement *for* everybody.
 *
 * That inverts the usual risk. The danger is not disclosure of the row — publication is the point
 * — it is publishing something **before it was meant to be published**, or to an audience it was
 * not meant for. So the state and the schedule are the load-bearing columns, and both are read at
 * the query rather than checked after it: a draft is absent from a public listing, not filtered
 * out of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsfeed_posts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Optional: a short advisory often reads better with no headline at all, and forcing
            // one produces "Announcement" fourteen times in a feed.
            $table->string('headline', 200)->nullable();
            $table->text('body');

            $table->string('category', 48);

            // Identity account UUID. No FK — the author may leave the office and the post stays.
            $table->uuid('author_subject_id')->nullable();

            /*
             * ── audience ──────────────────────────────────────────────────────────────────
             *
             * `municipality` reaches everybody; `barangay` reaches one.
             *
             * A targeted post is not a secret — anybody who can see the feed at all could be
             * shown it by a friend — but sending a barangay-specific relief notice to the whole
             * municipality produces a queue of people at a distribution they are not on the list
             * for, which is a real harm to real families.
             */
            $table->enum('audience', ['municipality', 'barangay'])->default('municipality');
            $table->foreignId('audience_barangay_id')->nullable()->constrained('barangays')->nullOnDelete();

            $table->boolean('comments_enabled')->default(true);

            $table->string('status', 16)->default('draft');

            /*
             * When it becomes visible. Null on a draft.
             *
             * The publish transition is a conditional UPDATE on `status = scheduled AND publish_at
             * <= now`, which is atomic — that is how "a scheduled post publishes at most once"
             * is held without a lock (ADR 0028 §3).
             */
            $table->timestampTz('publish_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();

            // Pinned posts sort first. A flag rather than a position, because an ordered pin list
            // is a thing somebody has to maintain and nobody does.
            $table->boolean('is_pinned')->default(false);

            $table->timestampsTz();

            /*
             * The index the public feed actually uses: status, then the schedule, then the sort.
             * A feed is the most-hit endpoint in the system and the one anonymous callers may
             * reach, so it gets the composite it needs rather than three separate indexes.
             */
            $table->index(['status', 'publish_at', 'is_pinned'], 'idx_newsfeed_public');
            $table->index(['audience', 'audience_barangay_id'], 'idx_newsfeed_audience');
        });

        Schema::create('newsfeed_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('newsfeed_post_id')->constrained('newsfeed_posts')->cascadeOnDelete();

            // Files module UUID. No FK — cross-module (Article 2.2).
            $table->uuid('stored_file_id');

            /*
             * ALT TEXT IS REQUIRED, and this column is not nullable.
             *
             * The master command asks for it on meaningful images, and making it optional means
             * it is omitted — a published municipal announcement that a blind resident cannot
             * read is a service the LGU is not providing to somebody entitled to it.
             *
             * A decorative image with genuinely nothing to say is handled by an explicit
             * `is_decorative` flag rather than an empty string, so "nobody wrote one" and "there
             * is nothing to write" stay distinguishable.
             */
            $table->string('alt_text', 255);
            $table->boolean('is_decorative')->default(false);

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();

            $table->unique(['newsfeed_post_id', 'stored_file_id'], 'uniq_newsfeed_media');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsfeed_media');
        Schema::dropIfExists('newsfeed_posts');
    }
};
