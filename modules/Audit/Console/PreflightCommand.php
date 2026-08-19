<?php

declare(strict_types=1);

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Modules\Files\Contracts\StoragePosture;
use Modules\Files\Contracts\UploadPolicy;

/**
 * TAB 18 — the production configuration checklist, asked of the running process.
 *
 * *"Verify every production setting the backend's own checklist names … on the running system, not
 * in a file."*
 *
 * ## Why a command and not a document
 *
 * `docs/runbooks/environments-and-secrets.md` already lists these settings, and a list is exactly
 * the artefact that goes stale: it describes the intended configuration of a server nobody
 * re-reads it against. This asks the **process that is actually serving traffic** what it loaded —
 * so a `.env` edited on the box, a variable missing from the deploy, or a cached config from the
 * previous release all show up as what they are.
 *
 * It is deliberately separate from `lguids:readiness`, which asks whether the backing services
 * *answer*. A database that answers on a public address is reachable and misconfigured, and one
 * command reporting both would let a green line mean either thing.
 *
 * ## The three verdicts, and why the third exists
 *
 * `ok` and `failed` are obvious. **`unverifiable`** is the important one: some items on the
 * checklist are properties of the *host*, not of this process — nginx's body limit, whether the
 * scheduler cron is on exactly one machine, whether a queue worker is actually consuming. A PHP
 * process cannot see any of them, and a preflight that printed `ok` for nginx would be stating a
 * guarantee nobody holds. So each of those prints the number or name to compare against and says
 * plainly that a person must check it.
 *
 * An unverifiable item does **not** fail the command. It is not a defect; it is the boundary of
 * what this vantage point can see, and conflating the two would teach an operator to ignore red.
 *
 * ## In `Audit` rather than `Shared`, for the reason `OperationsController` already records
 *
 * It was written in `Shared`, beside `ReadinessCommand`, and `ModuleBoundaryTest` failed the build
 * twice over: `Shared` may depend on nothing but the framework (Article 2.3), and the upload
 * ceiling belongs to `Files`. `Audit` already owns the surfaces for people who **oversee the system
 * instead of serving residents**, and it may legitimately read another module's `Contracts/`.
 *
 * `ReadinessCommand` stays in `Shared`, because it asks only the framework whether its own backing
 * services answer.
 */
final class PreflightCommand extends Command
{
    protected $signature = 'lguids:preflight {--json : Emit machine-readable output}';

    protected $description = 'Verify the production configuration checklist against the running process';

    private const OK = 'ok';

    private const FAILED = 'failed';

    /** A property of the host, which this process cannot see. Never a pass, never a failure. */
    private const UNVERIFIABLE = 'unverifiable';

    /**
     * The queues this application dispatches to.
     *
     * `QueueConventionsTest` holds the same list, because *"a queue a job dispatches to but no
     * worker consumes is a job that never runs — and it fails silently"*.
     */
    private const QUEUES = ['notifications', 'scheduled-content', 'default', 'integrations', 'media', 'exports'];

    /** A scheduler on two hosts runs every task twice; `withoutOverlapping` needs a store both can see. */
    private const SHARED_CACHE_STORES = ['redis', 'database', 'memcached', 'dynamodb'];

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $production = app()->environment('production');

        $this->application($production);
        $this->crossOrigin($production);
        $this->transport($production);
        $this->objectStorage();
        $this->retention();
        $this->hostSide();

        return $this->report();
    }

    // ── the application itself ───────────────────────────────────────────────────────

    private function application(bool $production): void
    {
        $this->assert(
            'app.key',
            config('app.key') !== null && config('app.key') !== '',
            'set',
            'Empty. Every encrypted value and signed URL this application has ever issued is unreadable.',
        );

        $this->assert(
            'app.debug',
            ! (bool) config('app.debug'),
            'off',
            'ON. The debug page prints the environment, the query, the stack and the connection string to whoever triggered the error.',
        );

        if ($production) {
            $this->assert(
                'app.url',
                str_starts_with((string) config('app.url'), 'https://'),
                (string) config('app.url'),
                'Not https. Signed URLs and password-reset links are generated from this, so they are issued over plaintext.',
            );
        }
    }

    // ── who may call this API from a browser ─────────────────────────────────────────

    private function crossOrigin(bool $production): void
    {
        /** @var list<string> $origins */
        $origins = config('cors.allowed_origins', []);

        $this->assert(
            'cors.allowed_origins',
            ! in_array('*', $origins, true),
            $origins === [] ? 'empty (denies every browser origin)' : implode(', ', $origins),
            'Contains "*". Any site a caseworker visits can then call this API with their session.',
        );

        if ($production && $origins === []) {
            $this->flag('cors.allowed_origins', 'Empty in production. Correct as a default and wrong as a deployed state: every console request is refused by the browser, which reads to a user as the API being down.');
        }

        foreach ($origins as $origin) {
            if (! str_starts_with($origin, 'https://') && ! str_starts_with($origin, 'http://localhost')) {
                $this->flag('cors.allowed_origins', "'{$origin}' is not https. An allowed plaintext origin is one an attacker on the path can impersonate.");
            }
        }

        $this->assert(
            'cors.supports_credentials',
            ! (bool) config('cors.supports_credentials'),
            'false',
            'TRUE. ADR 0005 chose bearer tokens precisely to avoid this; enabling it alongside an origin list is the classic CORS mistake.',
        );

        $this->assert(
            'cors.allowed_origins_patterns',
            config('cors.allowed_origins_patterns', []) === [],
            'none',
            'A pattern is set. A regex origin list is how a staging domain quietly matches an attacker-registered one.',
        );
    }

    // ── transport, proxies and rate limiting ─────────────────────────────────────────

    private function transport(bool $production): void
    {
        $proxies = config('trustedproxy.proxies') ?? env('TRUSTED_PROXIES');

        if ($proxies === '*') {
            $this->flag('trusted proxies', 'Set to "*". Laravel then believes any client-supplied X-Forwarded-For, so rate limiting collapses to one shared bucket and the audit trail records the wrong address.');
        } elseif ($proxies === null || $proxies === '') {
            $this->assert('trusted proxies', ! $production, 'unset', 'Unset behind a load balancer: every request appears to come from the balancer, so one bucket rate-limits the whole municipality.');
        } else {
            $this->pass('trusted proxies', is_array($proxies) ? implode(', ', $proxies) : (string) $proxies);
        }

        /*
         * HSTS is off by default and that is the *correct* state until somebody confirms the
         * certificate chain — it cannot be undone from the server, and a wrong max-age locks every
         * browser out for its duration. So this reports rather than judges.
         */
        $this->checks[] = [
            'name' => 'security.hsts',
            'status' => (bool) config('security.hsts.enabled') ? self::OK : self::UNVERIFIABLE,
            'detail' => (bool) config('security.hsts.enabled')
                ? 'enabled, max-age '.config('security.hsts.max_age')
                : 'DISABLED — correct until the certificate chain is confirmed for every subdomain that will be covered. Enabling it is a one-way door: a wrong max-age locks every browser out of the console for its duration.',
        ];

        $limits = config('security.rate_limits', []);
        $this->assert(
            'security.rate_limits',
            is_array($limits) && $limits !== [],
            count((array) $limits).' limits configured',
            'No rate limits. Sign-in becomes unbounded, which is the one endpoint where that matters most.',
        );
    }

    // ── the two buckets ──────────────────────────────────────────────────────────────

    private function objectStorage(): void
    {
        /*
         * Asked of `Files` rather than resolved here.
         *
         * `PublicMediaHasOneWriterTest` refuses any file outside that module to name the public
         * disk, and it failed this command's first version. It was right to: a preflight resolving
         * the disk itself would also keep checking a fixed disk name after `FILES_PUBLIC_DISK`
         * repointed the application elsewhere — verifying a bucket nobody writes to, and printing a
         * pass for it.
         *
         * `StoragePosture` returns booleans about values it never discloses, so no credential
         * reaches this command, its output, or a terminal scrollback.
         */
        $this->assert(
            'private disk visibility',
            StoragePosture::privateDiskIsPrivate(),
            'private',
            'Not private. Every citizen document in the bucket is then readable by URL.',
        );

        $this->assert(
            'private disk public URL',
            StoragePosture::privateDiskHasNoPublicUrl(),
            'none set',
            'A public base URL is set on the private disk, which is what makes a document link shareable outside any authorization decision.',
        );

        $this->separation('object storage keys', StoragePosture::keysAreSeparate(),
            'A leaked publishing key then reads every citizen document rather than some already-published images.');

        $this->separation('object storage buckets', StoragePosture::bucketsAreSeparate(),
            'One misconfigured object policy then exposes the whole store rather than the published half.');
    }

    // ── how long anything is kept ────────────────────────────────────────────────────

    private function retention(): void
    {
        /*
         * *"log retention per the DPO's decision"*. There is no DPO — the appointment is release-gate
         * blocker 1 — so no number here can be the right one, and this reports the configured value
         * with that stated rather than asserting a threshold engineering invented.
         */
        $this->checks[] = [
            'name' => 'log retention',
            'status' => self::UNVERIFIABLE,
            'detail' => config('logging.channels.daily.days', 14).' days configured. No DPO is appointed (release-gate blocker 1), so this figure is a default rather than a decision. It becomes verifiable when a retention schedule is approved.',
        ];
    }

    // ── what this process cannot see ─────────────────────────────────────────────────

    private function hostSide(): void
    {
        /*
         * The figure comes from `UploadPolicy` — the same published contract both clients read, so
         * the preflight compares nginx against exactly what the API told them, not a second copy.
         * It is enforced by `FileStore::store()` rather than by a validation rule: the controller
         * decides shape, the application service decides policy (Article 3.2).
         *
         * Read through `Contracts/` rather than `Domain/`, which is what the module boundary allows
         * and also what keeps the magic-byte checks out of an operational command.
         */
        $appLimit = UploadPolicy::maxBytes();
        $php = $this->bytesFromIni((string) ini_get('upload_max_filesize'));
        $post = $this->bytesFromIni((string) ini_get('post_max_size'));

        $this->checks[] = [
            'name' => 'nginx client_max_body_size',
            'status' => self::UNVERIFIABLE,
            'detail' => 'Must exceed '.$this->mb($appLimit).', which is what this application accepts. '
                .'Set below it, nginx refuses the upload itself: the caseworker gets a bare 413 from a server '
                .'that never reached this application, so nothing here logs it and nothing explains it.',
        ];

        /*
         * PHP's own ceilings are visible from here, and they are the ones that actually bite first
         * in a default installation — 2 MB out of the box, against a 10 MB application limit.
         */
        $this->assert(
            'php upload limits',
            $php >= $appLimit && $post >= $appLimit,
            'upload_max_filesize '.ini_get('upload_max_filesize').', post_max_size '.ini_get('post_max_size').' — both above '.$this->mb($appLimit),
            'upload_max_filesize='.ini_get('upload_max_filesize').' and post_max_size='.ini_get('post_max_size')
                .' — below the '.$this->mb($appLimit).' this application advertises to clients. PHP discards the body before any '
                .'application code runs, so the request arrives with no file and the error reads as a client bug.',
        );

        $this->checks[] = [
            'name' => 'queue workers',
            'status' => self::UNVERIFIABLE,
            'detail' => 'Workers must consume all six: '.implode(', ', self::QUEUES).'. A queue nothing consumes fails silently — the row sits looking pending, nothing errors and nothing alerts. Check with `queue:monitor` on the worker host.',
        ];

        $store = (string) config('cache.default');

        $this->checks[] = [
            'name' => 'scheduler host',
            'status' => in_array($store, self::SHARED_CACHE_STORES, true) ? self::UNVERIFIABLE : self::FAILED,
            'detail' => in_array($store, self::SHARED_CACHE_STORES, true)
                ? "Cache store '{$store}' is shared, so `withoutOverlapping` works across hosts. That the cron itself runs on exactly one host cannot be seen from here — verify by hand."
                : "Cache store '{$store}' is per-host, so a scheduler on two machines runs every task twice — including exports and notifications. `withoutOverlapping` cannot see the other host's lock.",
        ];

        $this->checks[] = [
            'name' => 'single-node decision',
            'status' => self::UNVERIFIABLE,
            'detail' => 'One API node means total outage on one host failure. Permitted for an initial deployment if recorded as a conscious trade-off — see docs/runbooks/deployment-and-rollback.md.',
        ];
    }

    // ── plumbing ─────────────────────────────────────────────────────────────────────

    /**
     * `null` is reported as unverifiable rather than as a pass.
     *
     * The first version compared the two values with `!== null && ===`, so two *unset* values
     * reported "keys differ" — a pass printed by a check that had looked at nothing, on exactly the
     * deployment where the variables were forgotten.
     */
    private function separation(string $name, ?bool $separate, string $consequence): void
    {
        if ($separate === null) {
            $this->checks[] = [
                'name' => $name,
                'status' => self::UNVERIFIABLE,
                'detail' => 'One or both are unconfigured, so there is nothing to compare. Unset is not separated — '.$consequence,
            ];

            return;
        }

        $this->assert($name, $separate, 'the two differ', 'The two disks share this. '.$consequence);
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }

    /** `ini_get` returns shorthand like "2M"; a preflight that compared it as an integer would read 2. */
    private function bytesFromIni(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function assert(string $name, bool $held, string $detail, string $failure): void
    {
        $held ? $this->pass($name, $detail) : $this->flag($name, $failure);
    }

    private function pass(string $name, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'status' => self::OK, 'detail' => $detail];
    }

    /** Named `flag` rather than `fail`: Laravel's own `Command::fail()` is public and throws. */
    private function flag(string $name, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'status' => self::FAILED, 'detail' => $detail];
    }

    private function report(): int
    {
        $failed = array_values(array_filter($this->checks, static fn (array $c): bool => $c['status'] === self::FAILED));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'environment' => app()->environment(),
                'passed' => $failed === [],
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  Configuration preflight — environment: <options=bold>'.app()->environment().'</>');
        $this->newLine();

        foreach ($this->checks as $check) {
            [$mark, $colour] = match ($check['status']) {
                self::OK => ['✓', 'green'],
                self::FAILED => ['✗', 'red'],
                default => ['?', 'yellow'],
            };

            $this->line("  <fg={$colour}>{$mark}</> <options=bold>{$check['name']}</>");
            $this->line("      {$check['detail']}");
        }

        $unverifiable = count(array_filter($this->checks, static fn (array $c): bool => $c['status'] === self::UNVERIFIABLE));

        $this->newLine();

        if ($failed !== []) {
            $this->line('  <fg=red;options=bold>'.count($failed).' setting(s) wrong.</> Not a deployable configuration.');
        } else {
            $this->line('  <fg=green;options=bold>Every checkable setting holds.</>');
        }

        if ($unverifiable > 0) {
            $this->line("  <fg=yellow>{$unverifiable} item(s) are properties of the host and cannot be seen from this process.</>");
            $this->line('  <fg=yellow>They are not passes. Somebody must check them on the machine.</>');
        }

        $this->newLine();

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
