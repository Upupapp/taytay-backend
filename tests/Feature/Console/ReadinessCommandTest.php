<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Modules\Shared\Console\ReadinessCommand;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The readiness check is what a developer and a deploy script trust when they ask "is
 * this thing actually able to serve?". A check that cannot fail is worse than no check,
 * so these tests prove it reports failure as readily as success — and that it does not
 * spill credentials while doing it.
 */
final class ReadinessCommandTest extends TestCase
{
    #[Test]
    public function it_succeeds_when_every_configured_dependency_answers(): void
    {
        $this->artisan('lguids:readiness')
            ->assertExitCode(0)
            ->expectsOutputToContain('Ready.');
    }

    #[Test]
    public function it_reports_each_dependency(): void
    {
        $exit = Artisan::call('lguids:readiness');
        $output = Artisan::output();

        $this->assertSame(0, $exit);

        foreach (['database', 'cache', 'redis', 'queue', 'storage'] as $dependency) {
            $this->assertStringContainsString($dependency, $output);
        }
    }

    #[Test]
    public function it_fails_when_a_dependency_is_unreachable(): void
    {
        // Point the cache at a Redis that is not there. Port 1 refuses immediately, so the
        // test stays fast and deterministic.
        config([
            'cache.default' => 'redis',
            'database.redis.client' => 'predis',
            'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 1],
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0],
        ]);

        $this->artisan('lguids:readiness')
            ->expectsOutputToContain('FAILED')
            ->assertExitCode(1);
    }

    #[Test]
    public function an_unused_dependency_is_skipped_rather_than_reported_as_healthy(): void
    {
        // Nothing in the test environment points at Redis, so claiming "ok" would be a
        // false pass — the check would go green on a machine with no Redis at all.
        $this->artisan('lguids:readiness --json')
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);
    }

    #[Test]
    public function json_output_is_machine_readable(): void
    {
        $exit = Artisan::call('lguids:readiness', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);

        /** @var array{ready: bool, checks: list<array<string, string>>} $decoded */
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['ready']);
        $this->assertCount(5, $decoded['checks']);
        $this->assertSame(
            ['database', 'cache', 'redis', 'queue', 'storage'],
            array_column($decoded['checks'], 'dependency'),
        );
    }

    /**
     * A readiness check that prints a database password into a terminal, a CI log or a
     * screenshot has done more harm than the outage it was reporting.
     */
    #[Test]
    public function it_redacts_credentials_out_of_driver_errors(): void
    {
        $redact = new ReflectionMethod(ReadinessCommand::class, 'redact');

        $this->assertSame(
            'could not connect to postgres://***:***@db.internal:5432/lguids',
            $redact->invoke(null, 'could not connect to postgres://lguids:hunter2@db.internal:5432/lguids'),
        );

        $this->assertStringNotContainsString(
            'hunter2',
            (string) $redact->invoke(null, 'auth failed (password=hunter2)'),
        );

        $this->assertStringNotContainsString(
            'abc123',
            (string) $redact->invoke(null, 'rejected: api_key=abc123'),
        );
    }
}
