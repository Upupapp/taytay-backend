<?php

declare(strict_types=1);

namespace Modules\Content\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Content\Application\NewsfeedService;
use Modules\Content\Domain\PostStatus;
use Modules\Content\Infrastructure\Eloquent\NewsfeedMedia;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Files\Application\DocumentLibrary;
use Modules\Identity\Application\AccountDirectory;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Exceptions\UnauthenticatedException;
use Modules\Shared\Http\ApiResponse;

/**
 * The newsfeed, for staff and for readers (ADR 0028).
 *
 * TWO PROJECTIONS AND TWO QUERIES, never one of each. The staff projection carries the author, the
 * status, the schedule and the audience; the public one carries what an announcement is. They are
 * separate methods reading separate queries, because the alternative — one projection with fields
 * removed for citizens — is the arrangement that leaks the first time somebody adds a field.
 */
final class NewsfeedController
{
    public function __construct(
        private readonly NewsfeedService $newsfeed,
        private readonly AccountDirectory $accounts,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
        private readonly DocumentLibrary $library,
    ) {}

    // ── staff ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->newsfeed->adminQuery();

        foreach (['status', 'category', 'audience'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where(function ($where) use ($search): void {
                $where->where('headline', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        /*
         * EAGER-LOADED. `$post->media()` inside the projection was an N+1: measured at 7
         * queries for one post and 14 for eight. A feed page is twenty-five posts, so the
         * endpoint every resident opens first was doing twenty-five avoidable round trips.
         */
        $rows = $query->with('media')->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (NewsfeedPost $post): array => $this->adminProjection($post),
        );
    }

    public function show(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        return ApiResponse::item($this->adminProjection($this->postOrFail($post)));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        $validated = $request->validate($this->rules());

        return ApiResponse::created($this->adminProjection(
            $this->newsfeed->draft($validated, $actor),
        ));
    }

    public function update(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        $model = $this->postOrFail($post);
        $validated = $request->validate($this->rules(partial: true));

        return ApiResponse::item($this->adminProjection(
            $this->newsfeed->update($model, $validated, $actor),
        ));
    }

    /**
     * What happened to a post, and when (TAB 07).
     *
     * ── WHY NOT JUST POINT THE CONSOLE AT THE AUDIT TRAIL ────────────────────────────
     *
     * Every act here already writes to it, and `GET admin/audit-entries/for-entity` already
     * exists — so the obvious answer was to map this row to that endpoint and build nothing.
     *
     * It does not work, for a reason worth recording: `audit.view` is deliberately withheld from
     * everybody except the Data Protection Officer, because the auditee must not be the auditor.
     * A newsfeed manager reading the lifecycle of **their own post** would have needed the
     * permission that lets them read the trail of every approval in the office.
     *
     * So this returns the post's **own** lifecycle from its own dated columns, under the module's
     * own permission. It is not a window into the trail, and it cannot become one.
     *
     * ── AND WHY A POST NEEDS A HISTORY AT ALL ────────────────────────────────────────
     *
     * A post goes outward and nothing brings it back. Archiving removes it from the feed going
     * forward and reaches nobody who already read it. The history is the only evidence of what
     * residents were actually shown and for how long — without it, *"we took it down"* is a claim
     * with nothing behind it.
     */
    public function history(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        $model = $this->postOrFail($post);

        $events = [];

        $events[] = [
            'kind' => 'created',
            'occurred_at' => $model->created_at?->toIso8601ZuluString(),
            'detail' => null,
        ];

        if ($model->publish_at !== null) {
            $events[] = [
                'kind' => 'scheduled',
                'occurred_at' => $model->publish_at->toIso8601ZuluString(),
                'detail' => null,
            ];
        }

        if ($model->published_at !== null) {
            $events[] = [
                'kind' => 'published',
                'occurred_at' => $model->published_at->toIso8601ZuluString(),
                'detail' => null,
            ];
        }

        if ($model->archived_at !== null) {
            $events[] = [
                'kind' => 'archived',
                'occurred_at' => $model->archived_at->toIso8601ZuluString(),
                /*
                 * NULL, AND THAT IS A GAP RATHER THAN A DESIGN. `newsfeed_posts` records when a
                 * post was archived and not why — so the one question worth asking about a removed
                 * post is the one this history cannot answer. Recorded as G-30; closing it is a
                 * column and a required field on the archive transition, not a projection change.
                 */
                'detail' => null,
            ];
        }

        $events = array_values(array_filter($events, static fn (array $e): bool => $e['occurred_at'] !== null));

        usort($events, static fn (array $a, array $b): int => $b['occurred_at'] <=> $a['occurred_at']);

        return ApiResponse::page(Page::fromArray($events, PaginationParams::fromRequest($request)));
    }

    /**
     * Schedule, publish now, return to draft, archive.
     */
    public function transition(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $model = $this->postOrFail($post);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', PostStatus::values())],
            'publish_at' => ['required_if:status,scheduled', 'nullable', 'date'],
        ]);

        $target = PostStatus::from($validated['status']);

        /*
         * The permission comes from the TARGET state. Drafting and archiving are editorial work;
         * putting something on the municipal feed is a different act, and an office may want the
         * second held by fewer people — the same shape as the case lifecycle in ADR 0016 §2.
         */
        $this->authorization->authorize($actor, $target->requiredPermission());

        $updated = $this->newsfeed->transition(
            $model,
            $target,
            $actor,
            isset($validated['publish_at']) ? Carbon::parse($validated['publish_at']) : null,
        );

        /*
         * OUTSIDE the transition, and in BOTH directions.
         *
         * Publishing derives the public renditions; archiving or returning to draft removes them.
         * The reverse is the one that matters more — a post taken down whose image stayed at a
         * public URL would be a takedown that did not take anything down (ADR 0033 §3).
         */
        $this->newsfeed->syncPublishedMedia($updated);

        return ApiResponse::item($this->adminProjection($updated));
    }

    public function setPinned(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedPublish);

        $model = $this->postOrFail($post);
        $validated = $request->validate(['is_pinned' => ['required', 'boolean']]);

        return ApiResponse::item($this->adminProjection(
            $this->newsfeed->setPinned($model, (bool) $validated['is_pinned'], $actor),
        ));
    }

    public function attachMedia(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        $model = $this->postOrFail($post);

        $validated = $request->validate([
            'file_id' => ['required', 'string', 'max:64'],
            // Required unless explicitly decorative — see the service for why an optional alt
            // text is an omitted one.
            'alt_text' => ['required_without:is_decorative', 'nullable', 'string', 'max:255'],
            'is_decorative' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);

        $this->newsfeed->attachMedia(
            $model,
            $validated['file_id'],
            $validated['alt_text'] ?? null,
            (bool) ($validated['is_decorative'] ?? false),
            (int) ($validated['position'] ?? 0),
        );

        // An image attached to an ALREADY-LIVE post must become publicly deliverable now; one
        // attached to a draft must not. Deciding from the post's live state rather than from
        // which endpoint was called means there is no ordering of these two operations that
        // leaves an image public on a draft or private on a published post.
        $this->newsfeed->syncPublishedMedia($model->refresh());

        return ApiResponse::item($this->adminProjection($model->refresh()));
    }

    /**
     * A summary for the newsfeed dashboard tile.
     */
    public function metrics(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::NewsfeedManage);

        return ApiResponse::item([
            'draft' => NewsfeedPost::query()->where('status', 'draft')->count(),
            'scheduled' => NewsfeedPost::query()->where('status', 'scheduled')->count(),
            'published' => NewsfeedPost::query()->where('status', 'published')->count(),
            'archived' => NewsfeedPost::query()->where('status', 'archived')->count(),
            'pinned' => NewsfeedPost::query()->where('is_pinned', true)->count(),
        ]);
    }

    // ── readers ───────────────────────────────────────────────────────────────────────

    /**
     * The published feed.
     *
     * Reads through `publicQuery()`, which narrows at the query. A draft is **absent**, not
     * filtered out — which is what makes "draft content cannot leak via a guessed ID" survive the
     * next endpoint somebody adds.
     */
    public function feed(Request $request, ActorContext $actor): JsonResponse
    {
        $this->assertReadable($actor);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->newsfeed->publicQuery($this->readerBarangay($actor));

        $total = (clone $query)->count();
        /*
         * EAGER-LOADED. `$post->media()` inside the projection was an N+1: measured at 7
         * queries for one post and 14 for eight. A feed page is twenty-five posts, so the
         * endpoint every resident opens first was doing twenty-five avoidable round trips.
         */
        $rows = $query->with('media')->forPage($pagination->page, $pagination->perPage)->get();

        /*
         * TWO QUERIES FOR THE WHOLE PAGE'S IMAGES, whatever the page size. Resolving them inside
         * the projection cost three per post — measured at 10 queries for one post with a picture
         * and 25 for six — so a feed page of twenty-five was seventy-five avoidable round trips on
         * the endpoint every resident opens first, over the connection least able to afford them.
         */
        $mediaUrls = $this->library->publicMediaUrlsFor(
            $rows->flatMap(fn (NewsfeedPost $post): array => $post->media->pluck('stored_file_id')->all())
                ->map(strval(...))
                ->all(),
        );

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (NewsfeedPost $post): array => $this->publicProjection($post, $mediaUrls),
        );
    }

    public function read(Request $request, ActorContext $actor, string $post): JsonResponse
    {
        $this->assertReadable($actor);

        /** @var NewsfeedPost|null $model */
        $model = $this->newsfeed->publicQuery($this->readerBarangay($actor))
            ->where('uuid', $post)
            ->first();

        /*
         * The lookup runs against the public query, so a draft or an embargoed post is simply not
         * there. No status check follows this line, because there is nothing left to check.
         */
        if ($model === null) {
            throw ResourceNotFoundException::make('That post was not found.');
        }

        return ApiResponse::item($this->publicProjection($model));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function adminProjection(NewsfeedPost $post): array
    {
        return $this->publicProjection($post) + [
            'status' => $post->status->value,
            'author_subject_id' => $post->author_subject_id,
            'audience' => $post->audience,
            'audience_barangay_id' => $post->audience_barangay_id === null
                ? null
                : (int) $post->audience_barangay_id,
            'scheduled_for' => $post->publish_at?->toIso8601ZuluString(),
            'archived_at' => $post->archived_at?->toIso8601ZuluString(),
            'available_transitions' => array_map(
                static fn (PostStatus $status): string => $status->value,
                $post->status->allowedNext(),
            ),
        ];
    }

    /**
     * What an announcement is.
     *
     * A SEPARATE METHOD, not the admin one with fields removed. Subtractive projection leaks the
     * first time somebody adds a field and forgets the deny-list; this one fails closed, because a
     * new column is absent until somebody puts it here (ADR 0016 §5, same rule).
     *
     * Absent by construction: the status, the author, the audience, the schedule, and whether the
     * post was ever a draft. A reader is told what the office is saying, not how it decided to say
     * it.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, array<string, string>>|null  $mediaUrls  resolved for the whole page;
     *                                                                null when rendering one post
     */
    private function publicProjection(NewsfeedPost $post, ?array $mediaUrls = null): array
    {
        return [
            'id' => $post->uuid,
            'headline' => $post->headline,
            'body' => $post->body,
            'category' => $post->category,
            'is_pinned' => (bool) $post->is_pinned,
            'comments_enabled' => (bool) $post->comments_enabled,
            // The moment it became public, which is the date a reader means by "when was this
            // posted". Never `created_at`, which is when somebody started drafting it.
            'published_at' => $post->published_at?->toIso8601ZuluString(),
            // `media`, not `media()` — the loaded relation, so a page of posts costs one query
            // for all of their media rather than one each.
            'media' => $post->media->map(fn (NewsfeedMedia $media): array => [
                'file_id' => $media->stored_file_id,
                // Always present, so a client never has to decide what to do with a missing one.
                'alt_text' => (string) $media->alt_text,
                'is_decorative' => (bool) $media->is_decorative,
                /*
                 * Public URLs of the RE-ENCODED renditions — never of the uploaded file, which
                 * stays private for its whole life. Empty for a post that is not live, which is
                 * the same answer as for a post that never had an image, so the absence tells a
                 * reader nothing about whether a draft exists (ADR 0033 §3).
                 */
                /*
                 * From the page's resolved map when there is one — and **an absent key is a real
                 * answer, not a cache miss**: it means the image has no published renditions,
                 * which is the normal state for a draft or a failed derivation (ADR 0033 §3).
                 *
                 * Falling back to a per-file lookup on an absent key re-queries to learn the same
                 * thing, and that fallback measured WORSE than the N+1 it replaced — 27 queries
                 * against 25 for six posts, because it paid for the batch and then did the work
                 * anyway. `null` means "no map was supplied", which is the detail endpoint
                 * rendering one post; an empty entry means "asked, and there are none".
                 */
                'urls' => $mediaUrls === null
                    ? $this->library->publicMediaUrls((string) $media->stored_file_id)
                    : ($mediaUrls[(string) $media->stored_file_id] ?? []),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'headline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body' => [$required, 'string', 'max:20000'],
            'category' => [$required, 'string', 'max:48'],
            'audience' => ['sometimes', 'string', 'in:municipality,barangay'],
            'audience_barangay_id' => ['sometimes', 'nullable', 'integer'],
            'comments_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Anonymous reading is off unless the LGU turned it on.
     *
     * The master command permits it "only if Taytay explicitly marks Newsfeed public", so the
     * default refuses. Defaulting the other way would have published a barangay's relief schedule
     * to the open internet before anybody at the MSWDO was asked (gap G-36).
     */
    private function assertReadable(ActorContext $actor): void
    {
        if ($actor->subjectId === null && ! config('newsfeed.public_access', false)) {
            throw UnauthenticatedException::make();
        }
    }

    /**
     * The reader's barangay, for audience matching. Null for anonymous or unlinked accounts.
     */
    private function readerBarangay(ActorContext $actor): ?int
    {
        if ($actor->subjectId === null) {
            return null;
        }

        $residentId = $this->accounts->residentIdFor($actor->subjectId);

        return $residentId === null ? null : $this->residents->summaryFor($residentId)?->barangayId;
    }

    private function postOrFail(string $uuid): NewsfeedPost
    {
        /** @var NewsfeedPost|null $post */
        $post = NewsfeedPost::query()->where('uuid', $uuid)->first();

        if ($post === null) {
            throw ResourceNotFoundException::make('That post was not found.');
        }

        return $post;
    }
}
