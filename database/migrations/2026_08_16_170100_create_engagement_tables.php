<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsfeed engagement and moderation (ADR 0029).
 *
 * WHAT IS DELIBERATELY ABSENT FROM `newsfeed_shares`, AND ENFORCED BY TEST:
 *
 * no destination, no platform, no recipient, no phone number, no email, no contact list, no
 * message body. Not one column that records **who somebody shared something with**.
 *
 * The master command forbids tracking external destinations or personal contacts. That is easy to
 * refuse as a feature and easy to acquire as a column — "which platform do people share to?" is a
 * reasonable product question, and answering it turns a municipal welfare system into a record of
 * who talks to whom. A share row here is a counter with a timestamp, and
 * `NoShareRecipientDataTest` fails the build if it becomes anything else.
 *
 * THIS IS NOT A SOCIAL NETWORK. There are no follows, no friend graph, no profiles and no
 * mentions. A resident reacts to an LGU announcement and may comment on it; nothing here models
 * relationships between residents, because the moment it does, the LGU is operating one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsfeed_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsfeed_post_id')->constrained('newsfeed_posts')->cascadeOnDelete();

            // Identity account UUID. No FK — cross-module (Article 2.2).
            $table->uuid('subject_id');

            $table->string('reaction', 24)->default('like');

            $table->timestampsTz();

            /*
             * ONE ACTIVE REACTION PER PERSON PER POST.
             *
             * Changing a reaction updates this row; removing it deletes the row. A history of
             * somebody's changing feelings about a municipal announcement is not a record the LGU
             * needs, and keeping it would make "who disliked the mayor's post in March" answerable.
             */
            $table->unique(['newsfeed_post_id', 'subject_id'], 'uniq_reaction_per_person');
            $table->index(['newsfeed_post_id', 'reaction'], 'idx_reaction_counts');
        });

        Schema::create('newsfeed_comments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('newsfeed_post_id')->constrained('newsfeed_posts')->cascadeOnDelete();

            /*
             * One level of reply, and no deeper.
             *
             * A thread that nests arbitrarily is a thread somebody has to moderate arbitrarily
             * deep, and on a municipal announcement the useful shape is a comment and the office's
             * answer to it.
             */
            $table->foreignId('parent_id')->nullable()->constrained('newsfeed_comments')->cascadeOnDelete();

            $table->uuid('author_subject_id');

            /*
             * Whether this is the LGU speaking.
             *
             * Set by the server from the author's permission, never accepted from a request. A
             * resident able to post a comment marked official could impersonate the municipality
             * on its own feed — which is a more effective lie than most, because it appears under
             * the LGU's own announcement.
             */
            $table->boolean('is_official')->default(false);

            $table->string('body', 2000);

            /*
             * ── moderation ────────────────────────────────────────────────────────────────
             *
             * `deleted` is a STATE, not a missing row. A comment removed for abuse must stay
             * readable by a moderator: "what did it say, who wrote it, who removed it and why" is
             * the question asked when the author complains, and a hard delete makes every answer
             * "we do not know".
             *
             * `review-needed` exists so a future moderation provider has a state to write into
             * without inventing one. Nothing sets it today (ADR 0029 §5).
             */
            $table->enum('moderation_state', ['visible', 'hidden', 'deleted', 'review-needed'])->default('visible');
            $table->uuid('moderated_by')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->string('moderation_reason', 255)->nullable();

            $table->timestampTz('edited_at')->nullable();

            $table->timestampsTz();

            // The index the citizen thread actually uses: post, then state, then order.
            $table->index(['newsfeed_post_id', 'moderation_state', 'created_at'], 'idx_comments_thread');
            $table->index(['author_subject_id'], 'idx_comments_author');
        });

        /*
         * ── shares ────────────────────────────────────────────────────────────────────────
         *
         * A COUNTER. That is the whole table.
         *
         * `subject_id` is nullable because an anonymous reader may share a public post, and the
         * row exists so the office can see that an advisory travelled — not who carried it.
         */
        Schema::create('newsfeed_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsfeed_post_id')->constrained('newsfeed_posts')->cascadeOnDelete();

            $table->uuid('subject_id')->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['newsfeed_post_id', 'created_at'], 'idx_share_counts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsfeed_shares');
        Schema::dropIfExists('newsfeed_comments');
        Schema::dropIfExists('newsfeed_reactions');
    }
};
