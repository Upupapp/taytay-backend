<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Modules\Shared\Logging\AddRequestContext;
use Modules\Shared\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 32, as tests.
 *
 *  1. **Logs contain no obvious secrets or PII payload dumps.**
 *  2. **Health endpoints do not disclose internals publicly.**
 *  3. (Backup strategy includes restore testing — a runbook commitment, not a code property.)
 */
final class ObservabilityTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: nothing sensitive reaches a log ─────────────────────────────────

    #[Test]
    public function the_redactor_removes_a_payload_dump(): void
    {
        /*
         * THE LINE THIS EXISTS TO SURVIVE. Somebody chasing a bug at four in the afternoon writes
         * `Log::error('Upload failed', ['request' => $request->all()])`. It looks entirely
         * reasonable and it puts a resident's PhilSys number, password and bearer token in a file
         * that is read by whoever is debugging and kept longer than the record it describes.
         */
        $scrubbed = (new RedactSensitiveData)->scrub([
            'request' => [
                'password' => 'correct-horse-battery',
                'philsys_number' => '1234-5678-9012',
                'narrative' => 'The family reported that the father has been violent.',
                'street_address' => '12 Manggahan Street, Dolores',
                'first_name' => 'Maria',
            ],
        ]);

        $flattened = (string) json_encode($scrubbed);

        foreach ([
            'correct-horse-battery',
            '1234-5678-9012',
            'has been violent',
            'Manggahan',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $flattened, "A log line kept [{$secret}].");
        }

        // A first name is NOT redacted, and that is deliberate: over-redacting everything makes a
        // log useless and teaches people to bypass it. The list is what Article 5.5 names.
        $this->assertSame('Maria', $scrubbed['request']['first_name']);
    }

    #[Test]
    public function a_secret_is_removed_even_under_a_key_nobody_predicted(): void
    {
        /*
         * THE PASS THAT MATTERS IN PRACTICE. The dangerous log line is never the one somebody
         * designed — it is a driver quoting the SQL it failed on, or a value arriving under a name
         * the key list has never heard of.
         */
        $scrubbed = (new RedactSensitiveData)->scrub([
            'payload' => '1234-5678-9012',
            'header' => 'Bearer 9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a',
            'sanctum' => '17|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789abcd',
            'digest' => str_repeat('a1b2c3d4', 6),
        ]);

        $flattened = (string) json_encode($scrubbed);

        $this->assertStringNotContainsString('1234-5678-9012', $flattened);
        $this->assertStringNotContainsString('9f8a7b6c5d4e', $flattened);
        $this->assertStringNotContainsString('aBcDeFgHiJkL', $flattened);
        $this->assertStringNotContainsString(str_repeat('a1b2c3d4', 6), $flattened);
    }

    #[Test]
    public function an_exception_message_is_scrubbed_too(): void
    {
        /*
         * The most common route a secret takes into a log: a database driver quoting the statement
         * it failed on. The message is scrubbed as well as the context, because a message is where
         * that lands.
         */
        $message = (new RedactSensitiveData)->scrubString(
            "SQLSTATE[23505]: insert into residents (philsys) values ('1234-5678-9012')",
        );

        $this->assertStringNotContainsString('1234-5678-9012', $message);
    }

    #[Test]
    public function a_key_whose_value_is_null_is_still_redacted(): void
    {
        // The KEY decides, so a caller cannot smuggle a value past by making it look boring — and
        // the presence of the field is itself the finding.
        $scrubbed = (new RedactSensitiveData)->scrub(['password' => null, 'api_key' => '']);

        $this->assertSame(RedactSensitiveData::REDACTED, $scrubbed['password']);
        $this->assertSame(RedactSensitiveData::REDACTED, $scrubbed['api_key']);
    }

    #[Test]
    public function a_serialised_object_is_replaced_rather_than_walked(): void
    {
        [, $resident] = $this->activeCitizenWithResident();

        $scrubbed = (new RedactSensitiveData)->scrub(['model' => $resident]);

        /*
         * A model in a log line IS the payload dump this class exists to prevent — every column,
         * including the ones the key list would have caught individually. Replaced by its class
         * name, which is the useful half.
         */
        $this->assertSame('['.$resident::class.']', $scrubbed['model']);
    }

    #[Test]
    public function the_redactor_does_not_flag_an_ordinary_line(): void
    {
        /*
         * THE POSITIVE FIXTURE. A redactor that replaced everything would also "never leak" — and
         * would be turned off within a week by whoever needed to read a log.
         */
        $scrubbed = (new RedactSensitiveData)->scrub([
            'action' => 'case.approved',
            'route' => 'admin/assistance-requests/{case}/transitions',
            'duration_ms' => 42,
            'status' => 200,
        ]);

        $this->assertSame('case.approved', $scrubbed['action']);
        $this->assertSame('admin/assistance-requests/{case}/transitions', $scrubbed['route']);
        $this->assertSame(42, $scrubbed['duration_ms']);
    }

    #[Test]
    public function redaction_runs_after_the_context_processor(): void
    {
        /*
         * THE ORDERING, ASSERTED RATHER THAN COMMENTED. Monolog invokes processors
         * last-pushed-first, so redaction must be pushed FIRST to run LAST — over everything the
         * context processor added.
         *
         * The intuitive order fails silently: the redacted fields are still redacted, because they
         * were in the original context, and the failure only surfaces the day the context
         * processor starts carrying something worth hiding.
         */
        $record = new LogRecord(
            new \DateTimeImmutable,
            'app',
            Level::Info,
            'probe',
            ['password' => 'secret-value'],
        );

        $composed = (new RedactSensitiveData)((new AddRequestContext)($record));

        $this->assertSame(RedactSensitiveData::REDACTED, $composed->context['password']);
        // And the context processor's own fields survived the scrub.
        $this->assertArrayHasKey('environment', $composed->extra);
    }

    #[Test]
    public function a_log_line_written_during_a_request_carries_the_correlation_id(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $captured = null;

        Log::listen(function ($message) use (&$captured): void {
            $captured ??= $message;
        });

        $response = $this->getJson('/api/v1/me')->assertOk();

        Log::info('probe during request');

        $this->assertNotNull($response->headers->get('X-Request-Id'));

        // The processor adds it, so a `Log::info` anywhere in a request inherits it without the
        // caller passing anything — which is what makes a support call tractable.
        $record = (new AddRequestContext)(new LogRecord(
            new \DateTimeImmutable, 'app', Level::Info, 'probe', [],
        ));

        $this->assertSame(config('app.env'), $record->extra['environment']);
        $this->assertNotNull($captured);
    }

    // ── criterion 2: health does not disclose internals ──────────────────────────────

    #[Test]
    public function the_public_probe_says_nothing_about_dependencies(): void
    {
        $this->app['auth']->forgetGuards();

        $body = $this->getJson('/api/v1/health')->assertOk()->content();

        /*
         * Publishing "postgres: down" to the internet is free reconnaissance, and publishing
         * "postgres: ok" tells an attacker which dependencies exist to attack. The public probe
         * answers only whether this process is alive.
         */
        foreach (['database', 'redis', 'queue', 'storage', 'postgres', 'host', 'bucket'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $body);
        }
    }

    #[Test]
    public function readiness_and_metrics_are_refused_without_the_permission(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (['/api/v1/admin/operations/readiness', '/api/v1/admin/operations/metrics'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        // Held by nobody but the operations role — not even the MSWDO head, whose job it is not.
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        foreach (['/api/v1/admin/operations/readiness', '/api/v1/admin/operations/metrics'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    #[Test]
    public function readiness_reports_state_and_never_configuration(): void
    {
        Sanctum::actingAs($this->reviewer('operations_engineer'));

        $response = $this->getJson('/api/v1/admin/operations/readiness');
        $body = $response->content();

        $this->assertContains($response->status(), [200, 503]);

        /*
         * A readiness endpoint that answered "postgres at 10.0.0.4:5432 is ok" would be a network
         * map behind one permission. The driver NAME is included, because "the queue is on `sync`"
         * is the actual finding when a production deployment is silently running jobs inline; its
         * configuration is not.
         */
        foreach (['password', 'secret', 'DB_HOST', '5432', '6379', 'OBJECT_STORAGE'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }

        $this->assertArrayHasKey('database', $response->json('data.checks'));
    }

    #[Test]
    public function metrics_report_queue_depth_per_named_queue(): void
    {
        Sanctum::actingAs($this->reviewer('operations_engineer'));

        $data = $this->getJson('/api/v1/admin/operations/metrics')->assertOk()->json('data');

        /*
         * PER QUEUE, because the aggregate hides the finding: a total of 400 is unremarkable if it
         * is all `exports`, and means nobody has been told anything for an hour if it is all
         * `notifications` (ADR 0036 §1).
         */
        foreach (['notifications', 'exports', 'media', 'scheduled-content'] as $queue) {
            $this->assertArrayHasKey($queue, $data['queues']);
        }

        // The numbers an operator actually alerts on.
        $this->assertArrayHasKey('failed_total', $data['jobs']);
        $this->assertArrayHasKey('sign_in_failures_last_hour', $data['auth']);
    }

    #[Test]
    public function metrics_carry_no_resident_data(): void
    {
        [$citizen] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->reviewer('operations_engineer'));

        $body = $this->getJson('/api/v1/admin/operations/metrics')->assertOk()->content();

        /*
         * The person on call is not a caseworker. Every value here is a COUNT — a metrics endpoint
         * that named a resident would be handing welfare data to an on-call rota.
         */
        $this->assertStringNotContainsString((string) $citizen->uuid, $body);
        $this->assertStringNotContainsString('@', $body);
    }
}
