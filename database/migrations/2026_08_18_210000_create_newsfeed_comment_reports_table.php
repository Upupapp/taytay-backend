<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A resident telling the municipality that a comment should not be there (F26).
 *
 * ---
 *
 * **Both app stores require a reporting path for user-generated content**, and the newsfeed's
 * comments are the only user-generated content this platform has. The moderation surface that
 * existed was `admin/newsfeed-comments/{comment}/moderation` — staff only, and a resident calling
 * it would be acting on somebody else's comment.
 *
 * **A REPORT FLAGS. IT DOES NOT HIDE.** Nothing here removes a comment from public view. A report
 * moves it to `review-needed` so it appears in the moderation queue, and a person decides. The
 * alternative — hide on report, or hide after N reports — hands any resident, or any three
 * residents who agree, the power to silence a neighbour on the municipality's own feed. That is
 * a moderation policy the LGU never adopted, implemented by an absent threshold.
 *
 * **NO FREE TEXT, ANYWHERE.** The reason is a controlled vocabulary. A text box here is where a
 * resident writes "this is my neighbour Juan at 12 Mabini and he is lying" — personal data about a
 * third party, entered by somebody with no obligation to be accurate, into a municipal record that
 * staff read and retention rules keep. The moderator has the comment itself in front of them; the
 * category tells them what to look for, which is all it needs to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsfeed_comment_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('newsfeed_comment_id')->constrained('newsfeed_comments')->cascadeOnDelete();

            // Identity account UUID. No FK — cross-module (Article 2.2).
            $table->uuid('reporter_subject_id');

            $table->enum('reason', ['abusive', 'harassment', 'false-information', 'spam', 'personal-information']);

            /*
             * APPEND-ONLY BY SHAPE. `created_at` and nothing else.
             *
             * A report is a statement somebody made at a moment. There is no state to advance —
             * the outcome lives on the comment, where the moderator's decision, reason and
             * identity already are — and no `updated_at`, because a report that can be edited is
             * a record of what somebody now says they said.
             */
            $table->timestampTz('created_at')->nullable();

            /*
             * ONE REPORT PER PERSON PER COMMENT.
             *
             * Reporting twice is not two reports. Without this, a resident tapping a button on a
             * slow connection puts several identical items in front of a human, and a count of
             * reports — if anybody ever builds one — becomes a measure of connection quality
             * rather than of how many people objected.
             */
            $table->unique(['newsfeed_comment_id', 'reporter_subject_id'], 'uniq_report_per_person');

            // The queue's question: what has been reported, most recent first.
            $table->index(['created_at'], 'idx_reports_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsfeed_comment_reports');
    }
};
