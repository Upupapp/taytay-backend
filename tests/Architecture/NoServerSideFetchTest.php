<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing here fetches a URL somebody sent us (ADR 0035 §5, OWASP API7).
 *
 * THE MASTER COMMAND ASKS FOR "strict validation of URLs before any server-side fetch" AND FOR NO
 * GENERIC SSRF-CAPABLE FETCH ENDPOINT. This system satisfies both the easy way: **there is no
 * server-side fetch at all**, so there is no URL to validate strictly.
 *
 * That is worth enforcing rather than merely observing, because the feature that would introduce
 * one always sounds reasonable. "Import a resident photo from a URL." "Check whether this
 * provider's website is up." "Preview the link in this event's `map_url`." Each is one HTTP client
 * call, and each turns this backend — which sits on a private network with a database, a Redis
 * instance and an object store reachable from it — into a proxy that will fetch
 * `http://169.254.169.254/` on request.
 *
 * `map_url` is the live temptation: events store a link to a public map (ADR 0030 §7). It is
 * stored and returned, never fetched. The difference between those is the whole of API7.
 *
 * NOT A BAN ON OUTBOUND HTTP IN GENERAL — FCM is an outbound call to a **fixed, configured**
 * endpoint, which is a different thing from a caller-supplied one. What this forbids is the shape
 * where a URL from a request or a database row becomes the target of a fetch.
 */
final class NoServerSideFetchTest extends TestCase
{
    /**
     * Modules permitted to make outbound HTTP calls at all, to endpoints they configure
     * themselves.
     */
    private const OUTBOUND_ALLOWED = [
        // FCM, over HTTP v1 to a fixed Google endpoint built from configuration (ADR 0025).
        'modules/Notification/',
    ];

    #[Test]
    public function no_module_fetches_a_url_it_was_given(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $scanned++;
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

            $source = $this->withoutComments((string) file_get_contents($path));

            foreach ($this->fetchSignatures() as $label => $needle) {
                if (! str_contains($source, $needle)) {
                    continue;
                }

                if ($this->isAllowed($relative)) {
                    continue;
                }

                $offenders[] = sprintf('%s (%s)', $relative, $label);
            }
        }

        // A walker that found nothing would report a spotless tree.
        $this->assertGreaterThan(100, $scanned, 'The file walker is broken, not the code.');

        sort($offenders);

        $this->assertSame([], array_unique($offenders), implode("\n", [
            'These files make an outbound HTTP request:',
            '',
            ...array_unique($offenders),
            '',
            'This backend sits on a private network with a database, a cache and an object store',
            'reachable from it. A fetch whose target comes from a request or a database row is a',
            'proxy into all three, and the feature that introduces one always sounds reasonable —',
            '"import a photo from a URL", "preview this link" (OWASP API7).',
            '',
            'If an integration genuinely needs an outbound call, it goes to a FIXED endpoint built',
            'from configuration, in a module listed in OUTBOUND_ALLOWED, with an ADR saying why.',
        ]));
    }

    #[Test]
    public function the_scanner_would_notice_a_fetch(): void
    {
        /*
         * THE NEGATIVE FIXTURE. A signature list that matched nothing would pass this suite
         * against a codebase full of `Http::get($request->input('url'))`.
         */
        $planted = [
            'HTTP client' => "<?php class X { function y(\$r) { return Http::get(\$r->input('url')); } }",
            'cURL' => '<?php class X { function y($u) { $c = curl_init($u); } }',
            'stream wrapper' => "<?php class X { function y() { return file_get_contents('http://169.254.169.254/'); } }",
        ];

        foreach ($planted as $label => $source) {
            $this->assertTrue(
                $this->matchesAny($this->withoutComments($source)),
                "The scanner does not recognise a {$label} call.",
            );
        }

        /*
         * AND IT DOES NOT FIRE ON THESE. A scanner that flagged a URL held as data, or a local
         * stream, would be silenced by whoever hit it — and the silencing would let a real fetch
         * through alongside.
         */
        $clean = [
            'a URL as data' => "<?php class X { function y() { return 'https://example.test'; } }",
            'a local stream' => "<?php class X { function y() { return fopen('php://temp', 'r+'); } }",
            'a stored map link' => '<?php class X { function y($e) { return $e->map_url; } }',
        ];

        foreach ($clean as $label => $source) {
            $this->assertFalse($this->matchesAny($this->withoutComments($source)), "False positive on {$label}.");
        }
    }

    #[Test]
    public function the_event_map_url_is_stored_and_never_fetched(): void
    {
        /*
         * The live temptation, named explicitly. Events store a link to a public map, and it is
         * exactly the field a "preview this location" feature would fetch.
         */
        $service = $this->withoutComments(
            (string) file_get_contents(base_path('modules/Events/Application/EventService.php')),
        );

        $this->assertStringContainsString('map_url', $service, 'The field moved; update this test.');
        $this->assertFalse($this->matchesAny($service));
    }

    /**
     * @return array<string, string>
     */
    private function fetchSignatures(): array
    {
        /*
         * NETWORK-ONLY SIGNATURES. `fopen(` is deliberately NOT one of them, and the reason is a
         * false positive this test produced on its first run: `BuildReportExport` opens
         * `php://temp` to assemble a CSV in memory, which is not a fetch by any reading.
         *
         * A signature that flags local stream work gets silenced by whoever hits it next —
         * probably by adding the file to the allow-list, which would then also permit a real
         * fetch from it. A narrow signature that is never wrong is worth more than a broad one
         * with exceptions carved into it.
         *
         * The remote stream wrappers are matched as literals instead, which is what a genuine
         * `fopen`/`file_get_contents` fetch has to contain.
         */
        return [
            'Laravel HTTP client' => 'Http::',
            'cURL' => 'curl_init',
            'Guzzle' => 'GuzzleHttp\\Client',
            'raw socket' => 'fsockopen(',
            'stream socket' => 'stream_socket_client(',
            'remote stream wrapper' => "('http://",
            'remote stream wrapper (tls)' => "('https://",
            'remote stream wrapper (ftp)' => "('ftp://",
        ];
    }

    private function matchesAny(string $source): bool
    {
        foreach ($this->fetchSignatures() as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowed(string $relative): bool
    {
        foreach (self::OUTBOUND_ALLOWED as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
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
