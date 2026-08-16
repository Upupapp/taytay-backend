<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Illuminate\Database\Eloquent\Builder;
use Modules\Content\Domain\ModerationState;
use Modules\Content\Infrastructure\Eloquent\NewsfeedComment;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Content\Infrastructure\Eloquent\NewsfeedReaction;
use Modules\Content\Infrastructure\Eloquent\NewsfeedShare;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Resident engagement with published announcements (ADR 0029).
 *
 * EVERY WRITE HERE IS BOUND TO THE AUTHENTICATED SUBJECT AND TO A LIVE POST. Those are the two
 * gates, and both are applied at the query rather than checked afterwards:
 *
 *  * the actor's own id comes from the token, so "modify only your own engagement" has no
 *    identifier to tamper with;
 *  * the post is loaded through `NewsfeedService::publicQuery()`, so a draft cannot accumulate
 *    reactions before it is published — which would then appear on the feed the moment it went
 *    live, with engagement nobody could account for.
 */
final class EngagementService
{
    /** A comment may be edited by its author for this long, and not after. */
    public const EDIT_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly NewsfeedService $newsfeed,
        private readonly ContentAudit $audit,
    ) {}

    // ── reactions ─────────────────────────────────────────────────────────────────────

    /**
     * Sets or changes this person's reaction. Idempotent.
     */
    public function react(NewsfeedPost $post, string $reaction, ActorContext $actor): void
    {
        $this->assertLive($post);

        /*
         * Upsert on the unique key. Changing a reaction updates the row; there is no history of
         * somebody's changing feelings about a municipal announcement, because "who disliked the
         * mayor's post in March" is not a record this office needs to be able to produce.
         */
        NewsfeedReaction::query()->updateOrCreate(
            ['newsfeed_post_id' => $post->id, 'subject_id' => (string) $actor->subjectId],
            ['reaction' => $reaction],
        );
    }

    public function unreact(NewsfeedPost $post, ActorContext $actor): void
    {
        // Scoped to the caller's own row. There is no path here that touches anybody else's.
        NewsfeedReaction::query()
            ->where('newsfeed_post_id', $post->id)
            ->where('subject_id', (string) $actor->subjectId)
            ->delete();
    }

    // ── comments ──────────────────────────────────────────────────────────────────────

    public function comment(
        NewsfeedPost $post,
        string $body,
        ?string $parentUuid,
        ActorContext $actor,
        bool $asOfficial = false,
    ): NewsfeedComment {
        $this->assertLive($post);

        if (! $post->comments_enabled) {
            throw new ApiException(ErrorCode::Conflict, 'Comments are closed on this post.');
        }

        $body = trim($body);

        if ($body === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'A comment needs something in it.');
        }

        $parent = null;

        if ($parentUuid !== null) {
            /** @var NewsfeedComment|null $parent */
            $parent = NewsfeedComment::query()
                ->where('uuid', $parentUuid)
                ->where('newsfeed_post_id', $post->id)
                ->first();

            if ($parent === null) {
                throw new ApiException(ErrorCode::NotFound, 'That comment was not found.');
            }

            /*
             * One level of reply, and no deeper. A thread that nests arbitrarily is a thread
             * somebody has to moderate arbitrarily deep, and on a municipal announcement the
             * useful shape is a comment and the office's answer to it.
             */
            if ($parent->parent_id !== null) {
                throw new ApiException(ErrorCode::Conflict, 'A reply cannot be replied to.');
            }
        }

        /** @var NewsfeedComment $comment */
        $comment = NewsfeedComment::query()->create([
            'newsfeed_post_id' => $post->id,
            'parent_id' => $parent?->id,
            'author_subject_id' => (string) $actor->subjectId,
            /*
             * Set by the SERVER from the author's permission, never accepted from the request. A
             * resident able to post a comment marked official could impersonate the municipality
             * on its own feed — a more effective lie than most, because it appears directly under
             * the LGU's own announcement.
             */
            'is_official' => $asOfficial,
            'body' => $body,
            'moderation_state' => ModerationState::Visible,
        ]);

        return $comment;
    }

    /**
     * The author corrects their own comment, within the window.
     */
    public function editOwnComment(NewsfeedComment $comment, string $body, ActorContext $actor): NewsfeedComment
    {
        if ((string) $comment->author_subject_id !== (string) $actor->subjectId) {
            // NOT FOUND rather than FORBIDDEN would be wrong here: the comment is public, so its
            // existence is not a secret. Forbidden is the honest answer.
            throw new ApiException(ErrorCode::Forbidden, 'That comment is not yours.');
        }

        if (! $comment->moderation_state->isAuthorEditable()) {
            throw new ApiException(ErrorCode::Conflict, 'That comment can no longer be edited.');
        }

        /*
         * A bounded window, not "any time".
         *
         * An unbounded edit lets somebody write something agreeable, collect replies, and rewrite
         * it into something else — leaving a thread of people apparently agreeing with a statement
         * nobody saw. Fifteen minutes covers a typo and not that.
         */
        if ($comment->created_at !== null && $comment->created_at->diffInMinutes(now()) > self::EDIT_WINDOW_MINUTES) {
            throw new ApiException(
                ErrorCode::Conflict,
                'A comment can only be edited for a short time after posting.',
            );
        }

        $comment->forceFill(['body' => trim($body), 'edited_at' => now()])->save();

        return $comment->refresh();
    }

    /**
     * The author withdraws their own comment.
     *
     * A STATE, not a missing row — the same reason a moderator's removal is. The author asked for
     * it to stop being visible, not for the office to lose the record that it existed.
     */
    public function deleteOwnComment(NewsfeedComment $comment, ActorContext $actor): NewsfeedComment
    {
        if ((string) $comment->author_subject_id !== (string) $actor->subjectId) {
            throw new ApiException(ErrorCode::Forbidden, 'That comment is not yours.');
        }

        $comment->forceFill([
            'moderation_state' => ModerationState::Deleted,
            'moderated_by' => $actor->subjectId,
            'moderated_at' => now(),
            'moderation_reason' => 'Withdrawn by the author.',
        ])->save();

        return $comment->refresh();
    }

    // ── moderation ────────────────────────────────────────────────────────────────────

    public function moderate(
        NewsfeedComment $comment,
        ModerationState $state,
        ?string $reason,
        ActorContext $actor,
    ): NewsfeedComment {
        /*
         * A moderation decision must say why.
         *
         * A comment that disappears with no recorded reason is indistinguishable from censorship
         * to the person who wrote it, and from a mistake to the colleague who finds it later.
         */
        if ($state !== ModerationState::Visible && trim((string) $reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Record why this comment is being moderated.');
        }

        $comment->forceFill([
            'moderation_state' => $state,
            'moderated_by' => $actor->subjectId,
            'moderated_at' => now(),
            'moderation_reason' => $reason,
        ])->save();

        $this->audit->record(
            $actor->subjectId,
            'newsfeed.comment-'.$state->value,
            'Comment moderated: '.$state->value,
            (string) $comment->uuid,
        );

        return $comment->refresh();
    }

    // ── shares ────────────────────────────────────────────────────────────────────────

    /**
     * Records that a post was shared. A counter, and nothing else.
     *
     * NO DESTINATION IS ACCEPTED OR STORED. The master command forbids tracking external
     * destinations or personal contacts, and there is deliberately no parameter here for one —
     * "which platform do people share to?" is a reasonable product question whose answer turns a
     * municipal welfare system into a record of who talks to whom.
     */
    public function share(NewsfeedPost $post, ActorContext $actor): void
    {
        $this->assertLive($post);

        NewsfeedShare::query()->create([
            'newsfeed_post_id' => $post->id,
            // Null for an anonymous reader. The row says an advisory travelled, not who carried it.
            'subject_id' => $actor->subjectId,
            'created_at' => now(),
        ]);
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────

    /**
     * Aggregate counts for a post.
     *
     * @return array<string, mixed>
     */
    public function engagementFor(NewsfeedPost $post, ActorContext $actor): array
    {
        $reactions = NewsfeedReaction::query()
            ->where('newsfeed_post_id', $post->id)
            ->selectRaw('reaction, COUNT(*) as total')
            ->groupBy('reaction')
            ->pluck('total', 'reaction')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'reactions' => $reactions,
            'reaction_total' => array_sum($reactions),
            // Only what a reader can actually see. A count that included hidden comments would
            // tell everybody how much was removed, which is a moderation log by arithmetic.
            'comment_count' => $this->visibleComments($post)->count(),
            'share_count' => NewsfeedShare::query()->where('newsfeed_post_id', $post->id)->count(),
            // So a client can render the reader's own state without a second request.
            'my_reaction' => $actor->subjectId === null ? null : NewsfeedReaction::query()
                ->where('newsfeed_post_id', $post->id)
                ->where('subject_id', $actor->subjectId)
                ->value('reaction'),
        ];
    }

    /**
     * The thread as a reader sees it.
     *
     * THE ACCEPTANCE CRITERION — hidden and deleted state respected across all citizen feeds — is
     * held by this being the only query a citizen surface uses, and by it narrowing on state at
     * the query rather than filtering afterwards.
     *
     * @return Builder<NewsfeedComment>
     */
    public function visibleComments(NewsfeedPost $post): Builder
    {
        return NewsfeedComment::query()
            ->where('newsfeed_post_id', $post->id)
            ->where('moderation_state', ModerationState::Visible->value)
            // Official replies first within the same parent, because the office's answer is what
            // most readers came for.
            ->orderBy('parent_id')
            ->orderByDesc('is_official')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * The moderator's view: everything, whatever its state.
     *
     * @return Builder<NewsfeedComment>
     */
    public function allComments(): Builder
    {
        return NewsfeedComment::query()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * A live post, or nothing.
     *
     * Engagement on a draft would accumulate before publication and then appear the moment it went
     * live, with counts nobody could account for.
     */
    private function assertLive(NewsfeedPost $post): void
    {
        if (! $post->isLive()) {
            throw new ApiException(ErrorCode::Conflict, 'That post is not published.');
        }
    }
}
