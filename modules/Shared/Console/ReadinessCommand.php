<?php

declare(strict_types=1);

namespace Modules\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Checks that every backing service this application needs is actually reachable.
 *
 * Deliberately a console command rather than an HTTP endpoint. `GET /api/v1/health` is a
 * public liveness probe and is contractually forbidden from disclosing dependency status
 * (docs/api/conventions.md §9) — publishing "postgres: down" to the internet is free
 * reconnaissance. Operators and developers have a shell; the internet does not.
 *
 * Used by a developer after `docker compose up` to tell "I mis-set an environment
 * variable" from "the container has not finished starting", and by a deploy to gate
 * traffic before a node joins the load balancer.
 *
 * Exit code 0 when every configured dependency answered, 1 otherwise, so it composes with
 * shell `&&` and with a deployment script.
 */
final class ReadinessCommand extends Command
{
    protected $signature = 'lguids:readiness {--json : Emit machine-readable output}';

    protected $description = 'Check that the database, cache, Redis, queue and object storage are reachable';

    private const STATUS_OK = 'ok';

    private const STATUS_FAILED = 'failed';

    /** Configured away, so not a failure — reported so nobody mistakes it for a pass. */
    private const STATUS_SKIPPED = 'skipped';

    public function handle(
        DatabaseManager $database,
        CacheFactory $cache,
        QueueFactory $queue,
        FilesystemFactory $filesystem,
    ): int {
        $checks = [
            $this->check('database', (string) config('database.default'), fn (): string => $this->probeDatabase($database)),
            $this->check('cache', (string) config('cache.default'), fn (): string => $this->probeCache($cache)),
            $this->check('redis', (string) config('database.redis.client'), fn (): string => $this->probeRedis()),
            $this->check('queue', (string) config('queue.default'), fn (): string => $this->probeQueue($queue)),
            $this->check('storage', (string) config('filesystems.default'), fn (): string => $this->probeStorage($filesystem)),
        ];

        $failed = array_filter($checks, static fn (array $check): bool => $check['status'] === self::STATUS_FAILED);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $failed === [],
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Dependency', 'Driver', 'Status', 'Detail'],
            array_map(
                static fn (array $check): array => [
                    $check['dependency'],
                    $check['driver'],
                    match ($check['status']) {
                        self::STATUS_OK => '<fg=green>ok</>',
                        self::STATUS_SKIPPED => '<fg=yellow>skipped</>',
                        default => '<fg=red>FAILED</>',
                    },
                    $check['detail'],
                ],
                $checks,
            ),
        );

        if ($failed !== []) {
            $this->newLine();
            $this->error('Not ready: '.implode(', ', array_column($failed, 'dependency')));
            $this->line('Local setup: docs/runbooks/local-development.md');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ready.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{dependency: string, driver: string, status: string, detail: string}
     */
    private function check(string $dependency, string $driver, callable $probe): array
    {
        try {
            $detail = $probe();

            return [
                'dependency' => $dependency,
                'driver' => $driver,
                'status' => str_starts_with($detail, 'not ') ? self::STATUS_SKIPPED : self::STATUS_OK,
                'detail' => $detail,
            ];
        } catch (Throwable $e) {
            return [
                'dependency' => $dependency,
                'driver' => $driver,
                'status' => self::STATUS_FAILED,
                'detail' => self::redact($e->getMessage()),
            ];
        }
    }

    private function probeDatabase(DatabaseManager $database): string
    {
        $connection = $database->connection();
        $connection->select('select 1');

        return 'connected';
    }

    private function probeCache(CacheFactory $cache): string
    {
        $key = 'lguids:readiness:'.bin2hex(random_bytes(4));
        $store = $cache->store();

        $store->put($key, 'probe', 10);
        $value = $store->get($key);
        $store->forget($key);

        if ($value !== 'probe') {
            throw new \RuntimeException('Cache accepted a write but did not return it.');
        }

        return 'read/write ok';
    }

    private function probeRedis(): string
    {
        // Redis is only required when something is actually pointed at it. Saying "ok"
        // for an unused dependency would be a false pass.
        $usesRedis = in_array('redis', [
            config('cache.default'),
            config('queue.default'),
            config('session.driver'),
            config('broadcasting.default'),
        ], true);

        if (! $usesRedis) {
            return 'not in use by cache, queue, session or broadcast';
        }

        Redis::connection()->ping();

        return 'ping ok';
    }

    private function probeQueue(QueueFactory $queue): string
    {
        $connection = $queue->connection();

        if (config('queue.default') === 'sync') {
            return 'not queued — sync runs jobs inline';
        }

        return 'pending jobs: '.$connection->size();
    }

    private function probeStorage(FilesystemFactory $filesystem): string
    {
        $disk = (string) config('filesystems.default');
        $path = '.readiness/'.bin2hex(random_bytes(4));
        $storage = $filesystem->disk($disk);

        $storage->put($path, 'probe');
        $readBack = $storage->get($path);
        $storage->delete($path);

        if ($readBack !== 'probe') {
            throw new \RuntimeException('Storage accepted a write but did not return it.');
        }

        return 'read/write/delete ok';
    }

    /**
     * Driver exceptions quote connection strings, which carry credentials. A readiness
     * check that leaks a database password into a terminal, a CI log or a screenshot has
     * done more harm than the outage it was reporting (CLAUDE.md Article 5.5).
     */
    private static function redact(string $message): string
    {
        $patterns = [
            // user:password@host in any URL-ish string
            '#(?<=://)[^:/@\s]+:[^@\s]+(?=@)#i' => '***:***',
            // key=value pairs naming a credential
            '#\b(password|passwd|pwd|secret|token|api[_-]?key)\s*=\s*\S+#i' => '$1=***',
        ];

        $redacted = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);

        return trim(preg_replace('/\s+/', ' ', $redacted) ?? $redacted);
    }
}
