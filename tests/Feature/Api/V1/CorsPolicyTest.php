<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API must not advertise itself to every origin on the internet.
 *
 * Laravel ships `allowed_origins => ['*']`; config/cors.php overrides that with an
 * environment-driven allow-list because this API holds personal data (RA 10173) and has a
 * known, small set of first-party browser clients.
 */
final class CorsPolicyTest extends TestCase
{
    #[Test]
    public function the_origin_allow_list_is_never_a_wildcard(): void
    {
        $this->assertNotContains(
            '*',
            (array) config('cors.allowed_origins'),
            'A wildcard origin must never be configured for an API serving personal data.'
        );

        $this->assertSame([], (array) config('cors.allowed_origins_patterns'));
    }

    #[Test]
    public function it_denies_cross_origin_browser_requests_by_default(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://attacker.example']);

        $response->assertOk();
        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'With no configured origins, no cross-origin browser request may be permitted.'
        );
    }

    #[Test]
    public function it_permits_a_configured_first_party_origin(): void
    {
        config(['cors.allowed_origins' => ['https://portal.taytayrizal.gov.ph']]);

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://portal.taytayrizal.gov.ph']);

        $response->assertOk();
        $this->assertSame(
            'https://portal.taytayrizal.gov.ph',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    #[Test]
    public function an_unlisted_origin_is_never_echoed_back(): void
    {
        config(['cors.allowed_origins' => [
            'https://portal.taytayrizal.gov.ph',
            'https://admin.taytayrizal.gov.ph',
        ]]);

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://attacker.example']);

        // With several allowed origins the header is negotiated per request, so an
        // unlisted origin gets no grant at all.
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function a_single_allowed_origin_is_emitted_statically_and_never_reflects_the_caller(): void
    {
        config(['cors.allowed_origins' => ['https://portal.taytayrizal.gov.ph']]);

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://attacker.example']);

        // When exactly one origin is configured the header is static, so the browser —
        // which compares it against its own origin — blocks the attacker. What matters is
        // that the caller's origin is never reflected back, which would grant it access.
        $this->assertNotSame(
            'https://attacker.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'Reflecting the requesting origin would defeat the allow-list entirely.'
        );
        $this->assertSame(
            'https://portal.taytayrizal.gov.ph',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    #[Test]
    public function credentialed_cross_origin_requests_are_not_enabled(): void
    {
        // Cookie-mode auth combined with a broad origin list is the classic CORS mistake.
        $this->assertFalse((bool) config('cors.supports_credentials'));
    }

    #[Test]
    public function the_correlation_header_is_readable_by_browser_clients(): void
    {
        config(['cors.allowed_origins' => ['https://portal.taytayrizal.gov.ph']]);

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://portal.taytayrizal.gov.ph']);

        // A citizen must be able to read the id their browser client shows them.
        $this->assertStringContainsString(
            'X-Request-Id',
            (string) $response->headers->get('Access-Control-Expose-Headers')
        );
    }
}
