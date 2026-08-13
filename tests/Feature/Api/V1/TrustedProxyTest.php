<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Modules\Shared\Providers\SharedServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for a real defect found during the infrastructure alignment.
 *
 * Trusted proxies were first configured with `env('TRUSTED_PROXIES')` inside the
 * `withMiddleware` closure in bootstrap/app.php. That closure runs when the HTTP kernel is
 * resolved — BEFORE Laravel loads the .env file — so the setting read null and silently
 * did nothing. The deployment would have looked configured while the API still treated
 * the load balancer as the client: one rate-limit bucket shared by every citizen, signed
 * URLs generated with the wrong scheme, and every audited action attributed to the proxy.
 *
 * These tests assert behaviour rather than the presence of a config key, because the
 * broken version had the config key too (ADR 0004).
 */
final class TrustedProxyTest extends TestCase
{
    private const PROXY = '203.0.113.9';

    private const REAL_CLIENT = '198.51.100.77';

    protected function tearDown(): void
    {
        // The trusted-proxy list is static on the middleware, so it must not leak into
        // any test that follows.
        TrustProxies::flushState();

        parent::tearDown();
    }

    #[Test]
    public function a_configured_proxy_reveals_the_real_client_and_the_original_scheme(): void
    {
        $this->trustProxies(self::PROXY);

        $this->forwardedRequest()->assertOk();

        $request = $this->app['request'];

        $this->assertSame(self::REAL_CLIENT, $request->ip());
        $this->assertTrue(
            $request->isSecure(),
            'X-Forwarded-Proto must be honoured, or signed URLs are generated with the wrong scheme.'
        );
    }

    #[Test]
    public function an_untrusted_caller_cannot_spoof_its_address(): void
    {
        // Deny by default: nothing configured, so the forwarding headers are ignored.
        $this->trustProxies('');

        $this->forwardedRequest()->assertOk();

        $request = $this->app['request'];

        $this->assertSame(
            self::PROXY,
            $request->ip(),
            'With no trusted proxy, X-Forwarded-For must be ignored — otherwise any caller '
                .'can forge its address and evade rate limiting.'
        );
        $this->assertFalse($request->isSecure());
    }

    #[Test]
    public function a_proxy_outside_the_trusted_list_is_ignored(): void
    {
        $this->trustProxies('10.0.0.1');

        $this->forwardedRequest()->assertOk();

        $this->assertSame(self::PROXY, $this->app['request']->ip());
    }

    #[Test]
    public function several_proxies_may_be_configured(): void
    {
        $this->trustProxies('10.0.0.1, '.self::PROXY);

        $this->forwardedRequest()->assertOk();

        $this->assertSame(self::REAL_CLIENT, $this->app['request']->ip());
    }

    /**
     * Re-runs the provider's boot with the given configuration, which is the seam that
     * actually applies the setting.
     */
    private function trustProxies(string $proxies): void
    {
        TrustProxies::flushState();

        config(['api.trusted_proxies' => $proxies]);

        (new SharedServiceProvider($this->app))->boot();
    }

    private function forwardedRequest(): TestResponse
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => self::REAL_CLIENT,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/api/v1/health');
    }
}
