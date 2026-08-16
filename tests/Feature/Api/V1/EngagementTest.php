<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Content\Application\EngagementService;
use Modules\Content\Infrastructure\Eloquent\NewsfeedReaction;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 24, as tests.
 *
 *  1. **A citizen can modify only their own engagement, except authorized moderators.**
 *  2. **Hidden and deleted moderation state is respected across all citizen feeds.**
 *  3. **No external share-recipient data is stored.**
 */
final class EngagementTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: only your own ───────────────────────────────────────────────────

    #[Test]
    public function a_reaction_is_bound_to_the_token_and_cannot_be_placed_for_somebody_else(): void
    {
        $post = $this->publishedPost();

        [$mine] = $this->activeCitizenWithResident();
        [$victim] = $this->activeCitizenWithResident();

        Sanctum::actingAs($mine);

        // There is no parameter naming a reactor — the extra fields are simply ignored.
        $this->postJson("/api/v1/newsfeed/{$post}/reaction", [
            'reaction' => 'like',
            'subject_id' => (string) $victim->uuid,
            'author_subject_id' => (string) $victim->uuid,
        ])->assertOk();

        $this->assertSame((string) $mine->uuid, (string) NewsfeedReaction::query()->value('subject_id'));
        $this->assertSame(0, NewsfeedReaction::query()->where('subject_id', (string) $victim->uuid)->count());
    }

    #[Test]
    public function one_person_holds_one_reaction_per_post(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);

        $this->postJson("/api/v1/newsfeed/{$post}/reaction", ['reaction' => 'like'])->assertOk();
        $this->postJson("/api/v1/newsfeed/{$post}/reaction", ['reaction' => 'helpful'])->assertOk();
        $body = $this->postJson("/api/v1/newsfeed/{$post}/reaction", ['reaction' => 'helpful'])
            ->assertOk()->json('data');

        /*
         * Changing a reaction updates the row. A history of somebody's changing feelings about a
         * municipal announcement is not a record this office needs to be able to produce.
         */
        $this->assertSame(1, NewsfeedReaction::query()->count());
        $this->assertSame(1, $body['reaction_total']);
        $this->assertSame('helpful', $body['my_reaction']);
    }

    #[Test]
    public function removing_a_reaction_touches_only_the_callers_own(): void
    {
        $post = $this->publishedPost();

        [$first] = $this->activeCitizenWithResident();
        [$second] = $this->activeCitizenWithResident();

        Sanctum::actingAs($first);
        $this->postJson("/api/v1/newsfeed/{$post}/reaction")->assertOk();

        Sanctum::actingAs($second);
        $this->postJson("/api/v1/newsfeed/{$post}/reaction")->assertOk();
        $this->deleteJson("/api/v1/newsfeed/{$post}/reaction")->assertOk();

        $this->assertSame(1, NewsfeedReaction::query()->count());
        $this->assertSame((string) $first->uuid, (string) NewsfeedReaction::query()->value('subject_id'));
    }

    #[Test]
    public function a_comment_can_only_be_edited_or_withdrawn_by_its_author(): void
    {
        $post = $this->publishedPost();

        [$author] = $this->activeCitizenWithResident();
        [$stranger] = $this->activeCitizenWithResident();

        Sanctum::actingAs($author);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'Will the distribution still run if it rains?',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($stranger);
        $this->patchJson("/api/v1/newsfeed-comments/{$comment}", ['body' => 'Rewritten.'])->assertForbidden();
        $this->deleteJson("/api/v1/newsfeed-comments/{$comment}")->assertForbidden();

        Sanctum::actingAs($author);
        $this->patchJson("/api/v1/newsfeed-comments/{$comment}", ['body' => 'Will it run if it rains?'])->assertOk();
    }

    #[Test]
    public function the_edit_window_closes(): void
    {
        $post = $this->publishedPost();
        [$author] = $this->activeCitizenWithResident();

        Sanctum::actingAs($author);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'An early comment.'])
            ->assertCreated()->json('data.id');

        $this->travel(EngagementService::EDIT_WINDOW_MINUTES + 1)->minutes();

        /*
         * An unbounded edit lets somebody write something agreeable, collect replies, and rewrite
         * it into something else — leaving a thread of people apparently agreeing with a statement
         * nobody saw.
         */
        $this->patchJson("/api/v1/newsfeed-comments/{$comment}", ['body' => 'Something else entirely.'])
            ->assertStatus(409);
    }

    #[Test]
    public function a_citizen_cannot_post_as_the_lgu(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);

        /*
         * `is_official` is set by the server from the author's permission. A resident able to post
         * a comment marked official could impersonate the municipality directly under its own
         * announcement — a more effective lie than most.
         */
        $body = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'This is the official position.',
            'is_official' => true,
        ])->assertCreated()->json('data');

        $this->assertFalse($body['is_official']);

        Sanctum::actingAs($this->admin());
        $official = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'The distribution runs rain or shine.',
        ])->assertCreated()->json('data');

        $this->assertTrue($official['is_official']);
    }

    #[Test]
    public function a_citizen_cannot_moderate(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A comment.'])
            ->assertCreated()->json('data.id');

        $this->getJson('/api/v1/admin/newsfeed-comments')->assertForbidden();
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'I disagree with it.',
        ])->assertForbidden();
    }

    // ── criterion 2: moderation state is respected everywhere ────────────────────────

    #[Test]
    public function a_hidden_comment_disappears_from_every_citizen_surface(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'A distinctive objectionable phrase.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'Abusive language.',
        ])->assertOk();

        Sanctum::actingAs($citizen);

        /*
         * Narrowed at the query, so a hidden comment is ABSENT from the thread rather than
         * filtered out of it — the same reason a draft is absent from the feed (ADR 0028 §2).
         */
        $thread = $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk();
        $this->assertCount(0, $thread->json('data'));
        $this->assertStringNotContainsString('distinctive objectionable', $thread->content());

        // And the count that travels with the post agrees, so nobody can infer a removal from
        // arithmetic.
        $this->assertSame(0, $this->postJson("/api/v1/newsfeed/{$post}/reaction")
            ->assertOk()->json('data.comment_count'));
    }

    #[Test]
    public function even_the_author_stops_seeing_their_hidden_comment_in_the_thread(): void
    {
        $post = $this->publishedPost();
        [$author] = $this->activeCitizenWithResident();

        Sanctum::actingAs($author);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'Removed later.'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'Off topic.',
        ])->assertOk();

        Sanctum::actingAs($author);
        $this->assertCount(0, $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk()->json('data'));

        // A hidden comment is not editable back into visibility: correcting the wording of
        // something a moderator removed would let an author launder a decision they disagree with.
        $this->patchJson("/api/v1/newsfeed-comments/{$comment}", ['body' => 'Rephrased.'])->assertStatus(409);
    }

    #[Test]
    public function a_removed_comment_is_a_state_and_not_a_missing_row(): void
    {
        $post = $this->publishedPost();
        [$author] = $this->activeCitizenWithResident();

        Sanctum::actingAs($author);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'The original wording.'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'deleted',
            'reason' => 'Personal attack.',
        ])->assertOk();

        /*
         * "What did it say, who wrote it, who removed it and why" is the question asked when the
         * author complains. A hard delete makes every answer "we do not know".
         */
        $row = collect($this->getJson('/api/v1/admin/newsfeed-comments')->assertOk()->json('data'))
            ->firstWhere('id', $comment);

        $this->assertSame('The original wording.', $row['body']);
        $this->assertSame('Personal attack.', $row['moderation_reason']);
        $this->assertNotNull($row['moderated_by']);
    }

    #[Test]
    public function a_moderation_decision_must_say_why(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A comment.'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());

        // A comment that disappears with no recorded reason is indistinguishable from censorship
        // to its author, and from a mistake to the colleague who finds it later.
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_reader_never_receives_moderation_fields(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A comment.'])->assertCreated();

        $body = $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk()->content();

        // A reader only ever receives visible comments, so a state field would be a constant —
        // and a reason field would publish a moderator's note about somebody to everybody.
        foreach (['moderation_state', 'moderation_reason', 'moderated_by'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
    }

    #[Test]
    public function moderation_is_audited(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A comment.'])
            ->assertCreated()->json('data.id');

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'Abusive language.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'newsfeed.comment-hidden',
            'entity_id' => $comment,
            'actor_subject_id' => (string) $admin->uuid,
        ]);
    }

    // ── criterion 3: no share-recipient data ─────────────────────────────────────────

    #[Test]
    public function a_share_is_a_counter_and_stores_no_destination(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);

        // A client that tried anyway.
        $body = $this->postJson("/api/v1/newsfeed/{$post}/share", [
            'platform' => 'facebook',
            'destination' => 'https://example.test/inbox',
            'recipient_phone' => '+639171234567',
        ])->assertOk()->json('data');

        $this->assertSame(1, $body['share_count']);

        /*
         * "Which platform do people share to?" is a reasonable product question whose answer turns
         * a municipal welfare system into a record of who talks to whom.
         */
        $row = (array) DB::table('newsfeed_shares')->first();

        $this->assertSame(['id', 'newsfeed_post_id', 'subject_id', 'created_at'], array_keys($row));

        $encoded = json_encode($row);
        $this->assertStringNotContainsString('facebook', $encoded);
        $this->assertStringNotContainsString('example.test', $encoded);
        $this->assertStringNotContainsString('639171234567', $encoded);
    }

    // ── engagement only happens on live posts ────────────────────────────────────────

    #[Test]
    public function a_draft_cannot_accumulate_engagement(): void
    {
        Sanctum::actingAs($this->admin());
        $draft = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Not yet published.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * Engagement on a draft would accumulate before publication and then appear the moment it
         * went live, with counts nobody could account for.
         */
        $this->postJson("/api/v1/newsfeed/{$draft}/reaction")->assertNotFound();
        $this->postJson("/api/v1/newsfeed/{$draft}/comments", ['body' => 'Early.'])->assertNotFound();
        $this->postJson("/api/v1/newsfeed/{$draft}/share")->assertNotFound();
    }

    #[Test]
    public function comments_can_be_closed_on_a_post(): void
    {
        Sanctum::actingAs($this->admin());
        $post = $this->publishedPost(['comments_enabled' => false]);

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A comment.'])->assertStatus(409);

        // Reacting is still allowed: closing comments is about a conversation the office cannot
        // moderate, not about refusing acknowledgement.
        $this->postJson("/api/v1/newsfeed/{$post}/reaction")->assertOk();
    }

    #[Test]
    public function a_reply_cannot_be_replied_to(): void
    {
        $post = $this->publishedPost();
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);
        $top = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A question.'])
            ->assertCreated()->json('data.id');

        $reply = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'An answer.',
            'parent_id' => $top,
        ])->assertCreated()->json('data.id');

        // A thread that nests arbitrarily is a thread somebody has to moderate arbitrarily deep.
        $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'A reply to the reply.',
            'parent_id' => $reply,
        ])->assertStatus(409);
    }

    #[Test]
    public function engagement_requires_authentication(): void
    {
        $post = $this->publishedPost();

        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/newsfeed/{$post}/reaction")->assertUnauthorized();
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'x'])->assertUnauthorized();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedPost(array $overrides = []): string
    {
        Sanctum::actingAs($this->admin());

        $post = $this->postJson('/api/v1/admin/newsfeed', $overrides + [
            'body' => 'Relief distribution on Thursday at the barangay hall.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        return $post;
    }
}
