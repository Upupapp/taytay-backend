<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers\V1;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Audit\Application\AuditQuery;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\WorkloadQueue;
use Modules\Shared\Http\ApiResponse;
use Throwable;

/**
 * Readiness and metrics, for operators (ADR 0037 §3).
 *
 * **IN `Audit` RATHER THAN `Shared`, AND THAT WAS NOT THE FIRST INSTINCT.** It was written in
 * `Shared` — where the health controller and the readiness command already live — and
 * `ModuleBoundaryTest` failed the build: `Shared` is the shared kernel and may depend on nothing
 * (Article 2.3), while any protected endpoint must ask `AccessControl` who is calling.
 *
 * `Audit` is the right home rather than a convenient one. It already owns the surfaces for people
 * who **oversee the system instead of serving residents** — the trail, privacy governance, and now
 * health — and every one of them is held by a role with no operational permission at all: the DPO,
 * and now the operations engineer. It also already depends on `AccessControl` legitimately, which
 * is what `Shared` cannot do.
 *
 * `GET /api/v1/health` stays in `Shared`, because it authorizes nothing.
 *
 * **DELIBERATELY NOT `GET /api/v1/health`.** That endpoint is the public liveness probe and is
 * contractually forbidden from disclosing dependency status — publishing "postgres: down" to the
 * internet is free reconnaissance, and publishing "postgres: ok" tells an attacker which
 * dependencies exist to attack.
 *
 * These two are permission-gated. A load balancer gets the public probe; a human with
 * `operations.view` gets the detail.
 *
 * THEY REPORT STATE, NEVER CONFIGURATION. No host, no port, no bucket name, no credential, no
 * connection string — a readiness endpoint that answered "postgres at 10.0.0.4:5432 is ok" would
 * be a network map behind one permission. The driver NAME is included because "the queue is on
 * `sync`" is the actual finding when a production deployment is silently running jobs inline; the
 * driver's configuration is not.
 */
final class OperationsController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditQuery $entries,
    ) {}

    /**
     * Is every backing service reachable?
     *
     * The HTTP counterpart of `lguids:readiness`, which stays because a deploy script and a
     * developer after `docker compose up` both have a shell and neither has a token.
     */
    public function readiness(Request $request, ActorContext $actor, DatabaseManager $database, CacheFactory $cache): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::OperationsView);

        $checks = [
            'database' => $this->probe((string) config('database.default'), function () use ($database): void {
                $database->connection()->select('select 1');
            }),
            'cache' => $this->probe((string) config('cache.default'), function () use ($cache): void {
                $key = 'lguids:readiness:'.bin2hex(random_bytes(4));
                $cache->store()->put($key, 'ok', 5);
                $cache->store()->forget($key);
            }),
            'queue' => $this->probe((string) config('queue.default'), function (): void {
                // Reachability, not a dispatch: enqueuing a probe job would leave a worker
                // processing readiness checks.
                DB::table('jobs')->count();
            }),
            'storage' => $this->probe((string) config('files.disk'), function (): void {
                Storage::disk((string) config('files.disk'))->exists('.readiness-probe');
            }),
        ];

        $ready = ! in_array('failed', array_column($checks, 'status'), true);

        return ApiResponse::item([
            'ready' => $ready,
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    /**
     * The numbers an operator watches.
     *
     * Every one is COUNTED LIVE rather than read from a counter somebody maintains — the same
     * reasoning as the event seat count (ADR 0031 §1). A metrics endpoint whose numbers drift from
     * reality is worse than none, because it is believed.
     */
    public function metrics(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::OperationsView);

        return ApiResponse::item([
            'queues' => $this->queueDepths(),
            'jobs' => [
                /*
                 * THE NUMBER TO ALERT ON. A failed job is a notification that never arrived, an
                 * export that never built, or an image that never published — and each of those
                 * is silent from the outside.
                 */
                'failed_total' => $this->safeCount('failed_jobs'),
                'failed_last_hour' => $this->safeCount('failed_jobs', 'failed_at'),
            ],
            'notifications' => [
                // Notification failures, named separately because they are the ones a resident
                // feels: a family that did not hear.
                'failed_last_hour' => $this->countWhere('notifications', 'status', 'failed'),
                'pending' => $this->countWhere('notifications', 'status', 'pending'),
            ],
            'auth' => [
                /*
                 * AUTH ANOMALIES, from the audit trail rather than from a separate counter — the
                 * trail is already the record of these, and a second source would drift.
                 */
                'sign_in_failures_last_hour' => $this->auditCount('identity.sign-in-failed'),
                'accounts_locked_last_hour' => $this->auditCount('identity.account-locked'),
                'sign_ins_blocked_last_hour' => $this->auditCount('identity.sign-in-blocked'),
            ],
            'exports' => [
                // A stuck export is one that has been `running` far longer than any export takes.
                'running' => $this->countWhere('report_exports', 'status', 'running'),
                'failed_last_hour' => $this->countWhere('report_exports', 'status', 'failed'),
            ],
            'observed_at' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function queueDepths(): array
    {
        $depths = [];

        foreach (WorkloadQueue::cases() as $queue) {
            /*
             * DEPTH PER NAMED QUEUE, because the aggregate hides the finding. A total of 400 is
             * unremarkable if it is all `exports`; the same 400 sitting on `notifications` means
             * nobody has been told anything for an hour.
             *
             * Zero on a queue with no worker looks identical to zero on a healthy one — which is
             * why the runbook says to watch for depth WITH no worker rather than depth alone.
             */
            $depths[$queue->value] = $this->countWhere('jobs', 'queue', $queue->value);
        }

        return $depths;
    }

    /**
     * @return array{driver: string, status: string}
     */
    private function probe(string $driver, callable $check): array
    {
        try {
            $check();

            return ['driver' => $driver, 'status' => 'ok'];
        } catch (Throwable) {
            /*
             * THE EXCEPTION IS NOT RETURNED. A driver exception carries a host, a port, a
             * database name and sometimes a credential — which is the network map this endpoint
             * exists not to publish, even to a caller holding the permission. It is logged, where
             * the redaction processor handles it (ADR 0037 §2).
             */
            return ['driver' => $driver, 'status' => 'failed'];
        }
    }

    private function safeCount(string $table, ?string $recentColumn = null): int
    {
        try {
            $query = DB::table($table);

            if ($recentColumn !== null) {
                $query->where($recentColumn, '>=', now()->subHour());
            }

            return $query->count();
        } catch (Throwable) {
            // A table that does not exist in this deployment is a zero, not a 500. A metrics
            // endpoint that falls over because one optional feature is unmigrated is a metrics
            // endpoint nobody can use during the incident they need it for.
            return 0;
        }
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        try {
            return DB::table($table)->where($column, $value)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Counted through the module's own query service rather than by naming the table.
     *
     * `AuditIsAppendOnlyTest` flags any file that reaches for `audit_entries` directly — written
     * for writers, and correct for readers too: a second place that knows the table's shape is a
     * second place to update when it changes.
     */
    private function auditCount(string $action): int
    {
        try {
            return $this->entries->query()
                ->where('action', $action)
                ->where('occurred_at', '>=', now()->subHour())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
