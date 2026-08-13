<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Evidence for TAB 01 acceptance criterion "the backend boots": an unauthenticated
 * request traverses routing, middleware, a module controller and the shared response
 * builder, and comes back as a correctly enveloped JSON payload.
 */
final class HealthEndpointTest extends TestCase
{
    #[Test]
    public function it_reports_liveness_in_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.service', 'taytay-lguids-backend')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonStructure([
                'data' => ['service', 'status', 'api_version'],
                'meta' => ['request_id'],
            ]);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function it_does_not_leak_environment_or_dependency_detail(): void
    {
        $body = $this->getJson('/api/v1/health')->getContent();
        $this->assertIsString($body);

        // A public probe must not become a reconnaissance surface
        // (docs/api/conventions.md §8).
        foreach ([app()->environment(), app()->version(), PHP_VERSION, 'laravel'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase(
                (string) $secret,
                $body,
                'The health endpoint must not disclose environment or dependency detail.'
            );
        }
    }

    #[Test]
    public function it_is_reachable_without_authentication(): void
    {
        // Public access here is an affirmative choice recorded in the route file, not an
        // omission (CLAUDE.md Article 3.5).
        $this->getJson('/api/v1/health')->assertOk();
    }
}
