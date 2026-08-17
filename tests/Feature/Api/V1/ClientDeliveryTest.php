<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * How responses travel to a browser, a CDN and a phone (ADR 0032 §2–§4).
 *
 * Three separate promises, each of which fails silently if it is wrong:
 *
 *  * a private response must never sit in a shared cache;
 *  * a client must be able to learn it is too old **before** it can sign in;
 *  * a browser origin allow-list must never widen to a wildcard while credentials are on.
 */
final class ClientDeliveryTest extends KycTestCase
{
    use RefreshDatabase;

    // ── cache directives ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_authenticated_response_is_never_stored_anywhere(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $response = $this->getJson('/api/v1/me')->assertOk();

        /*
         * `no-store` rather than `no-cache`: the two are routinely confused and only one of them
         * means what is needed here. `no-cache` permits storage and requires revalidation — a
         * resident's welfare file would still be written to a proxy's disk. `no-store` forbids
         * writing it down at all.
         */
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_route_that_declares_nothing_is_private(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * THE DEFAULT IS THE WHOLE POINT. A route added next year with no thought given to caching
         * is private, so the cost of forgetting is a little bandwidth rather than one resident's
         * file being served to the next caller.
         */
        $this->assertStringContainsString(
            'no-store',
            $this->getJson('/api/v1/me/cases')->assertOk()->headers->get('Cache-Control'),
        );
    }

    #[Test]
    public function a_public_read_is_cacheable_only_while_nobody_is_behind_it(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishEvent();

        $this->app['auth']->forgetGuards();

        $anonymous = $this->getJson('/api/v1/events')->assertOk();
        $this->assertStringContainsString('public', $anonymous->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', $anonymous->headers->get('Cache-Control'));

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * THE SAME URL, DOWNGRADED. The events list is genuinely public until a signed-in resident
         * asks for it — at which point the response is about somebody, and a shared cache keyed on
         * the URL alone would hand it to the next caller.
         */
        $this->assertStringContainsString(
            'no-store',
            $this->getJson('/api/v1/events')->assertOk()->headers->get('Cache-Control'),
        );

        // And the detail endpoint behaves the same way, so the rule is not one route deep.
        $this->assertStringContainsString(
            'no-store',
            $this->getJson("/api/v1/events/{$event}")->assertOk()->headers->get('Cache-Control'),
        );
    }

    #[Test]
    public function an_error_response_is_never_cached(): void
    {
        // An error body carries a request id. Replaying one to a different caller would hand them
        // somebody else's correlation id to quote at a support desk.
        $this->assertStringContainsString(
            'no-store',
            $this->getJson('/api/v1/me')->assertUnauthorized()->headers->get('Cache-Control'),
        );
    }

    #[Test]
    public function a_write_is_never_cached(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->assertStringContainsString(
            'no-store',
            $this->postJson('/api/v1/me/notifications/read-all')->headers->get('Cache-Control'),
        );
    }

    // ── app bootstrap ────────────────────────────────────────────────────────────────

    #[Test]
    public function bootstrap_answers_a_client_that_cannot_sign_in(): void
    {
        $this->app['auth']->forgetGuards();

        /*
         * UNAUTHENTICATED BY NECESSITY. An app that cannot start cannot sign in to be told that it
         * should update — a minimum-version gate behind authentication opens only for the clients
         * that did not need it.
         */
        $body = $this->getJson('/api/v1/app/bootstrap', ['X-Client-Channel' => 'citizen-mobile'])
            ->assertOk()->json('data');

        $this->assertSame('v1', $body['api_version']);
        $this->assertSame('citizen-mobile', $body['client']['channel']);
        $this->assertSame(15, $body['client']['default_page_size']);
        $this->assertSame('Idempotency-Key', $body['conventions']['idempotency_header']);
    }

    #[Test]
    public function bootstrap_publishes_server_time_so_nothing_depends_on_the_client_clock(): void
    {
        $body = $this->getJson('/api/v1/app/bootstrap')->assertOk()->json('data');

        /*
         * The master command requires that no critical operation depend on the client clock, and
         * none does — nothing on the server reads a client-supplied time. This is published so a
         * phone with a wrong clock can *notice*, rather than telling somebody an event starting in
         * an hour started yesterday.
         */
        $this->assertNotEmpty($body['server_time']);
        $this->assertSame('Asia/Manila', $body['timezone']);
    }

    #[Test]
    public function a_feature_flag_reports_what_the_module_will_actually_do(): void
    {
        config()->set('credential.enabled', false);
        $this->assertFalse($this->getJson('/api/v1/app/bootstrap')->json('data.features.digital_id'));

        config()->set('credential.enabled', true);
        /*
         * Read indirectly from the config the owning module reads, so this endpoint cannot drift
         * into claiming a feature is on while the module refuses it — and a client that ignored
         * every flag would gain nothing, because each module enforces independently (Article 3.4).
         */
        $this->assertTrue($this->getJson('/api/v1/app/bootstrap')->json('data.features.digital_id'));
    }

    #[Test]
    public function bootstrap_carries_nothing_worth_protecting(): void
    {
        $content = $this->getJson('/api/v1/app/bootstrap')->assertOk()->content();

        // It is served to anybody, so anything in it is public forever.
        foreach (['secret', 'password', 'private_key', 'service_account', 'database', 'APP_KEY'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $content);
        }
    }

    // ── browser origins ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_origin_allow_list_is_never_a_wildcard_with_credentials(): void
    {
        /*
         * THE CLASSIC CORS MISTAKE, and it is one line in a config file away at all times. A
         * wildcard origin with credentials enabled lets any page on the internet make
         * authenticated requests on a signed-in resident's behalf.
         *
         * The master command is explicit: production origins are explicit custom-domain entries,
         * never `*` with credentials and never a blanket `*.netlify.app`.
         */
        // The shipped configuration must be clean.
        $this->assertNull(
            $this->corsDanger(
                (array) config('cors.allowed_origins', []),
                (array) config('cors.allowed_origins_patterns', []),
                config('cors.supports_credentials') === true,
            ),
            'The configured CORS origins are unsafe.',
        );

        /*
         * AND THE RULE MUST REFUSE EACH DANGEROUS SHAPE.
         *
         * These negative fixtures are the point of the test. Written as bare conditionals over
         * the live config — `if (supports_credentials) { assert... }` — every branch was false in
         * the test environment, so this ran ZERO assertions and passed no matter what production
         * shipped. A check that cannot fail is not a check.
         */
        foreach ([
            'a wildcard origin alongside credentials' => [['*'], [], true],
            'an origin pattern alongside credentials' => [[], ['#^https://.+\.example\.com$#'], true],
            'every Netlify deploy preview' => [[], ['#^https://.+\.netlify\.app$#'], false],
        ] as $shape => [$origins, $patterns, $credentials]) {
            $this->assertNotNull(
                $this->corsDanger($origins, $patterns, $credentials),
                "The CORS rule failed to reject {$shape}.",
            );
        }
    }

    /**
     * The rule itself, as a predicate, so it can be pointed both at what ships and at what must
     * be refused. Returns why the shape is dangerous, or null when it is safe.
     *
     * @param  array<int, mixed>  $origins
     * @param  array<int, mixed>  $patterns
     */
    private function corsDanger(array $origins, array $patterns, bool $credentials): ?string
    {
        /*
         * THE CLASSIC CORS MISTAKE, and it is one line in a config file away at all times. A
         * wildcard origin with credentials enabled lets any page on the internet make
         * authenticated requests on a signed-in resident's behalf.
         */
        if ($credentials && in_array('*', $origins, true)) {
            return 'a wildcard origin with credentials enabled is a full CSRF surface';
        }

        if ($credentials && $patterns !== []) {
            return 'an origin pattern with credentials enabled is a wildcard wearing a hat';
        }

        foreach ($patterns as $pattern) {
            /*
             * A deploy preview is ephemeral and anybody can create one on a shared domain. It
             * points at staging; it must never be allowed to speak to production.
             *
             * UNESCAPED FIRST. These are regular expressions, so a real one spells the host
             * `netlify\.app` — a plain `str_contains($pattern, 'netlify.app')' matches the
             * literal string and misses every actual pattern. That was the original check, and
             * the negative fixture below is what exposed it.
             */
            $plain = str_replace('\\', '', (string) $pattern);

            if (str_contains($plain, 'netlify.app')) {
                return 'allowing every *.netlify.app origin allows every Netlify user';
            }
        }

        return null;
    }

    #[Test]
    public function the_origin_allow_list_denies_by_default(): void
    {
        /*
         * Evaluated from the shipped config FILE with nothing in the environment.
         *
         * This previously set `cors.allowed_origins` to `[]` and then asserted it was `[]`,
         * which proved that Laravel's config repository round-trips and nothing about this
         * application. What matters is what `config/cors.php` produces when the operator has
         * configured no origins: a misconfigured deployment must fail closed, not open.
         */
        $restore = [
            '_ENV' => $_ENV['CORS_ALLOWED_ORIGINS'] ?? null,
            '_SERVER' => $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null,
            'getenv' => getenv('CORS_ALLOWED_ORIGINS'),
        ];

        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        putenv('CORS_ALLOWED_ORIGINS');

        try {
            /** @var array<string, mixed> $shipped */
            $shipped = require base_path('config/cors.php');
        } finally {
            if ($restore['_ENV'] !== null) {
                $_ENV['CORS_ALLOWED_ORIGINS'] = $restore['_ENV'];
            }

            if ($restore['_SERVER'] !== null) {
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $restore['_SERVER'];
            }

            if ($restore['getenv'] !== false) {
                putenv('CORS_ALLOWED_ORIGINS='.$restore['getenv']);
            }
        }

        $this->assertSame([], $shipped['allowed_origins'], 'With no origins configured, none may be allowed.');
        $this->assertSame([], $shipped['allowed_origins_patterns'], 'A default origin pattern is a default hole.');
        $this->assertFalse($shipped['supports_credentials'], 'Cookie credentials stay off; auth here is bearer tokens (ADR 0005).');
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function publishEvent(): string
    {
        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        return $event;
    }
}
