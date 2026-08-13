<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Enforces the provider boundaries in CLAUDE.md Article 8 and ADR 0004.
 *
 * These are the infrastructure equivalents of the module boundary rules: Laravel on
 * Linode/Akamai is the only authority, Netlify delivers browser code, Firebase carries
 * push. Each assertion here is a way that could quietly stop being true — a convenient
 * package, a public bucket, a secret in a browser variable — long before anyone notices
 * in production.
 *
 * Deliberately filesystem/config assertions rather than live checks: this repository must
 * never reach a provider from a test.
 */
final class InfrastructureAlignmentTest extends TestCase
{
    /**
     * Packages that would make Firebase a parallel identity provider or data store.
     * FCM transport libraries are fine; these are not.
     */
    private const FORBIDDEN_PACKAGES = [
        'firebase/php-jwt-auth',
        'kreait/laravel-firebase',
        'kreait/firebase-php',
        'google/cloud-firestore',
        'mongodb/laravel-mongodb',
    ];

    #[Test]
    public function firebase_is_not_introduced_as_a_parallel_authority_or_store(): void
    {
        /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(self::basePath('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $installed = array_keys(($manifest['require'] ?? []) + ($manifest['require-dev'] ?? []));

        foreach (self::FORBIDDEN_PACKAGES as $package) {
            $this->assertNotContains(
                $package,
                $installed,
                "`{$package}` would make Firebase a parallel authority or store. "
                    .'Firebase Auth, Firestore, Realtime Database and Firebase Storage are not used '
                    .'(CLAUDE.md Article 8.3); introducing one requires a new ADR.'
            );
        }
    }

    #[Test]
    public function the_production_object_store_is_private(): void
    {
        $store = config('filesystems.disks.object-storage');

        $this->assertIsArray($store, 'The private production object store must be configured.');

        $this->assertSame('private', $store['visibility'] ?? null);
        $this->assertArrayNotHasKey(
            'url',
            $store,
            'A public base URL turns the private object store into a leak: citizen '
                .'documents must be served through an authorization-gated endpoint or a '
                .'short-lived signed URL (CLAUDE.md Article 8.5).'
        );
        $this->assertTrue(
            $store['throw'] ?? false,
            'A silent write failure on a citizen document surfaces later as missing evidence.'
        );
    }

    #[Test]
    public function no_secret_is_exposed_through_a_browser_build_variable(): void
    {
        $env = (string) file_get_contents(self::basePath('.env.example'));

        // Netlify (and every bundler) exposes these prefixes to the browser at build time.
        foreach (['VITE_', 'NEXT_PUBLIC_', 'REACT_APP_', 'NG_APP_', 'PUBLIC_'] as $prefix) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*#?\s*'.preg_quote($prefix, '/').'/m',
                $env,
                "`{$prefix}` variables are shipped to the browser. This backend must not "
                    .'define them at all (CLAUDE.md Article 8.2).'
            );
        }
    }

    #[Test]
    public function the_service_account_path_is_configured_but_never_its_contents(): void
    {
        $this->assertIsArray(config('services.fcm'));
        $this->assertArrayHasKey('credentials_path', (array) config('services.fcm'));

        // A path is safe to configure; the key material behind it is not.
        $env = (string) file_get_contents(self::basePath('.env.example'));

        foreach (['PRIVATE KEY', 'private_key', 'client_email', 'FCM_CREDENTIALS_JSON'] as $material) {
            $this->assertStringNotContainsString(
                $material,
                $env,
                'Service-account material must live in a file outside the repository, '
                    .'never inline in configuration (CLAUDE.md Article 5.6).'
            );
        }
    }

    #[Test]
    public function trusted_proxies_are_configurable_and_default_to_trusting_nothing(): void
    {
        // Behind Nginx/NodeBalancer, untrusted proxies mean Laravel reads the balancer as
        // the client: rate limiting collapses to one key and audit trails name the proxy.
        $this->assertIsString(config('api.trusted_proxies'));
        $this->assertSame(
            '',
            trim((string) config('api.trusted_proxies')),
            'Trust nothing unless an environment explicitly names its proxies.'
        );

        // The setting must NOT be read in bootstrap/app.php: that closure runs before the
        // .env file is loaded, so env() returns null there and the setting silently does
        // nothing. See tests/Feature/Api/V1/TrustedProxyTest.php.
        $bootstrap = (string) file_get_contents(self::basePath('bootstrap/app.php'));
        $this->assertDoesNotMatchRegularExpression(
            '/env\(\s*[\'"]TRUSTED_PROXIES/',
            $bootstrap,
            'Reading TRUSTED_PROXIES via env() in bootstrap/app.php is a silent no-op.'
        );

        $env = (string) file_get_contents(self::basePath('.env.example'));
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*TRUSTED_PROXIES\s*=\s*\*/m',
            $env,
            'Trusting every proxy by default would let a caller spoof X-Forwarded-For.'
        );
    }

    #[Test]
    public function migrations_stay_portable_postgresql(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn(self::basePath('database/migrations')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // Vendor-specific SQL would tie the schema to one engine and break the
            // "managed PostgreSQL is a deployment choice" property (ADR 0004).
            foreach (['DB::statement', 'DB::raw', 'AUTO_INCREMENT', '->engine', 'ENGINE='] as $vendorism) {
                if (str_contains($source, $vendorism)) {
                    $offenders[] = $file->getFilename().' → '.$vendorism;
                }
            }
        }

        $this->assertSame([], $offenders, "Non-portable migrations:\n".implode("\n", $offenders));
    }

    #[Test]
    public function postgresql_is_configured_for_encrypted_transit(): void
    {
        $pgsql = config('database.connections.pgsql');

        $this->assertIsArray($pgsql, 'PostgreSQL is the canonical production store (ADR 0004).');
        $this->assertArrayHasKey(
            'sslmode',
            $pgsql,
            'Managed PostgreSQL is reached over the network and must be encrypted in transit.'
        );
    }

    #[Test]
    public function sanctum_cookie_spa_mode_is_not_re_enabled_by_default(): void
    {
        $env = (string) file_get_contents(self::basePath('.env.example'));

        // ADR 0005 chose bearer tokens after checking cookie mode against the real
        // domains. Setting stateful domains silently restores the CSRF/CORS surface that
        // decision avoided.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*SANCTUM_STATEFUL_DOMAINS\s*=\s*\S/m',
            $env,
            'Sanctum cookie/SPA mode is deliberately off (ADR 0005).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*SESSION_DOMAIN\s*=\s*\./m',
            $env,
            'A wildcard-subdomain session cookie is exactly what ADR 0005 refused.'
        );
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path !== '' ? DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path) : '');
    }
}
