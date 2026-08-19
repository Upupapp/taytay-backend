<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 18 — the preflight refuses a wrong setting, and never prints a secret to do it.
 *
 * A configuration checker is the one tool nobody runs in anger until a deployment is going wrong,
 * which is the worst moment to discover it reports a pass for everything. So each rule is exercised
 * against the misconfiguration it exists for, rather than against a correct configuration where a
 * check that does nothing and a check that works look identical.
 */
final class PreflightCommandTest extends TestCase
{
    #[Test]
    public function a_wildcard_cors_origin_is_refused(): void
    {
        config(['cors.allowed_origins' => ['*']]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('Any site a caseworker visits')
            ->assertFailed();
    }

    #[Test]
    public function debug_mode_is_refused(): void
    {
        config(['app.debug' => true]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('the connection string to whoever triggered the error')
            ->assertFailed();
    }

    #[Test]
    public function credentialed_cors_is_refused(): void
    {
        config(['app.debug' => false, 'cors.allowed_origins' => ['https://console.example'], 'cors.supports_credentials' => true]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('ADR 0005 chose bearer tokens precisely to avoid this')
            ->assertFailed();
    }

    #[Test]
    public function a_plaintext_allowed_origin_is_refused(): void
    {
        config(['app.debug' => false, 'cors.allowed_origins' => ['http://console.example']]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('is not https')
            ->assertFailed();
    }

    #[Test]
    public function a_per_host_cache_store_fails_because_the_scheduler_would_double_run(): void
    {
        config(['app.debug' => false, 'cache.default' => 'file']);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('a scheduler on two machines runs every task twice')
            ->assertFailed();
    }

    /**
     * A shared credential between the two buckets is the failure the separation exists to prevent.
     *
     * Both are set to the *same* value here — the case that must fail. The unset case is covered
     * below, and the two must not report the same thing.
     */
    #[Test]
    public function two_buckets_sharing_a_credential_are_refused(): void
    {
        config([
            'app.debug' => false,
            'filesystems.disks.object-storage.key' => 'shared-key-value',
            'filesystems.disks.public-media.key' => 'shared-key-value',
            'filesystems.disks.object-storage.bucket' => 'taytay-private',
            'filesystems.disks.public-media.bucket' => 'taytay-public',
        ]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('The two disks share this')
            ->assertFailed();
    }

    /**
     * Unset is reported as unverifiable, and **does not fail** — but is never reported as separated.
     *
     * The distinction is the whole point: an operator reading "the two differ" for two blank
     * variables has been told something untrue about the deployment most likely to be wrong.
     */
    #[Test]
    public function unset_credentials_are_unverifiable_rather_than_separated(): void
    {
        config([
            'app.debug' => false,
            'filesystems.disks.object-storage.key' => null,
            'filesystems.disks.public-media.key' => null,
        ]);

        Artisan::call('lguids:preflight');

        $output = Artisan::output();

        $this->assertStringContainsString('Unset is not separated', $output);
        $this->assertStringNotContainsString(
            'the two differ',
            $output,
            'Two unset variables were reported as separated. That is a verified guarantee printed on exactly the deployment most likely to be missing them.'
        );
    }

    /**
     * *"Never read, print, commit or echo .env values, keys or tokens"* — Article 5.6.
     *
     * A configuration reporter is exactly the tool that ends up printing one, and its output goes
     * into terminal scrollback, a deploy log and a screenshot pasted into a chat.
     */
    #[Test]
    public function no_credential_value_reaches_the_output(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:S3CR3TAPPKEYVALUErz4Fk0mQ2wMxT7uYp9Lc1Nv8Hb6Jd0E=',
            'filesystems.disks.object-storage.key' => 'AKIAPRIVATEBUCKETKEY',
            'filesystems.disks.object-storage.secret' => 'privatebucketsecretvalue',
            'filesystems.disks.public-media.key' => 'AKIAPUBLICBUCKETKEY',
            'filesystems.disks.public-media.secret' => 'publicbucketsecretvalue',
        ]);

        Artisan::call('lguids:preflight', ['--json' => true]);

        $output = Artisan::output();

        foreach ([
            'S3CR3TAPPKEYVALUE',
            'AKIAPRIVATEBUCKETKEY',
            'privatebucketsecretvalue',
            'AKIAPUBLICBUCKETKEY',
            'publicbucketsecretvalue',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $output,
                "The preflight printed {$secret}. Its output reaches terminal scrollback, a deploy log and a screenshot pasted into a chat."
            );
        }

        // And it did report on them, so the absence above is redaction rather than silence.
        $this->assertStringContainsString('object storage keys', $output);
    }

    /** An unverifiable item is not a pass, and the summary must say so in words. */
    #[Test]
    public function host_side_items_are_reported_as_unverifiable_rather_than_passing(): void
    {
        config(['app.debug' => false]);

        $this->artisan('lguids:preflight')
            ->expectsOutputToContain('cannot be seen from this process')
            ->expectsOutputToContain('They are not passes');
    }
}
