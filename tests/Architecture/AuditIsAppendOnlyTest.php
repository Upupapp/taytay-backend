<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trail is written once and never rewritten (Article 5.4, ADR 0034 §1).
 *
 * "Audit records are append-only and are never edited or deleted" is a sentence in the
 * constitution. This is what makes it a property of the code rather than an aspiration, and the
 * two failure modes it guards are different:
 *
 *  * **a second writer** — the state before this TAB, where ten modules each hand-rolled the
 *    insert and had begun to diverge. A trail missing a field is invisible, because it looks like
 *    a trail of a quiet week;
 *  * **a rewrite** — `$entry->update([...])` is one autocomplete away on any Eloquent model, and a
 *    corrected audit trail is not an audit trail.
 */
final class AuditIsAppendOnlyTest extends TestCase
{
    /** The one class allowed to insert into `audit_entries`. */
    private const AUTHORISED_WRITER = 'modules/Audit/Application/AuditTrail.php';

    #[Test]
    public function only_the_audit_trail_writes_to_the_table(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $scanned++;
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

            if ($relative === self::AUTHORISED_WRITER) {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($path));

            if (str_contains($source, "DB::table('audit_entries')")) {
                $offenders[] = $relative;
            }
        }

        // A walker that found nothing would report a spotless tree.
        $this->assertGreaterThan(100, $scanned, 'The file walker is broken, not the code.');

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'These files write to `audit_entries` directly:',
            '',
            ...$offenders,
            '',
            'There is one writer. Depend on `Modules\Shared\Contracts\AuditWriter` and call',
            '`record()` — which is also what applies the redaction rules, the risk classification',
            'and the network-capture policy that a hand-rolled insert would silently skip.',
        ]));
    }

    #[Test]
    public function nothing_updates_or_deletes_an_audit_entry(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            /*
             * Any mutation of the table by any route. `AuditEntry` is guarded against
             * mass-assignment and has no timestamps, but a query-builder update bypasses the model
             * entirely — and that is the shape a "tidy up the audit log" task would take.
             */
            foreach (["audit_entries')->update", "audit_entries')->delete", "audit_entries')->truncate"] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
                }
            }
        }

        $this->assertSame([], array_unique($offenders), implode("\n", [
            'These files mutate the audit trail:',
            '',
            ...array_unique($offenders),
            '',
            'Article 5.4: audit records are append-only and are never edited or deleted. A record',
            'that was wrong is corrected by a NEW entry saying so, not by changing the old one —',
            'a trail that can be tidied is a trail nobody can rely on.',
        ]));
    }

    #[Test]
    public function the_audit_model_is_not_writable(): void
    {
        $source = (string) file_get_contents(base_path('modules/Audit/Infrastructure/Eloquent/AuditEntry.php'));

        // Guarded against everything, so no mass-assignment path exists even for a caller who
        // reaches the model directly.
        $this->assertStringContainsString("protected \$guarded = ['*'];", $source);
        $this->assertStringContainsString('public $timestamps = false;', $source);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Source with every comment removed, via the PHP tokeniser.
     *
     * Every docblock in the Audit module discusses `audit_entries` by name, and a detector that
     * matched its own explanation would have to be silenced.
     */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
