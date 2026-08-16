<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Content\Domain\PostStatus;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Content\Jobs\PublishScheduledPosts;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 23, as tests.
 *
 *  1. **A resident cannot create, edit or publish posts.**
 *  2. **A scheduled post publishes at most once.**
 *  3. **Draft/scheduled content cannot leak via a guessed ID.**
 */
final class NewsfeedTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 3: nothing unpublished leaks ───────────────────────────────────────

    #[Test]
    public function a_draft_is_absent_from_the_public_feed_and_unreachable_by_id(): void
    {
        Sanctum::actingAs($this->admin());
        $draft = $this->draft(['body' => 'An unreleased announcement.']);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->assertCount(0, $this->getJson('/api/v1/newsfeed')->assertOk()->json('data'));

        /*
         * The lookup runs against the public query, so a draft is simply NOT THERE — no status
         * check follows it. That is what makes this survive the next endpoint somebody adds: a
         * `where uuid = ?` on a query that already excludes drafts cannot be got wrong, whereas
         * a lookup followed by `if (isDraft) abort(404)` only holds while everybody remembers the
         * `if`.
         */
        $this->getJson("/api/v1/newsfeed/{$draft}")->assertNotFound();
    }

    #[Test]
    public function a_scheduled_post_is_embargoed_until_its_time_arrives(): void
    {
        Sanctum::actingAs($this->admin());

        $post = $this->draft();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addDay()->toIso8601ZuluString(),
        ])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson("/api/v1/newsfeed/{$post}")->assertNotFound();
    }

    #[Test]
    public function a_published_post_with_a_future_time_is_still_embargoed(): void
    {
        Sanctum::actingAs($this->admin());

        $post = $this->draft();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addWeek()->toIso8601ZuluString(),
        ])->assertOk();

        // Force the status forward without moving the schedule — the shape a partial migration or
        // a hand-edited row would leave behind.
        NewsfeedPost::query()->where('uuid', $post)->update(['status' => 'published']);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * Both conditions are checked, always together. Treating `status` alone as the gate is
         * how an embargoed announcement goes out early.
         */
        $this->getJson("/api/v1/newsfeed/{$post}")->assertNotFound();
    }

    #[Test]
    public function the_public_projection_omits_how_the_office_decided_to_say_it(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->publishedPost();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $body = $this->getJson("/api/v1/newsfeed/{$post}")->assertOk()->content();

        /*
         * A separate method, not the admin projection with fields removed. Subtractive projection
         * leaks the first time somebody adds a field and forgets the deny-list.
         */
        foreach (['status', 'author_subject_id', 'audience', 'scheduled_for', 'available_transitions'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }

        $this->assertStringContainsString('published_at', $body);
    }

    // ── criterion 2: a scheduled post publishes at most once ─────────────────────────

    #[Test]
    public function the_sweep_publishes_a_due_post_exactly_once_however_often_it_runs(): void
    {
        Sanctum::actingAs($this->admin());

        $post = $this->draft();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addHour()->toIso8601ZuluString(),
        ])->assertOk();

        $sweep = fn (): int => app(PublishScheduledPosts::class, ['asOf' => now()->addDay()->toIso8601ZuluString()])
            ->handle();

        /*
         * A conditional UPDATE, not a lock and not a check-then-write. Two workers racing produce
         * one update and one no-op, because the second one's WHERE no longer matches — there is
         * no window between reading and writing for a second worker to fit into.
         */
        $this->assertSame(1, $sweep());
        $this->assertSame(0, $sweep());
        $this->assertSame(0, $sweep());

        $row = NewsfeedPost::query()->where('uuid', $post)->firstOrFail();
        $this->assertSame('published', $row->status->value);
        $this->assertNotNull($row->published_at);
    }

    #[Test]
    public function the_sweep_leaves_a_post_whose_time_has_not_come(): void
    {
        Sanctum::actingAs($this->admin());

        $post = $this->draft();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addWeek()->toIso8601ZuluString(),
        ])->assertOk();

        $this->assertSame(0, app(PublishScheduledPosts::class)->handle());
        $this->assertSame(PostStatus::Scheduled, NewsfeedPost::query()->where('uuid', $post)->value('status'));
    }

    #[Test]
    public function a_schedule_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        // A schedule in the past is a publish pretending to be a schedule, and it makes "was this
        // reviewed before it went out?" unanswerable.
        $this->postJson("/api/v1/admin/newsfeed/{$this->draft()}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->subDay()->toIso8601ZuluString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function pulling_a_scheduled_post_back_to_draft_clears_its_schedule(): void
    {
        Sanctum::actingAs($this->admin());

        $post = $this->draft();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addHour()->toIso8601ZuluString(),
        ])->assertOk();

        // Somebody spotted a mistake before it went out — the whole reason scheduling exists.
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'draft'])->assertOk();

        // The schedule is cleared, or the sweep would silently republish it.
        $this->assertNull(NewsfeedPost::query()->where('uuid', $post)->value('publish_at'));
        $this->assertSame(0, app(PublishScheduledPosts::class, ['asOf' => now()->addYear()->toIso8601ZuluString()])->handle());
    }

    // ── criterion 1: a resident cannot author ────────────────────────────────────────

    #[Test]
    public function a_resident_cannot_create_edit_or_publish(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->draft();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/v1/admin/newsfeed')->assertForbidden();
        $this->postJson('/api/v1/admin/newsfeed', ['body' => 'Mine', 'category' => 'advisory'])->assertForbidden();
        $this->patchJson("/api/v1/admin/newsfeed/{$post}", ['body' => 'Edited'])->assertForbidden();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertForbidden();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/pin", ['is_pinned' => true])->assertForbidden();
    }

    #[Test]
    public function drafting_and_publishing_are_different_permissions(): void
    {
        Sanctum::actingAs($this->staff());

        // Front-line staff draft announcements.
        $post = $this->draft();
        $this->patchJson("/api/v1/admin/newsfeed/{$post}", ['body' => 'Corrected copy.'])->assertOk();

        /*
         * Putting one on the municipal feed is not theirs: an announcement that has been seen
         * cannot be unseen, and an office may want that held by fewer people — the same shape as
         * endorse/approve on a case.
         */
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertForbidden();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/pin", ['is_pinned' => true])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();
    }

    // ── anonymous access is off unless the LGU turns it on ───────────────────────────

    #[Test]
    public function anonymous_reading_is_refused_by_default(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->publishedPost();

        $this->app['auth']->forgetGuards();

        /*
         * The master command permits anonymous access "only if Taytay explicitly marks Newsfeed
         * public". Defaulting the other way would have published a barangay's relief schedule to
         * the open internet before anybody at the MSWDO was asked (gap G-36).
         */
        $this->getJson('/api/v1/newsfeed')->assertUnauthorized();
        $this->getJson("/api/v1/newsfeed/{$post}")->assertUnauthorized();
    }

    #[Test]
    public function anonymous_reading_works_once_the_lgu_enables_it(): void
    {
        Sanctum::actingAs($this->admin());
        $this->publishedPost();

        config()->set('newsfeed.public_access', true);
        $this->app['auth']->forgetGuards();

        $this->assertCount(1, $this->getJson('/api/v1/newsfeed')->assertOk()->json('data'));
    }

    #[Test]
    public function an_anonymous_reader_sees_municipality_posts_only(): void
    {
        Sanctum::actingAs($this->admin());

        $this->publishedPost();
        $this->publishedPost([
            'audience' => 'barangay',
            'audience_barangay_id' => $this->barangayId(),
            'body' => 'Relief distribution at the Dolores hall.',
        ]);

        config()->set('newsfeed.public_access', true);
        $this->app['auth']->forgetGuards();

        /*
         * Not because a barangay notice is confidential, but because showing somebody a
         * distribution schedule for a barangay they do not live in produces a queue of people at a
         * hall they are not on the list for.
         */
        $body = $this->getJson('/api/v1/newsfeed')->assertOk();
        $this->assertCount(1, $body->json('data'));
        $this->assertStringNotContainsString('Dolores hall', $body->content());
    }

    // ── audience targeting ───────────────────────────────────────────────────────────

    #[Test]
    public function a_barangay_post_reaches_that_barangay_and_not_another(): void
    {
        Sanctum::actingAs($this->admin());

        $this->publishedPost([
            'audience' => 'barangay',
            'audience_barangay_id' => $this->barangayId(),
            'body' => 'Targeted advisory.',
        ]);

        [$local, $resident] = $this->activeCitizenWithResident();
        $resident->forceFill(['barangay_id' => $this->barangayId()])->save();

        Sanctum::actingAs($local);
        $this->assertCount(1, $this->getJson('/api/v1/newsfeed')->assertOk()->json('data'));

        [$elsewhere, $other] = $this->activeCitizenWithResident();
        $other->forceFill(['barangay_id' => $this->otherBarangayId()])->save();

        Sanctum::actingAs($elsewhere);
        $this->assertCount(0, $this->getJson('/api/v1/newsfeed')->assertOk()->json('data'));
    }

    #[Test]
    public function a_barangay_post_needs_a_barangay(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Targeted at nowhere.',
            'category' => 'advisory',
            'audience' => 'barangay',
        ])->assertStatus(422);
    }

    // ── alt text ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_image_needs_alt_text_unless_it_is_marked_decorative(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->draft();

        /*
         * A published municipal announcement a blind resident cannot read is a service the LGU is
         * not providing to somebody entitled to it — and an optional field is an omitted field.
         */
        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) Str::uuid7(),
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) Str::uuid7(),
            'alt_text' => 'Queue of residents outside the barangay hall.',
        ])->assertOk();

        // "Nothing to describe" is an explicit statement, not a blank field.
        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) Str::uuid7(),
            'is_decorative' => true,
        ])->assertOk();
    }

    #[Test]
    public function alt_text_reaches_the_reader(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->draft();

        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) Str::uuid7(),
            'alt_text' => 'Queue of residents outside the barangay hall.',
        ])->assertOk();

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $media = $this->getJson("/api/v1/newsfeed/{$post}")->assertOk()->json('data.media.0');

        // Always present, so a client never has to decide what to do with a missing one.
        $this->assertSame('Queue of residents outside the barangay hall.', $media['alt_text']);
    }

    // ── the lifecycle ────────────────────────────────────────────────────────────────

    #[Test]
    public function an_archived_post_is_not_resurrected(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->publishedPost();

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'archived'])->assertOk();

        /*
         * Republishing would put an old post back at the top of the feed with its original date,
         * which reads as the office announcing something old as if it were new. A new post is a
         * new post.
         */
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertStatus(409);
        $this->patchJson("/api/v1/admin/newsfeed/{$post}", ['body' => 'Edited'])->assertStatus(409);
    }

    #[Test]
    public function a_published_post_can_still_be_corrected(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->publishedPost();

        // A wrong date on a relief schedule must be fixable without pulling the announcement down
        // and confusing everybody who already read it.
        $this->patchJson("/api/v1/admin/newsfeed/{$post}", [
            'body' => 'Corrected: the distribution is on Thursday.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_entries', ['action' => 'newsfeed.updated', 'entity_id' => $post]);
    }

    #[Test]
    public function publishing_is_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $post = $this->publishedPost();

        // "Who put this on the municipal feed, and when" is the first question after an
        // announcement turns out to be wrong.
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'newsfeed.published',
            'entity_id' => $post,
            'actor_subject_id' => (string) $admin->uuid,
        ]);
    }

    #[Test]
    public function pinned_posts_sort_first(): void
    {
        Sanctum::actingAs($this->admin());

        $this->publishedPost(['body' => 'Ordinary notice.']);
        $pinned = $this->publishedPost(['body' => 'Important notice.']);
        $this->postJson("/api/v1/admin/newsfeed/{$pinned}/pin", ['is_pinned' => true])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $feed = $this->getJson('/api/v1/newsfeed')->assertOk()->json('data');
        $this->assertSame($pinned, $feed[0]['id']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): string
    {
        return $this->postJson('/api/v1/admin/newsfeed', $overrides + [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedPost(array $overrides = []): string
    {
        $post = $this->draft($overrides);

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        return $post;
    }
}
