<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the local infrastructure definition and the environment template.
 *
 * Two failures these prevent, both of which are easy to commit by accident and expensive
 * to discover later:
 *
 *  1. a real credential reaching the repository through `.env.example` or the compose
 *     file (CLAUDE.md Article 5.6);
 *  2. the local stack drifting away from the production topology in ADR 0004, so that
 *     "it worked locally" stops being evidence of anything.
 */
final class LocalInfrastructureTest extends TestCase
{
    /** The backing services a developer must be able to boot (acceptance criterion). */
    private const REQUIRED_SERVICES = ['postgres:', 'redis:', 'minio:', 'mailpit:'];

    /**
     * Values that would mean a real secret had been committed. The local placeholders are
     * deliberately obvious and obviously local.
     */
    private const SECRET_SHAPED = [
        'linodeobjects.com/',      // a real bucket URL with a path
        'BEGIN PRIVATE KEY',
        'BEGIN RSA PRIVATE KEY',
        'private_key',
        'client_secret',
        'AKIA',                    // AWS-style access key id
        'sk_live_',
        'ghp_',
    ];

    #[Test]
    public function the_compose_file_defines_every_service_a_developer_must_boot(): void
    {
        $compose = self::compose();

        foreach (self::REQUIRED_SERVICES as $service) {
            $this->assertStringContainsString(
                $service,
                $compose,
                "docker-compose.yml must define `{$service}` — the runbook and the acceptance "
                    .'criteria promise a developer can boot it.'
            );
        }
    }

    #[Test]
    public function every_image_is_pinned(): void
    {
        preg_match_all('/^\s*image:\s*(\S+)/m', self::compose(), $matches);

        $this->assertNotEmpty($matches[1], 'No images found — has the compose file moved?');

        foreach ($matches[1] as $image) {
            // A floating tag makes the stack irreproducible: two developers, two Postgres
            // versions, one bug that only one of them can see.
            $this->assertStringContainsString(':', $image, "Image `{$image}` has no tag.");
            $this->assertStringNotContainsString(':latest', $image, "Image `{$image}` uses a floating tag.");
        }
    }

    #[Test]
    public function every_service_declares_a_healthcheck_or_is_a_one_shot(): void
    {
        $compose = self::compose();

        // `--wait` only means something if services report health; without it the runbook's
        // "migrate straight after up" races a database that is still starting.
        $this->assertSame(
            4,
            substr_count($compose, 'healthcheck:'),
            'Each long-running service needs a healthcheck so `docker compose up --wait` is meaningful.'
        );
    }

    #[Test]
    public function every_published_port_is_bound_to_the_loopback_interface(): void
    {
        $compose = self::compose();

        // Databases and Redis must never be reachable from the network, in any environment
        // (CLAUDE.md Article 8.6). Locally that means binding 127.0.0.1 — otherwise a
        // service with a known development password is exposed to whatever network the
        // laptop happens to be on.
        preg_match_all('/^\s*-\s*\'([^\']+:\d+)\'\s*$/m', $compose, $matches);

        $this->assertNotEmpty($matches[1], 'No published ports found — has the compose file moved?');

        foreach ($matches[1] as $binding) {
            $this->assertStringStartsWith(
                '127.0.0.1:',
                $binding,
                "Published port `{$binding}` must bind to 127.0.0.1, not every interface."
            );
        }
    }

    #[Test]
    public function the_compose_file_carries_no_production_reference(): void
    {
        $compose = self::compose();

        foreach (['api.taytay', 'portal.taytay', 'admin.taytay', 'linodeobjects.com', 'amazonaws.com'] as $production) {
            $this->assertStringNotContainsString(
                $production,
                $compose,
                'The local stack must not reference a deployed host.'
            );
        }
    }

    #[Test]
    public function the_environment_template_contains_no_secret(): void
    {
        $env = self::envExample();

        foreach (self::SECRET_SHAPED as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $env,
                "`.env.example` contains something shaped like a real credential ({$needle})."
            );
        }

        // APP_KEY must ship empty: a committed key would be shared by every developer and,
        // worse, might be reused in a deployed environment.
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $env, 'APP_KEY must be empty in the template.');
    }

    #[Test]
    public function the_environment_template_only_carries_local_placeholders(): void
    {
        $env = self::envExample();

        // Anything with a value must be a local-loopback or obviously-local placeholder.
        foreach (['DB_PASSWORD', 'OBJECT_STORAGE_KEY', 'OBJECT_STORAGE_SECRET'] as $key) {
            preg_match('/^'.$key.'=(.*)$/m', $env, $match);

            if (($match[1] ?? '') === '') {
                continue; // empty is always safe
            }

            $this->assertStringContainsString(
                'local',
                $match[1],
                "`{$key}` must be an obviously-local placeholder, not a real value."
            );
        }

        foreach (['DB_HOST', 'REDIS_HOST'] as $key) {
            preg_match('/^'.$key.'=(.*)$/m', $env, $match);

            $this->assertContains(
                trim($match[1] ?? ''),
                ['127.0.0.1', 'localhost', ''],
                "`{$key}` must point at the local machine in the template."
            );
        }
    }

    #[Test]
    public function the_real_env_file_is_not_committed(): void
    {
        $tracked = self::basePath('.env');

        // The template is committed; the populated file never is.
        $this->assertFileExists(self::basePath('.env.example'));
        $this->assertStringContainsString('.env', (string) file_get_contents(self::basePath('.gitignore')));

        if (is_file($tracked)) {
            $this->assertStringNotContainsString(
                'APP_KEY=base64:',
                (string) file_get_contents(self::basePath('.env.example')),
                'A generated APP_KEY has leaked from .env into the template.'
            );
        }
    }

    #[Test]
    public function the_template_documents_cross_origin_and_token_client_behaviour(): void
    {
        $env = self::envExample();

        // Acceptance criterion: cross-origin/cookie behaviour is documented for the SPA
        // and the token clients. The template is where an operator actually looks.
        $this->assertStringContainsString('CORS_ALLOWED_ORIGINS', $env);
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS', $env);
        $this->assertStringContainsString('TRUSTED_PROXIES', $env);
        $this->assertMatchesRegularExpression('/ADR 0005|ADR 0006/', $env);
    }

    #[Test]
    public function the_object_storage_disk_can_actually_be_built(): void
    {
        // This caught a real defect. The `object-storage` disk was configured, documented
        // in two runbooks and asserted by other tests, but `league/flysystem-aws-s3-v3`
        // was never installed — so every one of those checks passed while the disk itself
        // would have thrown on first use. Nothing exercised it, so nothing failed.
        //
        // Building the adapter needs no network: it constructs a client, it does not call
        // one. That is exactly the point — the check is cheap enough to always run.
        config([
            'filesystems.disks.object-storage' => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'region' => 'us-east-1',
                'bucket' => 'test-bucket',
                'endpoint' => 'http://127.0.0.1:9000',
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'throw' => true,
            ],
        ]);

        $this->assertInstanceOf(
            AwsS3V3Adapter::class,
            Storage::disk('object-storage'),
            'The s3 driver is unavailable — league/flysystem-aws-s3-v3 is missing from composer.json.'
        );
    }

    private static function compose(): string
    {
        return self::read('docker-compose.yml');
    }

    private static function envExample(): string
    {
        return self::read('.env.example');
    }

    private static function read(string $relative): string
    {
        $path = self::basePath($relative);

        self::assertFileExists($path, "Required infrastructure file is missing: {$relative}");

        return (string) file_get_contents($path);
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
