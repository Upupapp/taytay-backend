<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exactly one class may write to the public bucket (ADR 0033 §4).
 *
 * `MediaSecurityTest` proves that today's code paths do not put an upload in the public bucket.
 * This proves that **no code path can**, which is a different claim and the one that survives a
 * module added next year by somebody who never read the ADR.
 *
 * The reasoning is the same as `feedback: a capability gate needs one reader`. A rule enforced at
 * every call site is a rule that holds until somebody adds a call site — and the person adding it
 * will be doing something that looks entirely reasonable, like serving a programme's blank form
 * from a CDN.
 *
 * SO THE BUCKET HAS ONE WRITER AND THE WRITER RE-ENCODES. Those two facts together are what make
 * "no uploaded bytes ever reach the public bucket" structural: not "we checked", but "there is
 * nowhere else the write could come from".
 */
final class PublicMediaHasOneWriterTest extends TestCase
{
    /**
     * The one class permitted to name the public disk.
     *
     * `MediaVisibility` is here because the enum maps a visibility to its disk — that is the
     * lookup, not a write. `MediaPublisher` is the writer.
     */
    private const AUTHORISED = [
        'modules/Files/Application/MediaPublisher.php',
        'modules/Files/Domain/MediaVisibility.php',
    ];

    #[Test]
    public function only_the_media_publisher_names_the_public_disk(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $scanned++;
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

            if (in_array($relative, self::AUTHORISED, true)) {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($path));

            /*
             * Comments are stripped with the PHP tokeniser first. Every ADR and docblock in this
             * module discusses the public disk by name, and a detector that matched its own
             * explanation would have to be silenced — at which point somebody silences it wrongly.
             */
            if (str_contains($source, 'public-media') || str_contains($source, 'files.public_disk')) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        /*
         * A walker that found no files would report a spotlessly clean module tree. Every
         * detector in this project asserts its own reach, because a detector that reaches nothing
         * is indistinguishable from a codebase with nothing to find.
         */
        $this->assertGreaterThan(100, $scanned, 'The file walker is broken, not the code.');

        $this->assertSame([], $offenders, implode("\n", [
            'These files name the public media disk:',
            '',
            ...$offenders,
            '',
            'The public bucket has exactly ONE writer, and it writes only bytes it re-encoded from',
            'a decoded pixel buffer. That is what makes "no uploaded object reaches the public',
            'bucket" structural rather than a rule every future caller has to remember.',
            '',
            'If you need something published, call DocumentLibrary::publishMedia() — and if it',
            'refuses, the file is not publishable and the refusal is the point (ADR 0033 §3).',
        ]));
    }

    #[Test]
    public function no_disk_this_application_declares_publishes_a_url_except_the_public_one(): void
    {
        /*
         * Scoped to the disks THIS APPLICATION declares. Laravel merges its own default
         * `filesystems.php`, which ships an `s3` entry this project never asked for and never
         * uses — asserting against that would be testing the framework, and the exception would
         * eventually be widened to cover a real disk.
         */
        $declared = $this->declaredDisks();

        // Same guard: a scan that found nothing would pass without checking a single disk.
        $this->assertContains('object-storage', $declared, 'The disk scan is broken, not the config.');
        $this->assertContains('public-media', $declared);

        foreach ($declared as $name) {
            if ($name === 'public-media' || $name === 'public') {
                continue;
            }

            /*
             * A `url` on a store holding citizen documents is what turns it into a leak: it is
             * the one setting that makes an object reachable without an authorization decision,
             * and it is a single line somebody adds while debugging.
             */
            $this->assertArrayNotHasKey(
                'url',
                (array) config('filesystems.disks.'.$name),
                "Disk [{$name}] publishes a base URL. Private objects are delivered by an ".
                'authorization-gated stream or a short-lived signed URL, never a durable link '.
                '(Article 8.5).',
            );
        }
    }

    #[Test]
    public function the_frameworks_unused_s3_default_is_inert(): void
    {
        /*
         * NOT A DEFECT TODAY, and worth an assertion anyway.
         *
         * Laravel's default config ships an `s3` disk wired to `AWS_*` variables, and this
         * project does not use it — every real store is declared explicitly. But it publishes a
         * base URL from `AWS_URL`, so the day somebody sets those variables for an unrelated
         * reason, this deployment gains a URL-publishing disk nobody reviewed. This says so at
         * the moment it happens rather than afterwards.
         */
        $this->assertSame(
            '',
            (string) config('filesystems.disks.s3.bucket'),
            "The framework's default `s3` disk has been configured. This application declares its ".
            'own stores; if that disk is now in use it needs the same review as the others.',
        );
    }

    #[Test]
    public function the_two_object_stores_are_separate_buckets_with_separate_credentials(): void
    {
        $source = (string) file_get_contents(config_path('filesystems.php'));

        /*
         * Asserted on the ENV KEY NAMES rather than the resolved values, because in a test
         * environment both resolve to null and `assertNotSame(null, null)` fails for a reason
         * that has nothing to do with the invariant.
         *
         * The invariant is that the two disks read different variables. Two buckets with two
         * credentials, not one bucket with a public prefix: the arrangements look equivalent and
         * are not, because a single misapplied policy on a shared bucket exposes everything in it
         * rather than only the images already published (ADR 0033 §4).
         */
        foreach (['BUCKET', 'KEY', 'SECRET'] as $part) {
            $this->assertStringContainsString("OBJECT_STORAGE_{$part}", $source);
            $this->assertStringContainsString("PUBLIC_MEDIA_{$part}", $source);
        }

        $this->assertSame('private', config('filesystems.disks.object-storage.visibility'));
        $this->assertSame('public', config('filesystems.disks.public-media.visibility'));
    }

    /**
     * The disk names this application's own config file declares.
     *
     * @return list<string>
     */
    private function declaredDisks(): array
    {
        $source = (string) file_get_contents(config_path('filesystems.php'));

        preg_match_all("/^        '([a-z0-9-]+)' => \[$/m", $source, $matches);

        return $matches[1];
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
     * The same helper `InfrastructureAlignmentTest` uses, and for the same reason it was added
     * there: a detector that matches its own docblock produces a finding nobody can fix except by
     * rewording an explanation.
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
