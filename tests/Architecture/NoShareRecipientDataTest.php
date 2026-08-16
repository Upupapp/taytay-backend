<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * This system does not record who anybody shared anything with (ADR 0029 §3).
 *
 * The master command forbids tracking external destinations or personal contacts. Like the
 * location rule in ADR 0022 §1, that is easy to refuse as a feature and easy to acquire as a
 * column — *"which platform do people share to?"* is a reasonable product question, and answering
 * it turns a municipal welfare system into a record of who talks to whom.
 *
 * So the absence is enforced rather than intended. A share row is a counter with a timestamp and
 * an optional account id; anything else is a build failure.
 */
final class NoShareRecipientDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Column-name fragments that would record a destination or a contact.
     */
    private const FORBIDDEN_COLUMNS = [
        'destination', 'recipient', 'platform', 'channel', 'contact', 'phone', 'email',
        'messenger', 'whatsapp', 'facebook', 'target', 'sent_to', 'address', 'message',
    ];

    /**
     * Exactly what a share row may contain. An allow-list rather than only a deny-list, so a
     * column with an innocent name that nonetheless holds a destination still fails.
     */
    private const ALLOWED_COLUMNS = [
        'id', 'newsfeed_post_id', 'subject_id', 'created_at',
    ];

    #[Test]
    public function a_share_records_no_destination_and_no_contact(): void
    {
        $this->assertTrue(Schema::hasTable('newsfeed_shares'), 'The scan is stale.');

        $columns = Schema::getColumnListing('newsfeed_shares');

        $unexpected = array_values(array_diff($columns, self::ALLOWED_COLUMNS));

        $this->assertSame([], $unexpected, implode("\n", [
            'A share row may hold a post, an optional account and a timestamp. These appeared:',
            ...$unexpected,
            '',
            'Recording where a post was shared, or to whom, turns a municipal welfare system into',
            'a record of who talks to whom (ADR 0029 §3).',
        ]));

        foreach ($columns as $column) {
            foreach (self::FORBIDDEN_COLUMNS as $fragment) {
                $this->assertStringNotContainsString($fragment, strtolower($column));
            }
        }
    }

    #[Test]
    public function the_share_contract_accepts_no_destination(): void
    {
        $source = (string) file_get_contents(
            base_path('modules/Content/Http/Controllers/V1/EngagementController.php'),
        );

        $offenders = [];

        foreach (self::FORBIDDEN_COLUMNS as $fragment) {
            // Matches a validation key or an array key, which is how a field would enter the
            // contract. Prose in a docblock is excluded by requiring the quote and the arrow.
            if (preg_match("/['\"][a-z_]*".preg_quote($fragment, '/')."[a-z_]*['\"]\s*=>/i", $source) === 1) {
                $offenders[] = $fragment;
            }
        }

        $this->assertSame([], $offenders, 'The engagement contract accepts: '.implode(', ', $offenders));
    }

    /**
     * The scan's negative fixture.
     *
     * A detector matching nothing would pass both assertions while proving nothing — and this one
     * guards a table that is supposed to be almost empty, which is the quietest possible failure
     * mode.
     */
    #[Test]
    public function the_scan_would_notice_a_destination_column(): void
    {
        $hypotheticals = ['shared_to_platform', 'recipient_phone', 'destination_url', 'contact_email'];
        $matched = [];

        foreach ($hypotheticals as $column) {
            if (array_diff([$column], self::ALLOWED_COLUMNS) !== []) {
                $matched[] = $column;
            }
        }

        $this->assertSame($hypotheticals, $matched, 'The allow-list no longer rejects an obvious destination column.');

        // …and does not reject what a share legitimately holds.
        foreach (['newsfeed_post_id', 'subject_id', 'created_at'] as $legitimate) {
            $this->assertContains($legitimate, self::ALLOWED_COLUMNS);
        }
    }
}
