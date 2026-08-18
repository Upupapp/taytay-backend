<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Http\ApiResponse;
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

    // ── criterion 3, for the error vocabulary specifically ───────────────────────────

    #[Test]
    public function every_error_code_the_api_emits_is_published_with_the_value_it_emits(): void
    {
        /*
         * WHY THIS TEST EXISTS, AND WHY IT IS SEPARATE FROM THE ONE ABOVE.
         *
         * `every_enum_value_a_client_can_observe_is_documented` already compares the document to
         * real responses — but it only ever drives **successful** ones, so no error body was ever
         * inspected. Both generators published the PHP case name (`ValidationFailed`) while the
         * wire has always carried the backing value (`VALIDATION_FAILED`), and CI stayed green
         * for the whole life of the defect: `lguids:openapi --check` and `lguids:types --check`
         * compare the generated document to the committed one, so they verify **currency, never
         * correctness**, and they agree with each other whichever string the generator picks.
         *
         * Every client that did what conventions.md §4 instructs — "clients branch on this" —
         * matched nothing, ever, and its error handling silently never fired.
         *
         * So the observed side of this assertion never mentions `->value` or `->name`. It reads
         * `error.code` out of response bodies produced by the same renderer production uses. A
         * generator that goes back to `->name` makes this test red; a test written against
         * `ErrorCode::cases()` would have restated the bug instead of catching it.
         */
        $published = $this->publishedErrorCodes();
        $union = $this->publishedTypeScriptErrorCodes();

        // Half one: genuine HTTP round-trips, which prove the renderer under test is the one the
        // router actually reaches. These four need no fixture beyond a token.
        $overTheWire = [];

        $overTheWire[] = $this->getJson('/api/v1/me')
            ->assertUnauthorized()->json('error.code');

        $overTheWire[] = $this->postJson('/api/v1/auth/tokens', [])
            ->assertStatus(422)->json('error.code');

        $overTheWire[] = $this->deleteJson('/api/v1/health')
            ->assertStatus(405)->json('error.code');

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $overTheWire[] = $this->getJson('/api/v1/admin/residents/'.Str::uuid()->toString())
            ->assertNotFound()->json('error.code');

        foreach ($overTheWire as $code) {
            $this->assertIsString($code, 'An error response carried no `error.code` at all.');
            $this->assertContains($code, $published, sprintf(
                'The API returned `%s` over HTTP and openapi.json does not publish it. Published: %s',
                $code,
                implode(', ', $published),
            ));
            $this->assertContains($code, $union, sprintf(
                'The API returned `%s` over HTTP and types.ts does not declare it.',
                $code,
            ));
        }

        // Half two: exhaustive. The four above are the codes a test can provoke cheaply; the
        // remaining nine are reachable only from conditions a feature test cannot stage (a dead
        // dependency, a 500, an over-large upload). Rendering each through `ApiResponse::error()`
        // — the single builder every endpoint is required to use — reads the same wire the
        // router would have written, without asserting anything about how the enum spells itself.
        $emitted = [];

        foreach (ErrorCode::cases() as $case) {
            $body = json_decode(ApiResponse::error($case)->getContent() ?: '', true);

            $this->assertIsArray($body);
            $this->assertIsString($body['error']['code'] ?? null);

            $emitted[] = $body['error']['code'];
        }

        sort($emitted);
        sort($published);
        sort($union);

        $this->assertSame($emitted, $published, implode("\n", [
            'openapi.json does not publish the error vocabulary the API emits.',
            '',
            '  emitted on the wire: '.implode(', ', $emitted),
            '  published:           '.implode(', ', $published),
            '',
            'A client instructed to branch on `code` would match nothing. Check that',
            'OpenApiGenerator::schemas() maps ErrorCode with ->value, not ->name.',
        ]));

        $this->assertSame($emitted, $union, implode("\n", [
            'docs/api/types.ts does not declare the error vocabulary the API emits.',
            '',
            '  emitted on the wire: '.implode(', ', $emitted),
            '  declared:            '.implode(', ', $union),
            '',
            'Check GenerateTypesCommand::render() — the union must be built from ->value.',
        ]));
    }

    /**
     * The error codes the committed OpenAPI document publishes.
     *
     * @return list<string>
     */
    private function publishedErrorCodes(): array
    {
        $enum = $this->document()['components']['schemas']['Error']['properties']['error']['properties']['code']['enum'] ?? null;

        $this->assertIsArray($enum, 'openapi.json publishes no error code enum at all.');

        return array_map(strval(...), $enum);
    }

    /**
     * The error codes the committed TypeScript contract declares.
     *
     * Parsed from the emitted file rather than regenerated, because a consumer vendoring
     * `types.ts` reads exactly these bytes — TAB 06 turns that into a build-time guarantee.
     *
     * @return list<string>
     */
    private function publishedTypeScriptErrorCodes(): array
    {
        $source = (string) file_get_contents(base_path('docs/api/types.ts'));

        $this->assertMatchesRegularExpression('/export type ApiErrorCode =/', $source);

        $union = (string) preg_replace('/.*export type ApiErrorCode =(.*?);.*/s', '$1', $source);

        preg_match_all("/'([^']+)'/", $union, $matches);

        return $matches[1];
    }

    #[Test]
    public function a_client_can_learn_what_a_payload_contains_without_reading_php(): void
    {
        /*
         * CRITERION 1, ASSERTED FOR THE FIRST TIME.
         *
         * This class has always claimed "a frontend developer can build without reading backend
         * code", and until TAB 05 nothing checked the part that matters most. The document
         * published 221 paths and 56 schemas, 52 of them enums, and **not one resource shape**:
         * every response declared `data` as an untyped object. Four client teams were each opening
         * this repository to find out what a resident looks like.
         *
         * The shapes are now read from the same `*Projection()` methods that build the payload —
         * the same reasoning as reading enums from `cases()`. This test is what stops a response
         * quietly going back to being undescribed.
         */
        $document = $this->document();
        $described = 0;

        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['responses'] ?? [] as $response) {
                    $data = $response['content']['application/json']['schema']['properties']['data'] ?? null;

                    if ($data === null) {
                        continue;
                    }

                    if (isset(($data['items'] ?? $data)['properties'])) {
                        $described++;
                    }
                }
            }
        }

        $this->assertGreaterThan(150, $described, implode("\n", [
            'Almost no response describes what it returns.',
            '',
            'A client generating from this document would receive the envelope, the error',
            'vocabulary and the enums, and nothing about what comes back inside `data` — which is',
            'the whole of criterion 1. Check OpenApiGenerator::payloadShape().',
        ]));
    }

    #[Test]
    public function a_described_payload_carries_the_fields_it_inherits(): void
    {
        /*
         * A detail projection is routinely `listProjection($x) + [ … ]`. Reading only its own
         * literal keys publishes the extras and drops the base — a payload described as ten
         * fields when it carries twenty-one. A confidently partial shape is worse than an absent
         * one, because a client trusts it and meets the missing half at runtime.
         */
        $detail = $this->document()['paths']['/admin/assistance-requests/{case}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'] ?? [];

        $this->assertArrayHasKey('case_number', $detail, 'The inherited list fields were dropped.');
        $this->assertArrayHasKey('available_transitions', $detail, 'The detail-only fields were dropped.');
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
