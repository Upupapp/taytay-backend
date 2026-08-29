<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Content\Domain\ModerationState;
use Modules\Content\Infrastructure\Eloquent\NewsfeedComment;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * F26 — a resident can report objectionable content.
 *
 * ---
 *
 * **Both app stores require this**, and the newsfeed's comments are the only user-generated
 * content this platform has. The only moderation surface that existed was the staff one, which a
 * resident may not call.
 *
 * Most of what follows is about what a report must *not* do. Accepting one is easy; accepting one
 * without handing residents a veto over each other, and without telling the reporter what other
 * residents have done, is the job.
 */
final class CommentReportingTest extends KycTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_report_changes_nothing_about_the_comment(): void
    {
        [$comment] = $this->commentByAnother();
        [$reporter] = $this->activeCitizenWithResident();

        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'abusive'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'accepted');

        $model = NewsfeedComment::query()->where('uuid', $comment)->firstOrFail();

        /*
         * STILL VISIBLE, and this assertion is the one that caught the first version of this
         * feature. It moved a reported comment to `review-needed` so it would reach the moderation
         * queue — and `visibleComments()` filters on `visible`, so that quietly hid it. One
         * resident reporting another would have removed them from the municipality's own feed.
         */
        $this->assertSame(ModerationState::Visible, $model->moderation_state);
    }

    #[Test]
    public function a_flagged_comment_is_still_readable_by_everybody_else(): void
    {
        [$comment, $post] = $this->commentByAnother();
        [$reporter] = $this->activeCitizenWithResident();

        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'spam'])
            ->assertStatus(202);

        [$reader] = $this->activeCitizenWithResident();
        Sanctum::actingAs($reader);

        // The consequence of "flags, never hides", asserted from the reader's side rather than
        // from the column — because this is the sentence that would quietly stop being true if
        // somebody later made `review-needed` non-public.
        $ids = $this->getJson("/api/v1/newsfeed/{$post}/comments")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($comment, $ids);
    }

    #[Test]
    public function reporting_twice_is_one_report_and_looks_identical(): void
    {
        [$comment] = $this->commentByAnother();
        [$reporter] = $this->activeCitizenWithResident();

        Sanctum::actingAs($reporter);

        $first = $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'abusive'])
            ->assertStatus(202);
        $second = $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'abusive'])
            ->assertStatus(202);

        // A resident tapping a button twice on a slow connection must not put two identical items
        // in front of a human.
        $this->assertSame(1, DB::table('newsfeed_comment_reports')->count());

        // And the second answer must not reveal that there was a first. A "you already reported
        // this" is a state disclosure that only appears to somebody who tried twice.
        //
        // `data`, not the whole body: every response carries its own `meta.request_id`, so
        // comparing raw content asserts that two requests are the same request.
        $this->assertSame($first->json('data'), $second->json('data'));
    }

    #[Test]
    public function the_answer_does_not_change_when_other_residents_have_reported(): void
    {
        [$comment] = $this->commentByAnother();

        $bodies = [];
        foreach (range(1, 3) as $ignored) {
            [$reporter] = $this->activeCitizenWithResident();
            Sanctum::actingAs($reporter);
            $bodies[] = $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'harassment'])
                ->assertStatus(202)
                ->json('data');
        }

        // Three residents, three reports, and none of them learns that the others exist. A count
        // would be an obvious leak; so is any difference at all.
        $this->assertSame(3, DB::table('newsfeed_comment_reports')->count());
        // array_unique stringifies; these are arrays.
        foreach ($bodies as $body) {
            $this->assertSame($bodies[0], $body);
        }
    }

    #[Test]
    public function a_moderators_decision_is_not_overwritten_by_a_later_report(): void
    {
        [$comment] = $this->commentByAnother();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/newsfeed-comments/{$comment}/moderation", [
            'moderation_state' => 'hidden',
            'reason' => 'Abusive language.',
        ])->assertOk();

        [$reporter] = $this->activeCitizenWithResident();
        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'abusive'])
            ->assertStatus(202);

        $model = NewsfeedComment::query()->where('uuid', $comment)->firstOrFail();

        // A comment somebody already removed does not go back into the queue as unreviewed work.
        // The report is still recorded — that people objected is worth knowing — but the state
        // belongs to the moderator.
        $this->assertSame(ModerationState::Hidden, $model->moderation_state);
        $this->assertSame(1, DB::table('newsfeed_comment_reports')->count());
    }

    #[Test]
    public function you_cannot_report_your_own_comment(): void
    {
        [$comment, , $author] = $this->commentByAnother();

        Sanctum::actingAs($author);
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'spam'])
            ->assertStatus(409);

        $this->assertSame(0, DB::table('newsfeed_comment_reports')->count());
    }

    #[Test]
    public function a_guest_cannot_report(): void
    {
        [$comment] = $this->commentByAnother();

        /*
         * `Sanctum::actingAs` in the helper is still in force, so without this the "guest" is the
         * comment's author and the endpoint answers 409 — which the first run of this test
         * accepted as a refusal. A test that passes because it is authenticated as the wrong
         * person has not tested authentication.
         */
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'spam'])
            ->assertUnauthorized();
    }

    // ── the vocabulary ────────────────────────────────────────────────────────────────

    #[Test]
    public function there_is_no_other_and_no_free_text(): void
    {
        [$comment] = $this->commentByAnother();
        [$reporter] = $this->activeCitizenWithResident();

        Sanctum::actingAs($reporter);

        // `other` forces a text box to explain it, and that box is where a resident writes
        // personal data about a neighbour into a municipal record.
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'other'])
            ->assertStatus(422);

        // Nor does anything free-text ride along on a valid one.
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", [
            'reason' => 'abusive',
            'details' => 'My neighbour Juan at 12 Mabini Street is lying about the relief goods.',
            'note' => 'He also owes me money.',
        ])->assertStatus(202);

        foreach ((array) DB::table('newsfeed_comment_reports')->first() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('Juan', $value);
                $this->assertStringNotContainsString('Mabini', $value);
            }
        }
    }

    #[Test]
    public function a_report_is_recorded_in_the_audit_trail_and_not_on_the_comment(): void
    {
        [$comment] = $this->commentByAnother();
        [$reporter] = $this->activeCitizenWithResident();

        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'false-information'])
            ->assertStatus(202);

        $this->assertContains('newsfeed.comment-reported', DB::table('audit_entries')->pluck('action')->all());

        /*
         * Who reported must be answerable when an author complains, and must not be discoverable
         * by anybody reading the feed. So: nothing about reports reaches a reader's projection —
         * not the count, not the reasons, and certainly not the reporter.
         */
        [$post] = [DB::table('newsfeed_posts')->value('uuid')];
        [$reader] = $this->activeCitizenWithResident();
        Sanctum::actingAs($reader);

        $row = $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk()->json('data.0');

        foreach (['report_count', 'report_reasons', 'reporter_subject_id', 'reports'] as $leak) {
            $this->assertArrayNotHasKey($leak, $row, $leak);
        }
    }

    // ── the report has to reach a person ──────────────────────────────────────────────

    #[Test]
    public function the_moderation_queue_can_ask_for_what_residents_reported(): void
    {
        [$reported] = $this->commentByAnother();
        [$untouched] = $this->commentByAnother();

        [$reporter] = $this->activeCitizenWithResident();
        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/newsfeed-comments/{$reported}/reports", ['reason' => 'abusive'])
            ->assertStatus(202);

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        /*
         * The filter has to exist because reporting deliberately changes nothing about the
         * comment. Without it a reported comment is indistinguishable from every other visible
         * one, and the whole feature is a write to a table nobody reads — which is a worse outcome
         * than no report button, because the resident believes they have told the municipality.
         */
        $ids = $this->getJson('/api/v1/admin/newsfeed-comments?reported=true')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$reported], $ids);
        $this->assertNotContains($untouched, $ids);
    }

    #[Test]
    public function a_moderator_sees_how_many_and_why_and_never_who(): void
    {
        [$comment] = $this->commentByAnother();

        $reporters = [];
        foreach (['abusive', 'spam'] as $reason) {
            [$reporter] = $this->activeCitizenWithResident();
            $reporters[] = (string) $reporter->uuid;
            Sanctum::actingAs($reporter);
            $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => $reason])
                ->assertStatus(202);
        }

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $row = $this->getJson('/api/v1/admin/newsfeed-comments?reported=true')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(2, $row['report_count']);

        /*
         * AND THE WHY, WHICH THIS TEST'S NAME ALWAYS CLAIMED AND ITS BODY DID NOT CHECK.
         *
         * `report_reasons` came back null for every row: `reportedComments()` used `withCount`
         * without loading the relation, and `moderatorProjection()` renders the reasons only when
         * it is loaded. The guard made the omission silent — an unloaded relation yields null
         * rather than a per-row query — so a moderator saw a count and no categories, and nothing
         * failed.
         *
         * Sorted before comparing: the projection dedupes with `array_unique` over whatever order
         * the rows arrive in, and asserting an accidental order would make this fail on a database
         * that returns them differently.
         */
        $reasons = $row['report_reasons'];
        sort($reasons);
        $this->assertSame(['abusive', 'spam'], $reasons);

        // A staff screen listing who reported a neighbour is a list somebody eventually reads
        // aloud at a barangay hall. The identities are in the audit trail, where answering "who
        // reported me" is a deliberate act with a record of its own.
        $encoded = json_encode($row);
        foreach ($reporters as $subjectId) {
            $this->assertStringNotContainsString($subjectId, (string) $encoded);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────

    /**
     * A published post carrying one comment written by somebody else.
     *
     * @return array{0: string, 1: string, 2: Account} comment uuid, post uuid, its author
     */
    private function commentByAnother(): array
    {
        $post = $this->publishedPost();

        [$author] = $this->activeCitizenWithResident();
        Sanctum::actingAs($author);

        $comment = $this->postJson("/api/v1/newsfeed/{$post}/comments", [
            'body' => 'This is the comment somebody objects to.',
        ])->assertCreated()->json('data.id');

        return [$comment, $post, $author];
    }

    private function publishedPost(): string
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Relief distribution on Thursday at the barangay hall.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        return $post;
    }
}
