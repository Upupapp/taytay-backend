<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Content\Domain\PostStatus;
use Modules\Content\Infrastructure\Eloquent\NewsfeedMedia;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Files\Application\DocumentLibrary;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;

/**
 * Authoring and publishing announcements (ADR 0028).
 *
 * THE PUBLIC QUERY IS THE SECURITY BOUNDARY, and it is one method. Every citizen-facing read goes
 * through {@see publicQuery()}, which narrows to published-and-arrived-and-audience-matched at the
 * query rather than filtering a wider result afterwards.
 *
 * The difference is the acceptance criterion "draft/scheduled content cannot leak via a guessed
 * ID": a `where uuid = ?` against a query that already excludes drafts returns nothing for a
 * draft, whereas a lookup followed by an `if ($post->isDraft()) abort(404)` returns nothing only
 * as long as nobody adds a second endpoint and forgets the `if`.
 */
final class NewsfeedService
{
    public function __construct(
        private readonly ContentAudit $audit,
        private readonly DocumentLibrary $library,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function draft(array $attributes, ActorContext $actor): NewsfeedPost
    {
        $this->assertAudienceIsCoherent($attributes);

        /** @var NewsfeedPost $post */
        $post = NewsfeedPost::query()->create([
            'headline' => $attributes['headline'] ?? null,
            'body' => (string) $attributes['body'],
            'category' => (string) $attributes['category'],
            'author_subject_id' => $actor->subjectId,
            'audience' => $attributes['audience'] ?? 'municipality',
            'audience_barangay_id' => $attributes['audience_barangay_id'] ?? null,
            'comments_enabled' => $attributes['comments_enabled'] ?? true,
            'status' => PostStatus::Draft,
        ]);

        $this->audit->record($actor->subjectId, 'newsfeed.drafted', 'Newsfeed post drafted', (string) $post->uuid);

        return $post;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(NewsfeedPost $post, array $changes, ActorContext $actor): NewsfeedPost
    {
        /*
         * A published post can still be corrected — a wrong date on a relief schedule must be
         * fixable without pulling the announcement down and confusing everyone who already read
         * it. What cannot change is its history: the edit is audited and `published_at` stays put.
         */
        if ($post->status === PostStatus::Archived) {
            throw new ApiException(ErrorCode::Conflict, 'An archived post cannot be edited. Publish a new one.');
        }

        $this->assertAudienceIsCoherent($changes + [
            'audience' => $changes['audience'] ?? $post->audience,
        ]);

        $post->forceFill(array_intersect_key($changes, array_flip([
            'headline', 'body', 'category', 'audience', 'audience_barangay_id', 'comments_enabled',
        ])))->save();

        $this->audit->record($actor->subjectId, 'newsfeed.updated', 'Newsfeed post updated', (string) $post->uuid);

        return $post->refresh();
    }

    /**
     * Moves a post through its lifecycle.
     */
    public function transition(
        NewsfeedPost $post,
        PostStatus $target,
        ActorContext $actor,
        ?Carbon $publishAt = null,
    ): NewsfeedPost {
        return DB::transaction(function () use ($post, $target, $actor, $publishAt): NewsfeedPost {
            /** @var NewsfeedPost $post */
            $post = NewsfeedPost::query()->lockForUpdate()->findOrFail($post->id);

            if (! $post->status->canMoveTo($target)) {
                throw InvalidStateTransitionException::between($post->status->value, $target->value);
            }

            if ($target === PostStatus::Scheduled) {
                if ($publishAt === null || $publishAt->isPast()) {
                    // A schedule in the past is a publish pretending to be a schedule, and it
                    // makes "was this reviewed before it went out?" unanswerable.
                    throw new ApiException(ErrorCode::ValidationFailed, 'A scheduled post needs a future time.');
                }

                $post->forceFill(['status' => $target, 'publish_at' => $publishAt])->save();
            } elseif ($target === PostStatus::Published) {
                $post->forceFill([
                    'status' => $target,
                    // Publishing now means now. `publish_at` is what the public query compares
                    // against, so it must be set even on an immediate publish.
                    'publish_at' => $post->publish_at ?? now(),
                    'published_at' => $post->published_at ?? now(),
                ])->save();
            } elseif ($target === PostStatus::Archived) {
                $post->forceFill(['status' => $target, 'archived_at' => now()])->save();
            } else {
                // Back to draft: the schedule is cleared, or it would silently republish.
                $post->forceFill(['status' => $target, 'publish_at' => null])->save();
            }

            $this->audit->record(
                $actor->subjectId,
                'newsfeed.'.$target->value,
                'Newsfeed post '.$target->value,
                (string) $post->uuid,
            );

            return $post->refresh();
        });
    }

    /**
     * Derives the public renditions of a post's images, or removes them.
     *
     * **PUBLICATION IS THE ONLY ROUTE TO A PUBLIC OBJECT** (ADR 0033 §3). Content decides that a
     * post is live; Files derives a re-encoded, metadata-free rendition and writes *that* to the
     * public bucket. The uploaded original never moves and never touches it.
     *
     * Called OUTSIDE the transition transaction on purpose. Deriving an image is slow, and a
     * failure to resize a photograph must not roll back the publication of an advisory — the post
     * is live either way, and a missing thumbnail is a smaller problem than an announcement that
     * silently did not go out.
     *
     * The reverse matters more: archiving withdraws the objects, because a post taken down whose
     * image stayed at a public URL would be a takedown that did not take anything down.
     */
    public function syncPublishedMedia(NewsfeedPost $post): void
    {
        $fileIds = $post->media()->pluck('stored_file_id')->map(strval(...))->all();

        if ($fileIds === []) {
            return;
        }

        if ($post->isLive()) {
            $this->library->publishMedia($fileIds);

            return;
        }

        $this->library->withdrawMedia($fileIds);
    }

    public function setPinned(NewsfeedPost $post, bool $pinned, ActorContext $actor): NewsfeedPost
    {
        $post->forceFill(['is_pinned' => $pinned])->save();

        $this->audit->record(
            $actor->subjectId,
            $pinned ? 'newsfeed.pinned' : 'newsfeed.unpinned',
            'Newsfeed post '.($pinned ? 'pinned' : 'unpinned'),
            (string) $post->uuid,
        );

        return $post->refresh();
    }

    /**
     * Attaches an image.
     *
     * ALT TEXT IS REQUIRED unless the image is explicitly marked decorative. A published municipal
     * announcement a blind resident cannot read is a service the LGU is not providing to somebody
     * entitled to it — and an optional field is an omitted field.
     */
    public function attachMedia(
        NewsfeedPost $post,
        string $fileUuid,
        ?string $altText,
        bool $isDecorative,
        int $position,
    ): NewsfeedMedia {
        if (! $isDecorative && trim((string) $altText) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Describe this image for readers who cannot see it, or mark it decorative.',
            );
        }

        /** @var NewsfeedMedia $media */
        $media = NewsfeedMedia::query()->updateOrCreate(
            ['newsfeed_post_id' => $post->id, 'stored_file_id' => $fileUuid],
            [
                // Never null: "nobody wrote one" and "there is nothing to write" stay
                // distinguishable through the flag, not through an empty string.
                'alt_text' => $isDecorative ? '' : (string) $altText,
                'is_decorative' => $isDecorative,
                'position' => $position,
            ],
        );

        return $media;
    }

    /**
     * The staff view: everything, including drafts.
     *
     * @return Builder<NewsfeedPost>
     */
    public function adminQuery(): Builder
    {
        return NewsfeedPost::query()->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * THE PUBLIC QUERY. Every citizen-facing read goes through this.
     *
     * Three conditions, applied at the query:
     *
     *  1. **published** — a draft is absent, not filtered;
     *  2. **`publish_at` has arrived** — a published post with a future time is still embargoed,
     *     and treating `status` alone as the gate is how an announcement goes out early;
     *  3. **the audience matches** — a barangay-targeted post reaches that barangay and the
     *     unscoped reader, not everybody.
     *
     * @param  int|null  $barangayId  the reader's barangay, or null for an anonymous caller
     * @return Builder<NewsfeedPost>
     */
    public function publicQuery(?int $barangayId, ?Carbon $on = null): Builder
    {
        return NewsfeedPost::query()
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $on ?? Carbon::now())
            ->where(function (Builder $query) use ($barangayId): void {
                $query->where('audience', 'municipality');

                /*
                 * An anonymous or unlinked reader sees municipality-wide posts only.
                 *
                 * Not because a barangay notice is confidential, but because showing somebody a
                 * distribution schedule for a barangay they do not live in produces a queue of
                 * people at a hall they are not on the list for.
                 */
                if ($barangayId !== null) {
                    $query->orWhere(function (Builder $inner) use ($barangayId): void {
                        $inner->where('audience', 'barangay')
                            ->where('audience_barangay_id', $barangayId);
                    });
                }
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('publish_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertAudienceIsCoherent(array $attributes): void
    {
        $audience = $attributes['audience'] ?? null;

        if ($audience === 'barangay' && empty($attributes['audience_barangay_id'])) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A barangay post needs a barangay.');
        }
    }
}
