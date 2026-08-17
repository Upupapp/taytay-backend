<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Shared\Support\CitizenSurface;
use Modules\Shared\Support\OpenApiGenerator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 33, as tests.
 *
 *  1. **A frontend developer can build without reading backend code.**
 *  2. **A breaking response change requires a version or deprecation decision.**
 *  3. **Documented enums match actual backend output.**
 *
 * The third is the one worth the most, and it is the reason the document is generated rather than
 * written: an enum described in prose is a claim, an enum read from `cases()` is the same source
 * the backend serialises from.
 */
final class ApiContractTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 2: the committed document cannot go stale ──────────────────────────

    #[Test]
    public function the_committed_openapi_document_is_current(): void
    {
        /*
         * THE MECHANISM BEHIND "a breaking response change requires a decision".
         *
         * The document is committed, so a change to a response shape produces a **diff a reviewer
         * sees**, in the same commit as the change. Without this test the document would drift
         * silently — and a confidently wrong specification is worse than an absent one, because a
         * frontend developer builds against it and finds out at integration.
         */
        $exit = Artisan::call('lguids:openapi', ['--check' => true]);

        $this->assertSame(
            0,
            $exit,
            "docs/api/openapi.json is stale.\n\n".
            "Run `php artisan lguids:openapi` and READ THE DIFF. If a response shape changed in a\n".
            "way a client would notice, it needs an entry in CHANGELOG_API.md and a decision about\n".
            'whether it is breaking (ADR 0038 §4).',
        );
    }

    #[Test]
    public function the_committed_typescript_contract_is_current(): void
    {
        /*
         * Same mechanism as the OpenAPI check. A frontend developer reads `types.ts` from the
         * repository; if it lags the enums, they retype a value by hand and find out in production.
         */
        $this->assertSame(
            0,
            Artisan::call('lguids:types', ['--check' => true]),
            'docs/api/types.ts is stale. Run: php artisan lguids:types',
        );
    }

    // ── criterion 3: documented enums are the real ones ──────────────────────────────

    #[Test]
    public function every_enum_value_a_client_can_observe_is_documented(): void
    {
        $document = $this->document();
        $documented = [];

        foreach ($document['components']['schemas'] as $name => $schema) {
            foreach ($schema['enum'] ?? [] as $value) {
                $documented[(string) $value] = $name;
            }
        }

        $this->assertNotEmpty($documented, 'No enums were documented at all — the generator is broken.');

        /*
         * REAL RESPONSES, not the enum classes again. Comparing the document to the enums it was
         * generated from would prove only that the generator is deterministic; comparing it to
         * what endpoints actually return is what makes the criterion mean something.
         */
        $observed = $this->observedEnumValues();

        $this->assertNotEmpty($observed, 'The fixture produced no responses to inspect.');

        $missing = array_values(array_filter(
            $observed,
            static fn (string $value): bool => ! array_key_exists($value, $documented),
        ));

        sort($missing);

        $this->assertSame([], $missing, implode("\n", [
            'These values appear in real API responses and in no documented enum:',
            '',
            ...$missing,
            '',
            'A client written against the document would not know to handle them. If the value comes',
            'from a backed enum in Domain/ or Contracts/, the generator picks it up automatically —',
            'if it does not, the value is a bare string somewhere and that is the real finding.',
        ]));
    }

    // ── criterion 1: the document describes the whole surface ────────────────────────

    #[Test]
    public function every_registered_route_appears_in_the_document(): void
    {
        $document = $this->document();
        $documented = [];

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $operation) {
                $documented[] = (string) $operation['operationId'];
            }
        }

        $missing = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1') || $route->getName() === null) {
                continue;
            }

            if (! in_array($route->getName(), $documented, true)) {
                $missing[] = $route->getName();
            }
        }

        // An endpoint cannot be undocumented by being forgotten: the generator walks the router.
        $this->assertSame([], array_unique($missing), implode("\n", array_unique($missing)));
    }

    #[Test]
    public function no_authenticated_route_is_documented_as_public(): void
    {
        /*
         * THE BUG THIS TEST WAS WRITTEN FOR. The first generator matched only the resolved
         * middleware class name, while `gatherMiddleware()` returns the alias — so **every
         * authenticated endpoint in the API was tagged `public`** and carried no security scheme.
         *
         * The document looked complete and was confidently wrong about the single most important
         * thing a client needs to know.
         */
        $document = $this->document();
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $authenticated = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'auth:')) {
                    $authenticated = true;
                }
            }

            if (! $authenticated) {
                continue;
            }

            foreach ($document['paths'] as $operations) {
                foreach ($operations as $operation) {
                    if ($operation['operationId'] !== $name) {
                        continue;
                    }

                    if (in_array('public', $operation['tags'] ?? [], true) || ($operation['security'] ?? []) === []) {
                        $offenders[] = $name;
                    }
                }
            }
        }

        $this->assertSame([], array_unique($offenders), implode("\n", [
            'These routes require authentication and are documented as public:',
            '',
            ...array_unique($offenders),
        ]));
    }

    #[Test]
    public function the_citizen_surface_is_tagged_as_such(): void
    {
        $document = $this->document();
        $tags = [];

        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                $tags[(string) $operation['operationId']] = $operation['tags'][0] ?? null;
            }
        }

        $wrong = [];

        foreach (CitizenSurface::citizenRouteNames() as $name) {
            // A citizen route is `citizen` when authenticated and `public` when not — never
            // `staff`, which would tell a frontend developer to hide it behind a permission.
            if (($tags[$name] ?? null) === 'staff') {
                $wrong[] = $name;
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    #[Test]
    public function the_document_carries_the_conventions_a_client_needs_before_any_path(): void
    {
        $description = (string) $this->document()['info']['description'];

        /*
         * A path list is not a contract. Somebody implementing against this needs to know the
         * envelope, that `code` is the stable field, that money is centavos, and — the one most
         * often got wrong — that a `404` on somebody else's record is deliberate rather than a bug
         * to report.
         */
        foreach (['request_id', 'centavos', 'Idempotency-Key', 'X-Client-Channel', '404'] as $convention) {
            $this->assertStringContainsString($convention, $description, "The preamble omits [{$convention}].");
        }
    }

    #[Test]
    public function every_operation_documents_the_shared_failure_modes(): void
    {
        $document = $this->document();

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                /*
                 * The renderer is global (ADR 0003), so every operation really can produce these.
                 * A client written against one endpoint's error handling works for all of them,
                 * which is the point of a single envelope.
                 */
                foreach (['401', '403', '404', '422', '429', '500'] as $status) {
                    $this->assertArrayHasKey(
                        $status,
                        $operation['responses'],
                        "[{$method} {$path}] does not document a {$status}.",
                    );
                }
            }
        }
    }

    #[Test]
    public function the_rate_limited_endpoints_say_so(): void
    {
        $document = $this->document();
        $limited = [];

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $operation) {
                if (isset($operation['x-rate-limit'])) {
                    $limited[(string) $operation['operationId']] = (string) $operation['x-rate-limit'];
                }
            }
        }

        /*
         * A client that does not know an endpoint is limited retries into the limit and turns a
         * slow moment into a lockout. The named limiter is published so a client can back off
         * intelligently.
         */
        $this->assertSame('kyc-submission', $limited['v1.me.kyc.submit'] ?? null);
        $this->assertSame('event-registration', $limited['v1.events.register'] ?? null);
        $this->assertSame('export', $limited['v1.admin.exports.store'] ?? null);
    }

    // ── the fixture ───────────────────────────────────────────────────────────────────

    /**
     * Enum-shaped values observed in real responses across the surface the master command names.
     *
     * @return list<string>
     */
    private function observedEnumValues(): array
    {
        $bodies = [];

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
            'capacity' => 10,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help with hospital bills.',
            'consent_reference' => 'ack-contract',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'Thank you for the notice.']);

        $case = (string) DB::table('welfare_cases')->value('uuid');
        $registration = (string) DB::table('event_registrations')->value('uuid');

        // The endpoints the master command names, read back as a client would.
        foreach ([
            '/api/v1/me',
            '/api/v1/me/profile',
            '/api/v1/me/household',
            '/api/v1/me/cases',
            '/api/v1/me/cases/'.$case,
            '/api/v1/me/assistance/drafts',
            '/api/v1/programs',
            '/api/v1/services',
            '/api/v1/newsfeed',
            '/api/v1/newsfeed/'.$post,
            '/api/v1/newsfeed/'.$post.'/comments',
            '/api/v1/events',
            '/api/v1/events/'.$event,
            '/api/v1/me/event-registrations',
            '/api/v1/me/event-registrations/'.$registration,
            '/api/v1/me/notifications',
            '/api/v1/me/notification-preferences',
            '/api/v1/privacy/notice',
        ] as $url) {
            $response = $this->getJson($url);

            $this->assertLessThan(500, $response->status(), "[{$url}] failed with {$response->status()}.");

            $bodies[] = (array) $response->json('data');
        }

        return $this->enumShapedValues($bodies);
    }

    /**
     * Values that look like an enum: a short lower-case token, dashed or underscored.
     *
     * A heuristic, and deliberately a narrow one. It must not sweep in free text — a comment body
     * or a venue name would produce hundreds of false findings and the test would be silenced.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function enumShapedValues(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $found = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $found = array_merge($found, $this->enumShapedValues($value, $depth + 1));

                continue;
            }

            if (! is_string($value) || ! is_string($key)) {
                continue;
            }

            /*
             * Only fields whose NAME says they hold a state. Scanning every string would find a
             * category slug, a barangay code and a venue name, none of which is an enum — and the
             * resulting noise is how a detector gets turned off.
             */
            if (! preg_match('/(^|_)(status|state|tier|kind|type|priority|availability|risk|basis|outcome|source|recommendation|urgency|sensitivity|attendance|classification|variant|visibility)$/', $key)) {
                continue;
            }

            if (preg_match('/^[a-z][a-z0-9]*([-_][a-z0-9]+)*$/', $value) === 1) {
                $found[] = $value;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return app(OpenApiGenerator::class)->generate();
    }
}
