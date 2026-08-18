<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * A reader must be able to tell which comments are theirs WITHOUT being told who wrote the others.
 *
 * `author_subject_id` answers the first question by disclosing every author's stable account
 * identifier to everybody reading a public thread — which lets any reader correlate one person's
 * comments across the whole feed. On a welfare newsfeed that is a profile (Article 5.2).
 *
 * `is_mine` is the minimum that serves the actual client need: whether to offer edit and delete on
 * this row. The old field is retained because removing one is a breaking change, and the changelog
 * records the disclosure so its removal is scheduled rather than forgotten.
 */
final class CommentOwnershipTest extends KycTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_reader_can_tell_their_own_comment_from_somebody_elses(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $post = $this->publishedPost();

        [$mine] = $this->activeCitizenWithResident();
        [$theirs] = $this->activeCitizenWithResident();

        Sanctum::actingAs($theirs);
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'Somebody else asked this.'])
            ->assertCreated();

        Sanctum::actingAs($mine);
        $ownId = $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'And this one is mine.'])
            ->assertCreated()
            // The author sees their OWN comment come back marked as theirs.
            ->assertJsonPath('data.is_mine', true)
            ->json('data.id');

        $rows = $this->getJson("/api/v1/newsfeed/{$post}/comments")->assertOk()->json('data');

        $flags = [];

        foreach ($rows as $row) {
            $flags[$row['id']] = $row['is_mine'];
        }

        $this->assertCount(2, $flags);
        $this->assertTrue($flags[$ownId], 'The reader\'s own comment was not marked as theirs.');
        $this->assertSame(
            1,
            count(array_filter($flags)),
            'Exactly one comment on this thread belongs to the reader.',
        );
    }

    #[Test]
    public function another_readers_comment_is_not_marked_as_theirs(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $post = $this->publishedPost();

        [$author] = $this->activeCitizenWithResident();
        Sanctum::actingAs($author);
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'A question.'])->assertCreated();

        // A DIFFERENT reader opens the same thread.
        [$stranger] = $this->activeCitizenWithResident();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/newsfeed/{$post}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.is_mine', false);
    }

    private function publishedPost(): string
    {
        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published']);

        return (string) $post;
    }
}
