<?php

declare(strict_types=1);

namespace Modules\Content\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Content\Application\EngagementService;
use Modules\Content\Application\NewsfeedService;
use Modules\Content\Domain\ModerationState;
use Modules\Content\Infrastructure\Eloquent\NewsfeedComment;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Reactions, comments, shares and moderation (ADR 0029).
 *
 * EVERY CITIZEN WRITE IS BOUND TO THE TOKEN'S SUBJECT AND TO A LIVE POST. There is no field
 * anywhere that names an author, a reactor or a sharer — which is how "a citizen can modify only
 * their own engagement" is held: there is nothing to tamper with.
 *
 * TWO COMMENT PROJECTIONS. A reader sees the body and whether the office wrote it. A moderator
 * additionally sees the state, the reason and who decided — and sees hidden and deleted comments
 * at all, which a reader never does.
 */
final class EngagementController
{
    public function __construct(
        private readonly EngagementService $engagement,
        private readonly NewsfeedService $newsfeed,
        private readonly AuthorizationService $authorization,
    ) {}

    // ── the reader ────────────────────────────────────────────────────────────────────

    public function react(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->livePostOrFail($actor, $post);

        $validated = $request->validate([
            'reaction' => ['sometimes', 'string', 'in:like,helpful,concerned'],
        ]);

        $this->engagement->react($model, $validated['reaction'] ?? 'like', $actor);

        return ApiResponse::item($this->engagement->engagementFor($model, $actor));
    }

    public function unreact(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->livePostOrFail($actor, $post);

        $this->engagement->unreact($model, $actor);

        return ApiResponse::item($this->engagement->engagementFor($model, $actor));
    }

    public function comments(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->livePostOrFail($actor, $post);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->engagement->visibleComments($model);

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (NewsfeedComment $comment): array => $this->readerProjection($comment),
        );
    }

    public function comment(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->livePostOrFail($actor, $post);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /*
         * `is_official` is decided HERE, from the author's permission — never accepted from the
         * request. A resident able to post a comment marked official could impersonate the
         * municipality directly under its own announcement.
         */
        $asOfficial = $this->authorization->allows($actor, Permission::NewsfeedModerate);

        $comment = $this->engagement->comment(
            $model,
            $validated['body'],
            $validated['parent_id'] ?? null,
            $actor,
            $asOfficial,
        );

        return ApiResponse::created($this->readerProjection($comment));
    }

    public function editComment(Request $request, ActorContext $actor, string $comment): JsonResponse
    {
        $model = $this->commentOrFail($comment);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        return ApiResponse::item($this->readerProjection(
            $this->engagement->editOwnComment($model, $validated['body'], $actor),
        ));
    }

    public function deleteComment(Request $request, ActorContext $actor, string $comment): JsonResponse
    {
        $model = $this->commentOrFail($comment);

        $this->engagement->deleteOwnComment($model, $actor);

        return ApiResponse::item(['deleted' => true]);
    }

    /**
     * Records a share. A counter, and nothing else.
     */
    public function share(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->livePostOrFail($actor, $post);

        /*
         * NO DESTINATION IS ACCEPTED. There is deliberately no `platform`, `destination` or
         * `recipient` in this contract — the master command forbids tracking external destinations
         * or personal contacts, and a field that existed would eventually be filled.
         */
        $this->engagement->share($model, $actor);

        return ApiResponse::item($this->engagement->engagementFor($model, $actor));
    }

    // ── the moderator ─────────────────────────────────────────────────────────────────

    public function moderationQueue(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedModerate);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->engagement->allComments();

        $state = $request->query('moderation_state');

        if (is_string($state) && $state !== '') {
            $query->where('moderation_state', $state);
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where('body', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (NewsfeedComment $comment): array => $this->moderatorProjection($comment),
        );
    }

    public function moderate(Request $request, ActorContext $actor, string $comment): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedModerate);

        $model = $this->commentOrFail($comment);

        $validated = $request->validate([
            'moderation_state' => ['required', 'string', 'in:'.implode(',', ModerationState::values())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->moderatorProjection($this->engagement->moderate(
            $model,
            ModerationState::from($validated['moderation_state']),
            $validated['reason'] ?? null,
            $actor,
        )));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * What a reader sees.
     *
     * NO MODERATION FIELDS AT ALL — not the state, not the reason, not the moderator. A reader
     * only ever receives visible comments, so a state field would be a constant; and a reason
     * field would publish a moderator's note about somebody to everybody.
     *
     * @return array<string, mixed>
     */
    private function readerProjection(NewsfeedComment $comment): array
    {
        return [
            'id' => $comment->uuid,
            'parent_id' => $comment->parent_id === null ? null : (string) NewsfeedComment::query()
                ->whereKey($comment->parent_id)->value('uuid'),
            'body' => $comment->body,
            // Whether the office wrote it, which is the one thing a reader most needs to know.
            'is_official' => (bool) $comment->is_official,
            'author_subject_id' => $comment->author_subject_id,
            'created_at' => $comment->created_at?->toIso8601ZuluString(),
            // Shown so a reply is not silently rewritten under a reader who already answered it.
            'edited_at' => $comment->edited_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moderatorProjection(NewsfeedComment $comment): array
    {
        return $this->readerProjection($comment) + [
            'moderation_state' => $comment->moderation_state->value,
            'moderation_reason' => $comment->moderation_reason,
            'moderated_by' => $comment->moderated_by,
            'moderated_at' => $comment->moderated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * A post the reader may actually see, loaded through the public query.
     *
     * Engagement on a draft would accumulate before publication and then appear the moment it went
     * live, with counts nobody could account for.
     */
    private function livePostOrFail(ActorContext $actor, string $uuid): NewsfeedPost
    {
        /** @var NewsfeedPost|null $post */
        $post = $this->newsfeed->publicQuery(null)->where('uuid', $uuid)->first();

        if ($post === null) {
            throw ResourceNotFoundException::make('That post was not found.');
        }

        return $post;
    }

    private function commentOrFail(string $uuid): NewsfeedComment
    {
        /** @var NewsfeedComment|null $comment */
        $comment = NewsfeedComment::query()->where('uuid', $uuid)->first();

        if ($comment === null) {
            throw ResourceNotFoundException::make('That comment was not found.');
        }

        return $comment;
    }
}
