<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Document history can be added to and never rewritten (ADR 0020 §3).
 *
 * WHY A STRUCTURAL TEST AND NOT ONLY A BEHAVIOURAL ONE. `CaseDocumentTest` proves that today's
 * replacement path appends. It cannot prove that tomorrow's does — the way this guarantee
 * actually dies is somebody adding a tidy-up: a `replaceDocument()` that overwrites in place, a
 * `deleteVersion()` for a mistaken upload, a `forceDelete` in a retention job. Each of those
 * looks reasonable in isolation and each destroys the evidence of what the office saw when it
 * decided.
 *
 * The admin console guards the same invariant on its side with `check:documents`. This is the
 * server half of the same rule, and it is the half that matters, because the console is one of
 * four clients.
 */
final class DocumentHistoryIsAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Methods that would destroy or rewrite history rather than add to it.
     *
     * `update` is absent deliberately: two additive stamps — supersession and a verification
     * decision — are legitimate writes onto an existing row, and banning the verb outright would
     * ban the design. The mass-assignment and deletion verbs below have no such use.
     */
    private const FORBIDDEN = [
        'delete(',
        'forceDelete(',
        'truncate(',
        'destroy(',
        'restore(',
    ];

    #[Test]
    public function nothing_deletes_a_document_version(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file => $source) {
            foreach (self::FORBIDDEN as $verb) {
                if (str_contains($source, $verb) && $this->mentionsVersions($source)) {
                    $offenders[] = $file.' → '.rtrim($verb, '(');
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These touch document versions with a destructive verb:',
            ...$offenders,
            '',
            'The superseded version is the evidence of what the office saw when it decided.',
            'A request approved in March on a certificate replaced in June must still be',
            'explicable in December. Append instead (ADR 0020 §3).',
        ]));
    }

    #[Test]
    public function no_service_offers_a_replace_or_overwrite_operation(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file => $source) {
            // `replacesBecause` / `replaces_because` are the REASON carried on an append and are
            // exactly right; a `function replace` is the operation that must not exist.
            if (preg_match('/function\s+(replace|overwrite)(Document|Version)?\s*\(/i', $source) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These declare a replace/overwrite operation on documents:',
            ...$offenders,
            '',
            'There is no replaceDocument and no deleteDocument, and there must not be.',
            'A replacement is an append that carries a required reason.',
        ]));
    }

    #[Test]
    public function the_versions_table_has_no_updated_at_column(): void
    {
        /*
         * The schema-level statement of the same rule. An `updated_at` on an append-only table
         * is an invitation: it says rows here get edited, and the next person to read the model
         * will believe it.
         */
        $this->assertTrue(Schema::hasColumn('document_versions', 'created_at'));
        $this->assertFalse(Schema::hasColumn('document_versions', 'updated_at'));
    }

    #[Test]
    public function a_superseded_version_keeps_its_own_file(): void
    {
        // Supersession points forward, never sideways: the old row keeps its own stored file, so
        // "what did we actually look at" stays answerable per version.
        $this->assertTrue(Schema::hasColumn('document_versions', 'stored_file_id'));
        $this->assertTrue(Schema::hasColumn('document_versions', 'superseded_at'));
        $this->assertTrue(Schema::hasColumn('document_versions', 'superseded_reason'));
    }

    /**
     * The scan's own negative fixture.
     *
     * A scan that never matched anything would pass every assertion above while proving nothing.
     */
    #[Test]
    public function the_scan_actually_reads_the_document_code(): void
    {
        $files = $this->sourceFiles();

        $this->assertNotEmpty($files);

        $mentioning = array_filter($files, fn (string $source): bool => $this->mentionsVersions($source));

        $this->assertNotEmpty(
            $mentioning,
            'No scanned file mentions DocumentVersion — the scan is looking in the wrong place.',
        );

        /*
         * And that the comment stripping works, using the exact docblock sentence that produced
         * the first false accusation. If this sentence ever reappears in the scanned text, the
         * detector is reading prose again.
         */
        $service = $files['modules/Files/Application/DocumentService.php']
            ?? $files['modules\Files\Application\DocumentService.php']
            ?? null;

        $this->assertNotNull($service, 'DocumentService is not being scanned.');
        $this->assertStringNotContainsString('no `delete()` in this class', $service);
        $this->assertStringContainsString('function append', $service);
    }

    /**
     * The scanned files, with comments and docblocks stripped.
     *
     * WITHOUT THIS THE SCAN READS ITS OWN PROSE. `DocumentService`'s docblock says "there is no
     * `replace()` and no `delete()` in this class", and the first version of this test reported
     * that sentence as a violation — a detector that cannot tell a statement of the rule from a
     * breach of it will eventually be silenced rather than fixed.
     *
     * Tokenised rather than regex-stripped, because a `//` inside a string literal is not a
     * comment and PHP's own tokeniser is the only thing that reliably knows the difference.
     *
     * @return array<string, string> path => code without comments
     */
    private function sourceFiles(): array
    {
        $sources = [];

        foreach ([
            'modules/Files/Application/*.php',
            'modules/Files/Infrastructure/Eloquent/*.php',
            'modules/Files/Jobs/*.php',
            'modules/Welfare/Application/*.php',
            'modules/Welfare/Http/Controllers/V1/*.php',
        ] as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $file) {
                $sources[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)]
                    = self::withoutComments((string) file_get_contents($file));
            }
        }

        return $sources;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    private function mentionsVersions(string $source): bool
    {
        return str_contains($source, 'DocumentVersion') || str_contains($source, 'document_versions');
    }
}
