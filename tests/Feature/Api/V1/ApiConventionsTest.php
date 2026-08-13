<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Locks the response and error contract from docs/api/conventions.md and ADR 0003.
 *
 * These assertions exist because four independently released clients — one of them an
 * installed mobile build that cannot be patched on demand — depend on this shape.
 */
final class ApiConventionsTest extends TestCase
{
    #[Test]
    public function an_unknown_route_returns_the_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    #[Test]
    public function a_wrong_http_method_returns_method_not_allowed(): void
    {
        $this->postJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    #[Test]
    public function an_unauthenticated_protected_route_returns_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/services')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    #[Test]
    public function success_and_error_envelopes_are_mutually_exclusive(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonMissingPath('error');

        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonMissingPath('data');
    }

    #[Test]
    public function it_echoes_a_well_formed_client_request_id(): void
    {
        $requestId = 'client-correlation-0001';

        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $requestId]);

        $response->assertOk()->assertJsonPath('meta.request_id', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function it_replaces_a_malformed_client_request_id_rather_than_reflecting_it(): void
    {
        // An unvalidated header would be reflected into logs and response headers.
        $malformed = "<script>alert('x')</script>";

        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $malformed]);

        $generated = $response->assertOk()->json('meta.request_id');

        $this->assertIsString($generated);
        $this->assertNotSame($malformed, $generated);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]+$/', $generated);
        $this->assertSame($generated, $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function the_error_request_id_matches_the_response_header(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('error.request_id'),
            'A citizen quoting the id from an error body must lead staff to the same request.'
        );
    }

    #[Test]
    public function it_answers_json_even_when_the_client_asks_for_html(): void
    {
        $response = $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html']);

        $response->assertNotFound();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function error_messages_do_not_leak_internals(): void
    {
        $body = (string) $this->getJson('/api/v1/does-not-exist')->getContent();

        foreach (['Illuminate\\', 'vendor/', 'SQL', 'Stack trace'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }
}
