# Evidence ledger — Taytay Rizal LGU IDS backend

Append-only record for the *Admin Portal & Backend Integration Master Command* (sweep dated
18 August 2026). One row per command. Every figure here was produced by running the tool named
beside it — never copied from the master command.

Its companion is `docs/integration/evidence-ledger.md` in the Angular console repository
(`Upupapp/taytay-admin-web`). Where a command touches both sides, both ledgers carry the entry.
The console's copy holds the full TAB 00 baseline for both repositories; this file holds the
backend half and the decisions that bind this repository.

---

## TAB 00 — Baseline, remotes and the evidence ledger

| | |
| --- | --- |
| Date | 18 August 2026 |
| Executing machine | macOS (Darwin 25.5.0) — **not** the machine the sweep was run on |
| Backend HEAD at start | `22cb10d8eb3c687f959ad7f5084454db8df82fb8` (48 commits, branch `main`) |
| Console HEAD at start | `6df92acbf4604a27e36b3598bc086e4711f3267a` (71 commits, branch `main`) |
| Status | Local half complete; environment provisioning open |

### Toolchain

PHP **8.4.23** (Herd, NTS) with `gd`, `exif`, `pdo_pgsql`, `redis`, `bcmath` — every extension
TAB 00 requires. Composer **2.10.1**. PHP is not on `PATH`; it lives in
`~/Library/Application Support/Herd/bin`.

**PostgreSQL, Redis, Docker and any package manager are absent from this machine.** The
repository's `docker-compose.yml` cannot be used here, so the backend has been *run* but not
*deployed*, and never against real Postgres.

`composer.json` requires `php: ^8.3`; 8.4.23 satisfies it. The master command names PHP 8.3
throughout — running on 8.4 is in-contract, and is recorded so that no later command assumes
8.3 exactly without having checked.

> The sweep's **F-25** ("no PHP toolchain on the development machine; the backend has never
> been run by the integrator") was true of the Windows machine and is **not true here**. The
> suite has now been run, twice, including from a clean clone.

### Measured baseline

| Measure | Measured here | Sweep stated | Agrees |
| --- | --- | --- | --- |
| Test suite | **906 passed, 6696 assertions**, 72 files | 889 passing | **no — L-01** |
| Registered routes | **266** = 263 under `api/v1/` + 3 framework | 262 | **no — L-01** |
| Routes under `admin/` | **174** | 173 | **no — L-01** |
| OpenAPI paths | **221** | 221 | yes |
| OpenAPI schemas | **54** | 54 | yes |
| Migrations | **38** | 38 | yes |
| ADRs | **42 numbered** (43 files incl. index) | 42 | yes |
| Release gate | **NO-GO**, four blockers open | NO-GO | yes |
| Remote | `origin` → `github.com/Upupapp/taytay-backend`, **public** | public | yes — **F-28 stands** |

The three non-`api/v1` routes are framework-owned: `GET|HEAD sanctum/csrf-cookie` and
`GET|PUT storage/{path}`.

### Findings raised by this command

**L-01 — the sweep's counts for this repository are wrong; the measured ones supersede them.**
The suite declares exactly **906 `#[Test]` attributes** across 72 files and uses **no data
providers**, so 906 is unambiguous and 889 cannot be reconciled with this HEAD under any
counting convention. This repository is at the 48 commits the sweep itself recorded, so this is
a mis-measurement rather than drift. Routes measure 263 under `api/v1/` against a stated 262
and 174 under `admin/` against a stated 173.

**TAB 05 must build its 147-row mapping from `php artisan route:list` and `openapi.json`, never
from the sweep's figures.** The master command already instructs this; L-01 is the evidence for
why it does.

**L-02 — the test suite needs `memory_limit` above the PHP CLI default, and nothing in this
repository says so.**
On a clean clone at PHP's default 128M,
`Tests\Feature\Api\V1\MediaSecurityTest::a_derived_rendition_is_bounded_by_its_variants_longest_edge`
exhausts memory inside GD at `modules/Files/Domain/ImageDerivative.php:102`
(`imagecreatetruecolor`). The run dies with *"Premature end of PHP process"* — a fatal rather
than a test failure, so the surrounding exit status does not reliably report it. With
`-d memory_limit=1G` the same clone passes **906/906**.

CI is green because its PHP image raises the limit; a laptop with stock settings is not. **Not
fixed in this command** — TAB 00's guardrail is *"Do not fix anything in this command. Its only
product is a safe, reproducible, measured starting line."* The fix belongs in `phpunit.xml` or
the setup documentation, and is carried to TAB 18's production configuration checklist. It also
sharpens **F-26**: image derivation is not merely slow inline, it is memory-hungry enough to
kill a default PHP process.

**Confirmed at baseline — F-08, the P1 contract defect, reproduces exactly.**
Both generators publish the PHP case *name* instead of the backing value:

- `modules/Shared/Support/OpenApiGenerator.php:189` — `static fn (ErrorCode $code): string => $code->name`
- `modules/Shared/Console/GenerateTypesCommand.php:98` — `"'".$code->name."'"`

Read and confirmed in place, unmodified. TAB 01 owns the fix and the test that must be watched
failing before it is trusted.

### Secret scan — full history

`gitleaks` is unavailable on this machine and no package manager exists to obtain it, so an
equivalent scanner was written for this command and is committed at
`docs/integration/tools/secret-scan.php`. It reads `git cat-file --batch-all-objects --batch`,
so it inspects **every blob ever committed on any branch**, not the working tree, and
attributes hits to paths via `git rev-list --objects --all`.

**953 blobs seen, 953 text blobs scanned, 3 findings — all synthetic.** Each was read to
confirm it:

| Location | Value | What it is |
| --- | --- | --- |
| `tests/Feature/Api/V1/CredentialLeakageTest.php:64` | `correct-horse-battery-staple` | the password posted by `a_password_never_reaches_the_log_or_the_response` |
| `tests/Feature/Console/ReadinessCommandTest.php:98` | `postgres://***:***@…` | the **expected redacted** form |
| `tests/Feature/Console/ReadinessCommandTest.php:99` | `postgres://lguids:hunter2@…` | its input, in `it_redacts_credentials_out_of_driver_errors` |

**Verdict: clean.** Every hit is a fixture inside a test that exists to prove credentials do
*not* leak. **No live credential is present in this history, so nothing requires rotation on
disclosure grounds** — which matters here specifically, because this repository's history is
already published and TAB 00 treats its scan as remedial rather than precautionary.

This does not make publication harmless. What is published is the schema of a welfare registry,
a 61-key authorization model and the privacy design — which is what F-28 is about, and it is
untouched by the scan being clean.

### Clean-clone reproduction

Cloned fresh to a scratch directory on a machine that had never seen this repository.
`composer install --no-interaction --prefer-dist` succeeded; `.env` seeded from `.env.example`
plus `php artisan key:generate`; `php -d memory_limit=1G vendor/bin/phpunit` returned
**906 passed, 6696 assertions** — identical to the configured working copy. Subject to L-02.

### Decisions

**D-00-01 — Visibility: this repository is public; the recommendation is private; the change is the owner's to make.**
Measured: unauthenticated `GET https://api.github.com/repos/Upupapp/taytay-backend` → **HTTP 200**.
The console repository is now **also public** (it had no remote at all when the sweep ran), so
the exposure F-28 describes has widened rather than narrowed since 18 August.

The recommendation follows the master command's guardrail and RA 10173's requirement that a
personal-information controller adopt organizational measures proportionate to the sensitivity
of what is processed — here, VAWC, child-protection and medical records. Publishing the design
of that system should be a decision on the record, not a default.

Repository administration is outside the boundary authorized for this work (no push, no remote
administration, no deployment), so **this entry records the recommendation and the evidence and
does not execute it**. While the repository stays public, the scanner above is a **standing
pre-push gate**, not a one-off, and no environment file, fixture or seed may carry a real value
— confirmed true today.

*Open action, owner: repository owner. Blocks the TAB 19 gate line for TAB 00.*

**D-00-02 — The measured baseline is authoritative where it disagrees with the sweep.** See L-01.

### Open, not done

| # | Step | Why | Owner |
| --- | --- | --- | --- |
| 2/3 | Protect `main`; settle visibility | Remote administration, outside the boundary | Repository owner |
| 4 | Provision PostgreSQL 18, Redis, MinIO, Mailpit | Absent from this machine; no Docker, no package manager | Deployment |
| 5 | `php artisan migrate` against **real PostgreSQL** | Blocked by the above. All 38 migrations do execute — the suite runs every one on in-memory SQLite — but Postgres-specific behaviour is unproven, which is precisely what release-gate blocker 4 already names | Deployment |
| 6 | Seed a usable dataset across every role | Needs a database. `DemoDataSeeder`, `AccessControlSeeder`, `BarangaySeeder` exist and are the starting point | Backend |
| 7 | Stand up staging to `docs/architecture/deployment-topology.md` | Deployment action, outside the boundary | Deployment |

Steps 1, 8 and 9 — scan, ledger, baseline — are complete.

### Verdict

**TAB 00 — locally complete, environmentally blocked.** This repository has now been run,
tested and measured by the integrator, its full history is proven clean of credentials, and the
baseline is written down. It has never been run against PostgreSQL on this machine, and it
remains public.
