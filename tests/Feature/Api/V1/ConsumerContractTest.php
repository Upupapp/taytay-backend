<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Notification\Application\Notifier;
use PHPUnit\Framework\Attributes\Test;

/**
 * PROVIDER VERIFICATION — the other half of TAB 06.
 *
 * The console vendors this API's generated types and fails its own build when they drift. That
 * protects the console from *this* repository, and protects this repository from nothing. This
 * test is the reciprocal: it replays what the console says it reads against the real router, so
 * that dropping a field here fails a build here — in the same commit as the change, by the
 * developer who made it, rather than in front of a caseworker.
 *
 * ── WHY THE EXPECTATIONS ARE NOT WRITTEN DOWN HERE ───────────────────────────────────
 *
 * `docs/api/consumers/taytay-admin-web.json` is **generated in the console** from its mappers —
 * from the `field(wire, '…')` reads and the null-guards that decide whether a record survives —
 * and vendored here with its provenance. Nobody hand-maintains it on either side.
 *
 * That matters more than it sounds. A hand-written expectation file is a third description of the
 * API, sitting beside the mapper and the controller, and every divergence this integration has
 * found (D1–D8, L-01 through L-21) had exactly that shape: two descriptions of one thing, drifting
 * quietly, discovered late. A file derived from the code that does the reading cannot describe
 * something the console does not actually do.
 *
 * ── WHAT "REQUIRED" MEANS, AND WHY IT IS THE FAILURE WORTH GATING ────────────────────
 *
 * A required field is one whose absence makes the console's mapper return `null`. The record is
 * **dropped** — no error, no empty state, no log. The list is simply shorter, and nothing on the
 * screen says a resident is missing.
 *
 * L-15 was this exactly. `barangay_id` is required by `toResident`, and the API sends it as the
 * integer `2`; the mapper wanted a string, rejected every row, and a resident list would have
 * rendered empty against a healthy API and a green test suite in both repositories. This test is
 * the gate that would have caught it, so it checks the **value** as well as the key: a required
 * field present as `null` is the same silent drop as one that is absent.
 *
 * ── EVERY FIXTURE IS BUILT THROUGH THE API ───────────────────────────────────────────
 *
 * Nothing here inserts a row. Records are created by calling the same endpoints a client calls,
 * so a payload verified here came out of the real controller, the real policy and the real
 * serialiser. A test that seeded Eloquent directly could prove a response shape that no reachable
 * code path can actually produce.
 *
 * ── WHAT IT DOES NOT PROVE ───────────────────────────────────────────────────────────
 *
 * The console's *optional* fields are reported and not enforced: their loss degrades a screen
 * rather than emptying it, and gating on them would freeze every field this API has ever
 * published. And this runs against SQLite in CI like the rest of the suite, so it says nothing
 * about PostgreSQL-specific serialisation.
 */
final class ConsumerContractTest extends KycTestCase
{
    use RefreshDatabase;

    /**
     * Every consumer whose expectations are vendored here, discovered from the directory rather
     * than listed.
     *
     * **Four clients consume this API** (Article 0): citizen web, citizen mobile, the admin
     * console and verifier devices. Only the admin console has published expectations, so only
     * the admin console is verified — the other three could each lose a field they depend on and
     * this suite would stay green.
     *
     * Discovering the list from disk rather than naming it here means adding one of them is a
     * data change: drop the generated file and its provenance beside this one. Naming a constant
     * would have made a second consumer look like a code change and quietly discouraged it.
     *
     * @return list<string>
     */
    private function consumers(): array
    {
        $names = [];

        foreach (glob(base_path('docs/api/consumers/*.json')) ?: [] as $file) {
            $name = basename($file, '.json');

            if (! str_ends_with($name, '.source')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    #[Test]
    public function the_vendored_consumer_expectations_are_what_each_client_published(): void
    {
        $this->assertNotEmpty($this->consumers(), 'No consumer has published expectations, so nothing is verified.');

        foreach ($this->consumers() as $consumer) {
            $source = $this->provenance($consumer);

            foreach (['repository', 'commit', 'sha256', 'vendoredOn'] as $key) {
                $this->assertArrayHasKey($key, $source, "{$consumer} provenance is missing \"{$key}\".");
            }

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $source['commit'],
                "{$consumer} provenance must record a full commit SHA; a short one is ambiguous across repositories.",
            );

            $this->assertSame(
                $source['sha256'],
                hash_file('sha256', $this->expectationsPath($consumer)),
                "docs/api/consumers/{$consumer}.json does not match its recorded sha256.\n\n".
                "It is a vendored artefact generated in the client repository. Do not edit it here to\n".
                'make this test pass — re-vendor it, and read what changed.',
            );
        }
    }

    #[Test]
    public function every_route_a_client_calls_exists_on_this_router(): void
    {
        $missing = [];

        foreach ($this->consumers() as $consumer) {
            foreach ($this->interactions($consumer) as $interaction) {
                if ($this->matchRoute($interaction['method'], $interaction['path']) === null) {
                    $missing[] = "{$interaction['method']} api/v1/{$interaction['path']}  ({$consumer}: {$interaction['consumer']})";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "A client calls routes this API does not serve:\n\n  ".implode("\n  ", $missing)."\n\n".
            "Either the route was renamed — which is a breaking change needing a CHANGELOG_API entry\n".
            'and a deprecation decision (ADR 0038 §4) — or the console is calling something it invented.',
        );
    }

    /**
     * The test that earns the file: a live response must carry every field a client cannot render
     * a record without.
     */
    #[Test]
    public function every_field_a_client_requires_arrives_in_a_real_response(): void
    {
        $failures = [];
        $verified = 0;
        $expected = 0;

        foreach ($this->consumers() as $consumer) {
            foreach ($this->interactions($consumer) as $interaction) {
                $expected++;

                $record = $this->sampleRecordFor($interaction['path']);

                /*
                 * An interaction with no reachable sample is NOT quietly skipped. "Nothing to
                 * check" reads as "checked and fine" in a green suite, which is the precise
                 * failure this whole TAB exists to stop.
                 */
                if ($record === null) {
                    $failures[] = "{$consumer} {$interaction['path']}: no sample record could be produced, so nothing was verified.";

                    continue;
                }

                $verified++;

                foreach ($interaction['required'] as $field) {
                    if (! array_key_exists($field, $record)) {
                        $failures[] = "{$consumer} {$interaction['path']}: required field \"{$field}\" is absent from the response.";

                        continue;
                    }

                    if ($record[$field] === null) {
                        $failures[] = "{$consumer} {$interaction['path']}: required field \"{$field}\" arrived as null.";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "A client drops the record when one of these is missing — silently, with no error and\n".
            "no empty state. The list is simply shorter and nothing on the screen says so.\n\n  ".
            implode("\n  ", $failures)."\n\n".
            "If a field genuinely had to go, it is a breaking change: CHANGELOG_API.md, a\n".
            'deprecation decision, and the client repointed before this is made to pass.',
        );

        $this->assertSame($expected, $verified, 'Fewer interactions were verified than clients published.');
    }

    // ── the vendored document ────────────────────────────────────────────────────────

    private function expectationsPath(string $consumer): string
    {
        return base_path('docs/api/consumers/'.$consumer.'.json');
    }

    /** @return array<string, mixed> */
    private function provenance(string $consumer): array
    {
        return json_decode(
            (string) file_get_contents(base_path('docs/api/consumers/'.$consumer.'.source.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<array{method: string, path: string, consumer: string, required: list<string>}> */
    private function interactions(string $consumer): array
    {
        $document = json_decode(
            (string) file_get_contents($this->expectationsPath($consumer)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $document['interactions'];
    }

    private function matchRoute(string $method, string $path): ?string
    {
        // `{resident}` in the expectations is Laravel's own placeholder syntax, so the comparison
        // is exact rather than a pattern match — a route renamed to `{id}` is a real difference.
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === "api/v1/{$path}" && in_array($method, $route->methods(), true)) {
                return $route->getName();
            }
        }

        return null;
    }

    // ── fixtures, all built by calling the API ───────────────────────────────────────

    /**
     * One record from the endpoint, shaped as the console's mapper would receive it: a list row
     * for a collection, the object itself for a detail read.
     *
     * @return array<string, mixed>|null
     */
    private function sampleRecordFor(string $path): ?array
    {
        /*
         * FIXTURES ARE BUILT BY ONE ACTOR AND READ BY ANOTHER, and that is the design working
         * rather than an inconvenience. The Data Protection Officer may read the audit trail and
         * may register nobody — `Role::DataProtectionOfficer` holds no operational permission at
         * all, deliberately. An actor able to do both would be the control failure the role
         * exists to prevent.
         */
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->registerResident();

        // Created for the list read as well as the detail one: a collection with no rows hands
        // the mapper nothing, and an empty page would satisfy every assertion below without
        // proving a single field.
        $household = str_starts_with($path, 'admin/households') ? $this->registerHousehold($resident) : null;
        $visit = str_starts_with($path, 'admin/visits') ? $this->scheduleVisit($resident) : null;

        $reader = $this->actorWhoMaySee($path);

        $concrete = match ($path) {
            'admin/residents/{resident}' => 'admin/residents/'.$resident,
            'admin/households/{household}' => 'admin/households/'.$household,
            'admin/visits/{visit}' => 'admin/visits/'.$visit,
            // The trail is written by the registration above. The inbox is not — nothing there
            // addresses a staff account, and no endpoint creates a notification, since they are
            // raised by domain events. So this is the one fixture built through an application
            // service rather than over HTTP, which is still the app deciding what a row contains.
            'me/notifications' => $this->raiseNotification($reader),
            default => $path,
        };

        Sanctum::actingAs($reader);

        $response = $this->getJson("/api/v1/{$concrete}");

        if ($response->status() !== 200) {
            return null;
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            return null;
        }

        // A collection: take the first row, which is what the mapper is handed.
        if (array_is_list($data)) {
            return isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        }

        return $data;
    }

    private function raiseNotification(Account $recipient): string
    {
        app(Notifier::class)->notify(
            (string) $recipient->uuid,
            'contract.verification',
            ['title' => 'Contract verification', 'body' => 'Raised so the inbox has a row to verify.'],
        );

        return 'me/notifications';
    }

    private function registerResident(): string
    {
        return (string) $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');
    }

    private function registerHousehold(string $residentId): string
    {
        return (string) $this->postJson('/api/v1/admin/households', [
            'head_resident_id' => $residentId,
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');
    }

    private function scheduleVisit(string $residentId): string
    {
        return (string) $this->postJson('/api/v1/admin/visits', [
            'resident_id' => $residentId,
            'purpose' => 'verification',
            'scheduled_for' => now()->addDay()->toDateString(),
            'scheduled_window' => 'morning',
        ])->assertCreated()->json('data.id');
    }

    /**
     * An account that may actually read this endpoint.
     *
     * **There is no role that can see all eight**, and discovering that was worth the test.
     * `audit.view` is deliberately withheld from `lgu_admin` — `Role::DataProtectionOfficer`
     * exists precisely so that the trail recording the MSWDO head's own approvals is not read by
     * the MSWDO head. Verifying the contract through a single all-powerful actor would have
     * required granting an administrator the one permission the design spends a whole role
     * keeping away from them.
     *
     * So the actor is chosen per interaction, and only ever to make the payload *reachable*.
     * Whether a narrower role should see a given field is `AuthorizationMatrixTest`'s question,
     * not this one: conflating "the field was removed" with "the field was withheld" would send
     * somebody to fix the wrong thing.
     */
    private function actorWhoMaySee(string $path): Account
    {
        return $this->reviewer(match (true) {
            str_starts_with($path, 'admin/audit-entries') => 'data_protection_officer',
            default => 'lgu_admin',
        });
    }
}
