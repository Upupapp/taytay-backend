# Runbook — Local Development

Getting the API, database, Redis, queue worker and object storage running on a laptop,
and proving they are actually up.

Prerequisites: PHP 8.3+ (with `pdo_pgsql`), Composer 2, and Docker Desktop (or any
`docker compose` v2). A no-Docker path is in [§ 6](#6-without-docker).

---

## 1. First boot

```bash
composer install
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate

docker compose up -d --wait   # postgres, redis, minio, mailpit
php artisan migrate
php artisan lguids:readiness
```

`--wait` blocks until every service reports healthy, so the migrate step cannot race a
database that is still starting.

`lguids:readiness` is the check that matters. It writes and reads back through each
dependency rather than pinging a port, so a misconfigured credential or an unwritable
bucket fails here instead of three screens into a feature:

```
+------------+--------+--------+----------------------+
| Dependency | Driver | Status | Detail               |
+------------+--------+--------+----------------------+
| database   | pgsql  | ok     | connected            |
| cache      | redis  | ok     | read/write ok        |
| redis      | predis | ok     | ping ok              |
| queue      | redis  | ok     | pending jobs: 0      |
| storage    | local  | ok     | read/write/delete ok |
+------------+--------+--------+----------------------+

Ready.
```

Exit code is 0 when ready and 1 otherwise, so it composes: `php artisan lguids:readiness && php artisan serve`.
`--json` gives machine-readable output for a script.

## 2. Running it

| What | Command | Notes |
| --- | --- | --- |
| API | `php artisan serve` | http://127.0.0.1:8000 |
| Queue worker | `php artisan queue:work` | A separate terminal. Restart it after changing job code — a worker holds the old code in memory. |
| Scheduler | `php artisan schedule:work` | Only if you are working on scheduled tasks. |
| Logs | `php artisan pail` | Or `storage/logs/laravel.log`. |
| Mail UI | http://localhost:8025 | Mailpit. Nothing leaves the machine. |
| Object storage UI | http://localhost:9001 | MinIO console, `lguids_local_key` / `lguids_local_secret`. |

Smoke test:

```bash
curl http://127.0.0.1:8000/api/v1/health
# {"data":{"service":"taytay-lguids-backend","status":"ok","api_version":"v1"},"meta":{...}}
```

## 3. Checks before you push

```bash
composer check     # pint --test, then the full test suite
```

or individually: `composer lint`, `composer test`, `vendor/bin/pint` to fix.

The test suite ignores your `.env` entirely and runs against in-memory SQLite
(`phpunit.xml`), so tests are fast and cannot be broken by local service state. The
trade-off is that **tests do not prove PostgreSQL behaviour** — run `php artisan migrate`
against the container before trusting a migration.

## 4. Health versus readiness

Two different questions, deliberately answered in two different places:

| | `GET /api/v1/health` | `php artisan lguids:readiness` |
| --- | --- | --- |
| Question | Is the process alive? | Can it actually serve? |
| Audience | Load balancer, uptime monitor, public | Developers, deploy scripts |
| Exposure | Public, unauthenticated | Shell only |
| Reports | service name, status, API version | per-dependency status |

The public endpoint must never report dependency status — "postgres: down" published to
the internet is free reconnaissance, and `docs/api/conventions.md` §9 forbids it. That is
why readiness is a command: operators have a shell, the internet does not.

## 5. Behaviour worth knowing locally

**Queue.** `QUEUE_CONNECTION=redis` means jobs are queued and nothing runs until a worker
picks them up. If a queued side effect "does nothing", check that a worker is running
before debugging the job. Setting `QUEUE_CONNECTION=sync` runs jobs inline, which is
convenient and also hides every ordering and retry bug — use it briefly, not by default.

**Storage.** `FILESYSTEM_DISK=local` writes to `storage/app/private`. Switch to
`object-storage` to exercise the real S3 path against MinIO. The `object-storage` disk is
private with `throw` enabled, so a failed write raises rather than returning `false` —
you will notice it. Nothing citizen-derived may ever be written to the `public` disk
(CLAUDE.md Article 8.5).

**Cache.** Redis locally, as in production. `php artisan cache:clear` after changing
anything cached. Note that `config:cache` makes `.env` edits invisible until
`config:clear` — a classic ten-minute confusion.

**Mail.** Everything goes to Mailpit. There is no path from a laptop to a resident's
inbox, which is the point.

**Logging.** `single` file locally. Redact before logging: never a government identifier,
credential, token, QR signing material, password or full address (CLAUDE.md Article 5.5).

**Push.** FCM is unset locally, so nothing sends. Notification code should degrade
cleanly rather than fail when no credentials are present.

## 6. Without Docker

Swap the block at the bottom of `.env.example` in — SQLite, file cache, sync queue, log
mailer — and the application runs with no services at all. `lguids:readiness` will report
`skipped` for Redis rather than pretending it passed.

You lose PostgreSQL parity (SQLite differs on transactions, types and constraint
enforcement), real queue behaviour, and the object-storage code path. It is fine for
domain and HTTP work; it is not fine for anything infrastructure-shaped. Run the Docker
stack before trusting a migration, a job or an upload.

If you have local PostgreSQL and Redis installed natively, point the `.env` at them
instead — nothing in the application knows or cares whether a dependency is containerised.

### A disposable native PostgreSQL

If PostgreSQL is installed but you do not want to touch the cluster your machine already
runs, create a throwaway one on a spare port. It never interacts with the existing service
and is deleted afterwards:

```bash
PGBIN="/c/Program Files/PostgreSQL/16/bin"      # adjust to your install
PGDATA_DIR="$TEMP/lguids_pg"

"$PGBIN/initdb" -D "$PGDATA_DIR" -U lguids --auth=trust --encoding=UTF8 --no-locale
"$PGBIN/pg_ctl" -D "$PGDATA_DIR" -o "-p 55432 -h 127.0.0.1" -l "$TEMP/lguids_pg.log" start
"$PGBIN/psql" -h 127.0.0.1 -p 55432 -U lguids -d postgres -c "CREATE DATABASE lguids;"

# point .env at it: DB_PORT=55432, DB_USERNAME=lguids, DB_PASSWORD=
php artisan migrate && php artisan lguids:readiness

"$PGBIN/pg_ctl" -D "$PGDATA_DIR" stop && rm -rf "$PGDATA_DIR"
```

`--auth=trust` is safe **only** because the cluster listens on `127.0.0.1`, holds nothing
but synthetic data, and is destroyed at the end. Never use it for anything you keep.

## 6a. What has actually been verified on a developer machine

Recorded so that "it should work" is not confused with "it was run". Verified on Windows
with PHP 8.3.30, PostgreSQL 16.14 and Redis 5.0.14 as native processes:

| Item | Result |
| --- | --- |
| `migrate` and `migrate:fresh` on PostgreSQL | 4 migrations applied, 10 tables created, re-runnable |
| `lguids:readiness` on PostgreSQL + Redis | all five dependencies `ok` |
| Redis cache and queue | read/write ok; a job was dispatched and executed by `queue:work` |
| Local SMTP capture | message delivered to Mailpit 1.22.3 and visible in its inbox |
| API against PostgreSQL | `/api/v1/health` 200, `/api/v1/services` paginated, `/api/v1/admin/services` 401 |
| Storage — `local` disk | write/read/delete ok |
| Storage — **`object-storage` against a real S3 API** | bucket created, write/read/size ok, `visibility=private`, signed URL issued, delete ok — against MinIO `RELEASE.2025-09-07T16-13-09Z` running locally |
| `lguids:readiness` with `FILESYSTEM_DISK=object-storage` | storage `ok` through the S3 path |
| `docker compose config` | **valid** — parsed and fully resolved by the official Docker Compose CLI v5.4.0; 5 services, all published ports on `127.0.0.1`, 3 volumes, healthchecks intact |
| Pinned image tags | all five verified to exist in their registries |

The Compose CLI used for validation was the official release binary, checksum-verified
against Docker's published `.sha256`, run from a temporary directory and not installed.

### The one thing still not executed

**No container has ever been started from this file.** `docker compose config` validates
the model — schema, interpolation, defaults — and the image tags are known to exist, but
image pull, container networking, `depends_on` ordering, volume mounts and the
healthcheck *commands inside the images* have not run, because no container runtime was
available. Everything the stack provides has been proven with the same software running
natively; the orchestration layer itself has not.

If you are the first person to run `docker compose up -d --wait`, expect it to work and
please report it if it does not.

Lesser caveat: the compose file pins PostgreSQL 17; the migration proof above ran on the
locally installed PostgreSQL 16.14.

## 7. When something is wrong

| Symptom | Likely cause |
| --- | --- |
| `readiness` says database failed | Containers still starting (`docker compose ps`), or `DB_PORT` clashes with another Postgres. Set `DB_HOST_PORT` and `DB_PORT` to a free port. |
| `readiness` says redis failed | `REDIS_CLIENT=phpredis` without the extension installed. Use `predis`. |
| `readiness` says storage failed | Bucket missing — `docker compose up minio-bucket` re-runs the one-shot creator. |
| Config changes ignored | `php artisan config:clear`. |
| 500 with no detail | `APP_DEBUG=true` locally, then read `storage/logs/laravel.log`. |
| Port already allocated | Set the `*_HOST_PORT` override in `.env`; every published port is parameterised. |

## 8. Resetting

```bash
docker compose down -v     # DESTROYS local volumes: database, redis, objects
docker compose up -d --wait
php artisan migrate:fresh
```

`-v` is local only. There is no equivalent workflow for any deployed environment, and
nothing in this repository can reach one.
