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
| `tests/Feature/Console/ReadinessCommandTest.php:98` | a redacted Postgres DSN | the **expected** output |
| `tests/Feature/Console/ReadinessCommandTest.php:99` | a Postgres DSN carrying a fake password | its input, in `it_redacts_credentials_out_of_driver_errors` |

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

---

## TAB 01 — Contract reconciliation (backend half)

| | |
| --- | --- |
| Date | 18 August 2026 |
| HEAD at start | `d767759` |
| Severity | P0 — six of the eight divergences |
| Status | Backend steps 1–3 complete. Console half recorded in the console's ledger |

### Precondition, and how it was met

TAB 01 states its precondition as *"a running backend to observe, not merely a document to
read."* No staging API exists and no PostgreSQL runs on this machine (TAB 00, *Open, not done*).
The backend **is** runnable here, so every claim below was taken from the application itself —
the router, the response builder, the paginator and real HTTP round-trips in the test suite —
rather than from `conventions.md`. Where a live staging call is required, the acceptance
criterion is recorded as deferred rather than claimed.

### Step 1 — the published error vocabulary, fixed at its source

`ApiResponse::error()` has always put `$code->value` on the wire. Both generators published
`$code->name`. The wire was never wrong; the contract was.

| File | Was | Now |
| --- | --- | --- |
| `modules/Shared/Support/OpenApiGenerator.php` | `static fn (ErrorCode $code): string => $code->name` | `=> $code->value` |
| `modules/Shared/Console/GenerateTypesCommand.php` | `"'".$code->name."'"` | `"'".$code->value."'"` |

Both artefacts regenerated and committed. `openapi.json` and `types.ts` now carry
`VALIDATION_FAILED`, `UNAUTHENTICATED`, `FORBIDDEN` — thirteen codes, matching
`ErrorCode::httpStatus()` and the canonical table in `conventions.md` §4.

### Step 2 — a gate that has been watched failing

Added `ApiContractTest::every_error_code_the_api_emits_is_published_with_the_value_it_emits`.

**Why the existing suite missed this for the life of the defect.** `ApiContractTest` already had
`every_enum_value_a_client_can_observe_is_documented`, and it already drove *real* responses
rather than re-reading the enums — but `observedEnumValues()` only ever exercises **successful**
endpoints, so no error body was ever inspected. Meanwhile `lguids:openapi --check` and
`lguids:types --check` compare the generated document to the committed one: they verify
**currency, never correctness**, and they agree with each other whichever string the generator
picks. Three green gates, none of which could see it.

The new test is built so it cannot restate the bug:

- **Half one — genuine HTTP round-trips.** `GET /api/v1/me` unauthenticated (401),
  `POST /api/v1/auth/tokens` with an empty body (422), `DELETE /api/v1/health` (405), and an
  authenticated `GET` of a random resident UUID (404). Each response's `error.code` is read from
  the body and must appear in both published artefacts. This proves the renderer under test is
  the one the router actually reaches.
- **Half two — exhaustive.** Every `ErrorCode` case is rendered through `ApiResponse::error()`,
  the single builder every endpoint is required to use, and the `code` is read back **out of the
  rendered JSON**. The assertion never mentions `->name` or `->value`, so a generator that
  regresses cannot agree with it.

`types.ts` is parsed from the committed file rather than regenerated, because a consumer
vendoring it reads exactly those bytes. TAB 06 turns that into a build-time guarantee.

**Mutation transcript — the gate was proven red before it was trusted.** The defect was
reintroduced in both generators and the artefacts regenerated, as a careless commit would:

```
mutation applied  → published now: BadRequest, Unauthenticated, Forbidden
FAILED  ApiContractTest::every_error_code_the_api_emits_is_published_with_the_value_it_emits
  The API returned `UNAUTHENTICATED` over HTTP and openapi.json does not publish it.
  Published: BadRequest, Unauthenticated, Forbidden, NotFound, MethodNotAllowed, …
  Failed asserting that an array contains 'UNAUTHENTICATED'.
```

The fix was then restored, the artefacts regenerated, and the test returned green
(46 assertions).

### Step 3 — the pagination contract, published rather than described

Three defects, all closed:

1. **The `Pagination` schema was referenced by nothing.** Zero `$ref`s. Every response declared
   `meta` as a bare `{"type":"object"}`, so a client generating from `openapi.json` received an
   opaque object and had to read prose to learn the shape — which is precisely how the console
   came to invent `meta.pageSize`.
2. **`has_more` was served but never published.** `Page::meta()` emits
   `page, per_page, total, total_pages, has_more`; the schema listed four of the five, and
   `types.ts` did the same.
3. **Nothing distinguished a collection from a single resource.** Both were `data` + untyped
   `meta`.

Now: `Pagination` carries all five keys, all required, with the clamping rule stated
(default 25, maximum 100, out-of-range clamped rather than rejected). A new `Meta` schema
requires `request_id` and optionally holds `pagination`; `PaginatedMeta` composes it and makes
`pagination` required. `responsesFor()` references `PaginatedMeta` when the annotation says
`paginated`, `Meta` otherwise, and types `data` as an array for the paginated case.

**257 references to `Meta`, 7 to `PaginatedMeta`** in the regenerated document, against zero
before.

### Verification

| Check | Result |
| --- | --- |
| `php -d memory_limit=1G vendor/bin/phpunit` | **907 passed, 6742 assertions** (906 + the new gate) |
| `vendor/bin/pint --test` | **passed** |
| `lguids:openapi --check` / `lguids:types --check` | current |
| Published error codes | `BAD_REQUEST … SERVICE_UNAVAILABLE`, 13 values, matching the wire |

Pint also reformatted `docs/integration/tools/secret-scan.php`, committed in TAB 00 before Pint
had seen it. The scanner was re-run afterwards to confirm the reformatting did not change its
behaviour.

**Ledger hygiene, noted for the standing pre-push gate:** the TAB 00 entry originally quoted the
literal fixture strings it certified as synthetic, which made the scanner flag its own ledger.
The quotations are now described rather than reproduced, so the gate stays low-noise.

### Guardrails observed

- **The backend was not bent to the console.** Nothing in the envelope, the pagination shape, the
  error vocabulary or CORS was changed to make a console request succeed. The only backend
  changes are: publish what is already served, and add a test.
- `supports_credentials` untouched; CORS not widened; Sanctum stateful domains not enabled.
- No domain model, controller, route or migration touched.

### Deferred — needs a live environment

- The acceptance criterion *"a single live call from the console to `GET /api/v1/health` and one
  authenticated list endpoint returns parsed, correctly-paginated data in staging"* cannot be
  met here: there is no staging API and no PostgreSQL. Carried forward.
- A network trace of a successful paginated call, and before/after screenshots of a validation
  failure, likewise.

### Verdict

**Backend half — complete.** The published contract now describes what this API actually serves;
the defect that made every client's error handling dead code is fixed at its source; and the gate
that catches its return has been watched failing.

---

## TAB 02 — Authentication and session (backend half)

| | |
| --- | --- |
| Date | 18 August 2026 |
| HEAD at start | `eec71e6` |
| Severity | P0 |
| Decision record | [ADR 0043](../adr/0043-mandatory-second-factor-and-the-refresh-question.md) |

### L-04 (P1) — the second factor was opt-in by enrolment

`AuthenticationService::signInWithPassword` read
`requiresMultiFactor() && confirmedTotpFactor() !== null`. A staff account that **required** a
second factor but had never **enrolled** one fell through to a full 12-hour session on a password
alone. Nothing ever prompted enrolment, so the factor was optional in practice for the lifetime of
the account.

**Three existing tests asserted this behaviour** — `staff_can_sign_in_with_a_password`,
`authentication_alone_does_not_widen_what_a_caller_can_see`, and
`a_reset_token_works_once_and_revokes_every_session` all expected `201` and a working token for an
unenrolled account. That is why it survived a 906-test suite: the suite encoded the bypass as the
expectation. All three now model a real staff member, and the change is covered by two new tests.

### L-05 (P1) — token abilities were assigned and never checked

`TokenService::abilitiesFor()` has always stamped `['staff']` or `['citizen']`. A search of the
whole repository found **no `tokenCan()`, no `ability:` middleware and no route constraint**. The
grant read like enforcement where it was issued and enforced nothing where it was used.

Not exploitable on its own — staff/citizen separation is enforced by permissions and
`ScopeResolver` — but it blocked the fix for L-04, because a *restricted* token is only safe if
abilities are real.

### What was built

| Change | File |
| --- | --- |
| `mfa-enrolment-required` status + enrolment-scoped token | `modules/Identity/Application/AuthenticationService.php` |
| `issueForMfaEnrolment()` — ability `mfa-enrolment`, 15-minute TTL | `modules/Identity/Application/TokenService.php` |
| Global, deny-by-default ability enforcement | `modules/Shared/Http/Middleware/EnforceTokenAbilities.php` |
| Registered after `auth:sanctum` | `bootstrap/app.php` |
| `identity.mfa.enrolment_ttl_minutes` | `config/identity.php` |

Refusing the sign-in outright would have been a lockout rather than a control: `POST me/mfa` is
itself authenticated, so an office with nobody enrolled would have had no route to compliance. The
account instead gets a token that reaches enrolment and nothing else.

**One correction found by testing.** The first version of the middleware refused *any* route,
which broke `POST auth/tokens` — Sanctum resolves a bearer token even on a public route, so a
staff member who had just enrolled could not sign in again. The restriction now applies only where
a route demands authentication.

### Steps 9 and 10 — verified

- **MFA enforced for every staff role:** yes, now. `requiresMultiFactor()` is a property of the
  account *type*, so it covers every staff role uniformly; no role can opt out, and no account can
  reach a working session without a confirmed factor.
- **Token lifetime:** staff 12 hours (`IDENTITY_STAFF_TOKEN_TTL`), citizen 30 days, both in
  `config/identity.php` with the reasoning beside them. The staff figure matches NIST SP 800-63B's
  AAL2 reauthentication requirement.
- **Idle timeout:** **not implemented.** NIST SP 800-63B requires 30 minutes of inactivity at AAL2
  as well as the 12-hour absolute bound. Only the absolute bound exists. Recorded as an open item
  and carried to TAB 13/15 — it needs a decision about whether idleness is measured server-side
  per token or by the console, and the console's memory-only token already bounds a shift.
- **Sign-out revokes server-side:** yes — `DELETE auth/tokens/current` deletes the token row.
- **Password reset revokes every session:** yes, asserted by
  `a_reset_token_works_once_and_revokes_every_session`.

### Step 8 — refresh, decided and deliberately not built

See ADR 0043 §4. Every location a refresh credential could occupy is refused by an accepted
decision — web storage, a cookie, a URL, a BFF — and ADR 0006 states that its own residual XSS
exposure *"is unmitigated"* until the CSP is deployed, which is TAB 13's. Adding a second,
longer-lived browser credential before the mitigation the first depends on exists would widen an
open risk to buy convenience. Sequenced after TAB 13, with the leading design recorded.

### Verification

| Check | Result |
| --- | --- |
| `php -d memory_limit=1G vendor/bin/phpunit` | **909 passed, 6758 assertions** (907 → 909) |
| `vendor/bin/pint --test` | passed |
| `lguids:openapi --check` / `lguids:types --check` | current |

### Carried forward

- **TAB 19 runbook:** every existing staff account without a factor will meet an enrolment-only
  session at cutover. That is correct, and it is a support event — enrolment must be run **before**
  go-live, not discovered on the first morning.
- **TAB 13/15:** the idle timeout.

---

## TAB 03 — Authorization convergence (backend half)

| | |
| --- | --- |
| Date | 18 August 2026 |
| HEAD at start | `cc2ae05` |

### The renames

Six keys reached the canonical form — one kebab-case `resource.action`, two segments. None changes
what is granted.

`resident.link_review` → `resident.link-review` · `vulnerability.view_protected` →
`vulnerability.view-protected` · `services.view_unpublished` → `services.view-unpublished` ·
`document.view.sensitive` → `document.view-sensitive` · `referral.disclose.protected` →
`referral.disclose-protected` · `report.export.person-level` → `report.export-person-level`

**No data migration was needed, and that was checked rather than assumed.** Role→permission
mapping lives in `Role::permissions()` — code, not rows; `role_assignments` stores role names
only. The one place permission strings are persisted is
`report_exports.permission_context.permissions`, and those are **deliberately not rewritten**: the
column is a snapshot taken at export time, whose own comment says *"snapshotted, not looked up
later"*, because a person-level export produced last March must stay explicable in terms of what
was true then. Rewriting an audit snapshot to match a later vocabulary would be falsifying it.

Accepted ADRs were **not** edited. They record decisions as they were made; the old spellings stand
in them, and this entry plus the reconciliation table are the mapping.

### `PermissionVocabularyTest` — the gate

Three assertions: every key is kebab `resource.action`; no two keys differ only by punctuation
(`document.view_sensitive` beside `document.view-sensitive` reads as a second grant and enforces a
different one); and every role grants only keys that exist — a rename that missed a role definition
would leave that role silently short, and the symptom would look like a broken screen.

Mutation-tested: restoring `resident.link_review` turns the first red.

### Data scope — reconciled, and it already agreed

`role_assignments.scope_type` is a check-constrained enum of `all-barangays`, `own-barangay`,
`assigned-cases`. The console's `DataScope` is the same three strings. TAB 03 step 9 is therefore
**vocabulary-complete**; what remains is behavioural — proving `ScopeResolver` and the console's
scope agree in effect, which needs a running API.

### Not done here

The backend does **not** yet gain the console's finer keys (the newsfeed, event and disbursement
splits, and `document.view-full-number`). A permission with no enforcement point behind it is
decoration — which is exactly what L-05 was — so those land with the endpoints that enforce them,
in TAB 07. Recorded in the reconciliation table rather than added as dead vocabulary.

### Verification

| Check | Result |
| --- | --- |
| `php -d memory_limit=1G vendor/bin/phpunit` | **912 passed, 6761 assertions** |
| `vendor/bin/pint --test` | passed |
| `lguids:openapi --check` / `lguids:types --check` | regenerated and current |

---

## TAB 04 — The case collision (decision recorded; option-specific build outstanding)

| | |
| --- | --- |
| Date | 18 August 2026 |
| HEAD at start | `4eead78` |
| Decision record | [ADR 0044](../adr/0044-what-a-case-is.md), cross-referenced in the console |

### The decision

**Option A — two entities.** A case is the office's continuing involvement with a household; an
assistance request is one intervention inside it. **Supersede, not merge**, for duplicate identity.

Recorded as *accepted in principle, pending MSWDO ratification*: the working session the command
asks for has not happened, and the ADR says so rather than implying a mandate. Deciding early is
made safe by building only what is true under **all three** options.

### L-07 — the two case vocabularies overlap on exactly one state

Measured: `assessment` appears in both the console's 7-state case catalog and the 13-state
assistance lifecycle, and nothing else does.

**That is worse than disjoint sets.** A `CaseRepository` pointed at the assistance route would
render that one status correctly and blank the other twelve — and a screen that is *partly* right
invites the conclusion that the data is incomplete rather than that the wiring is wrong. It is the
"looks like success when wrong" failure with a plausible cover story. Pinned by test at exactly
one; a second coincidence must be a decision, not two teams reaching for the same English word.

### Implemented — safe under every option

**`admin/cases` → `admin/assistance-requests`, all 30 routes.** Not part of the choice:

- **ADR 0007 §2 already specifies** `POST /assistance-requests/{id}/transitions`. The
  implementation had drifted to `admin/cases` — recorded by the sweep as F-23. This is conformance
  to an accepted decision, not a new one.
- Under A, `welfare_cases` *is* the assistance request and a new entity becomes the case; under B
  there is only the request; under C this rename is the work. The current entity is the assistance
  request in all three.

912 tests pass unchanged. `me/cases` is deliberately **not** renamed: it is consumed by a shipped
Flutter client, `/api/v1` is stable, and ADR 0007 §3 already projects a citizen vocabulary. That
rename belongs to `/api/v2`.

### Step 4 — the four status vocabularies, measured

| Vocabulary | Console | Backend | Result |
| --- | --- | --- | --- |
| Assistance request | 13 | 13 | identical |
| Referral | 8 | 8 | **identical** |
| Field visit | 5 | 5 | **identical** |
| Enrolment | 3 | 3 | **identical** |
| Release / disbursement | **9** | **6** | **3 shared — diverges** |

Three of the four needed no reconciliation at all. The fourth is a real divergence and not a naming
slip: the console distinguishes `unclaimed` from `deferred` because `DL-94` holds that **deferred
is the office's failing and unclaimed is nobody's**. Mapping `unclaimed` onto the API's `failed`
would blame a household for the office's missing countersignature, and the record would read that
way to every worker afterwards. The console also has no catalog entry for `ready`, `failed` or
`cancelled`, so a release in any of them would render blank today.

TAB 08 owns it. Pinned by test so the gap cannot widen in the meantime, and so nobody wires the two
together believing they match.

### Outstanding — waits on ratification

The continuing-involvement module (migration, entity, endpoints, authorization, tests,
`continues_case_id`, append-only event log); the six permission keys TAB 03 held back; and the
citizen-facing projection — `welfare_case_events.is_citizen_visible` / `citizen_message` against a
console `CaseEvent` that has no such concept, so a caseworker cannot yet tell which of their notes
a resident will read.

### Verification

Backend **912 passed, 6761 assertions**; Pint clean; artefacts regenerated after the route rename.
Console **77 files, 1491 tests**, 22 checks, clean build.

---

## TAB 05 — L-11: the contract publishes no resource shapes

Measured while preparing the console's per-resource mappers.

`openapi.json` carries **221 paths and 56 schemas — and 52 of those schemas are enums.** The other
four are `Error`, `Meta`, `PaginatedMeta` and `Pagination`. **No resource shape is published at
all.** Every response documents `data` as an untyped object.

A client generating from the document therefore receives the envelope, the error vocabulary and
the enums, and nothing whatever about what comes back inside `data`. That contradicts the first
acceptance criterion `ApiContractTest` states for itself — *"a frontend developer can build
without reading backend code"* — because the field names live in private `*Projection()` methods
inside controllers, and the only way to learn them is to open the PHP.

**This is why TAB 05's remaining steps stop here rather than proceeding.** Writing twenty
per-resource `snake_case` → domain mappers against a contract that does not describe the payloads
would mean inventing the field names — which is precisely the failure step 1 caught 28 times when
routes were inferred rather than measured. A mapper built on a guessed field name compiles,
typechecks, and yields `undefined` at runtime; TypeScript cannot see it because the envelope is
cast. That is divergence D7 all over again, reintroduced by the fix for it.

**What was done instead.** `docs/api/wire-shapes.md` extracts the field names from the projection
methods that build them — **59 projections, 565 fields**, measured rather than transcribed. The
console's mappers can now be written against real names, and the four client teams have the
vocabulary they need today.

**What it does not do.** It is a document, not a contract: nothing fails a build when a projection
changes. Publishing the shapes into `openapi.json` and `types.ts` is TAB 06's work — *"consume the
generated types … a backend enum change then becomes a TypeScript error in the console rather than
a runtime surprise"* — and the same argument applies with more force to field names than to enums.

Recorded rather than fixed here for the same reason TAB 01 fixed the error vocabulary at its
source and left the console's adapters to TAB 05: the generator is the right place, and it is the
next command's scope.

### L-11 closed at its source — the contract now describes its payloads

Rather than leave `wire-shapes.md` as the answer, `OpenApiGenerator::payloadShape()` reads the
field names out of the same `*Projection()` methods that build the response — the same reasoning
that has always read enums from `cases()`: a shape the document cannot describe wrongly is better
than one somebody remembers to update.

**171 of 263 responses now describe their payload — 2,266 field declarations, against zero
before.** The remaining 92 shape their payload inline rather than through a projection, and are
left *undescribed rather than half-described*.

**One correction the first version needed.** A detail projection is routinely
`listProjection($x) + [ … ]`, and reading only its own literal keys published the extras and
dropped the base: `GET /admin/assistance-requests/{case}` came out as **10 fields when it carries
21**. A confidently partial shape is worse than an absent one — a client trusts it and meets the
missing half at runtime. `projectionKeys()` now resolves inherited keys, depth-limited rather than
cycle-detected, because a generator that can loop forever on malformed input is one that hangs CI.

**Names, not types.** The projections build plain arrays, so each value is published untyped. That
limit is deliberate and stated: a wrong type published with confidence would be worse than an
absent one.

Two gates added, and mutation-tested (blanking the extraction turns the first red):

- `a_client_can_learn_what_a_payload_contains_without_reading_php` — criterion 1 asserted for the
  first time in this class's life.
- `a_described_payload_carries_the_fields_it_inherits` — the partial-shape regression specifically.

**912 → 914 tests, all passing.** Pint clean, artefacts current.

This is what unblocks TAB 05's remaining mapper work: the console can now write per-resource
mappers against **published** field names rather than invented ones.

---

## L-15 (P2) — `barangay_id` is exposed as an auto-increment key

Found by pointing the console's mappers at responses **recorded from the API actually running**,
which is the whole reason TAB 05 step 10 asks for recorded responses rather than fixtures.

```json
{ "id": "01a013cd-2009-7226-8b7f-829e5d811a4f", "barangay_id": 2, ... }
```

The `id` is a UUIDv7, correctly. `barangay_id` is **`2`** — the raw auto-increment primary key,
on residents and households alike.

`docs/api/conventions.md` §6 states: *"Identifiers exposed to clients: UUID strings.
Auto-increment primary keys are internal and must never appear in a payload."* The backend's own
CLAUDE.md Article 4 repeats it, and TAB 07's guardrails say it again: *"Never expose an
auto-increment key."*

### Why this one is worth the space

**Nothing the console had could have caught it.**

- The hand-written fixture used a string, because that is what the console's own mock used — the
  mock and the mapper were written by the same hand, from the same assumption.
- The **published schema could not disagree**: payload properties are declared untyped (`{}`), so
  there was no type to conflict with.
- TypeScript could not see it: the envelope is cast at the boundary. That is divergence D7's exact
  mechanism.

Against the real payload, `toResident` required a string, found a number, returned `null` — and
**every resident and every household would have been dropped**, silently, on the console's two
busiest screens. A screen showing "no residents found" against a populated registry is the kind of
failure an office reports as "the system is broken" and an engineer spends a day not reproducing.

### What was done, and what was not

The console tolerates it **narrowly and visibly**: `idTolerantOfNumeric()`, used at exactly two
call sites, with the violation named in its doc comment. The shared `id()` primitive is
**unchanged** — widening that would normalise auto-increment keys across the whole mapping layer
and quietly retire a convention the API is supposed to keep.

The tolerance is one-way: a proper UUID passes through unchanged, so nothing in the console needs
touching when the backend fixes it.

**The fix belongs to the backend and is TAB 07's** — expose the barangay's UUID, or its code.
Recorded rather than patched here, because the console is not the place to decide what an
identifier is.

### The wider point about step 10

This is the first defect in the whole sequence found by *running the thing*. Every earlier one
came from reading two descriptions against each other. The command's wording is exact —
*"not hand-written fixtures, which drift toward what the author expected"* — and the drift here
was not carelessness: the fixture matched the documentation, the schema, and the mock. It just did
not match the API.

---

## L-16 and L-17 — the assistance request cannot be constructed either

Found by creating a real assistance request through the API and reading it back, which is
something no amount of document-comparison would have surfaced.

`POST admin/assistance-requests` returns 201 and a 21-field payload matching the published shape.
Two things are missing from it, and from the table behind it.

### L-16 (P1) — there is no field for why the household applied

`AssistanceRequest.reasonForRequest` is a **required string** in the console's domain. The payload
has no such field, and `welfare_cases` has **no narrative or reason column at all** — only
`priority_reason`, which is about urgency, not need. It is not withheld behind a permission; it
does not exist.

A `narrative` sent on create is simply not a field the endpoint accepts, so it is ignored.

**A console that cannot show why a household applied cannot support the decision it is asking a
social worker to make.** The whole assessment screen is built around reading what was asked for.

### L-17 (P1) — the assistance request carries no money

`requestedAmount` and `approvedAmount` are core domain fields on the console's model. There is no
amount, currency or minor-unit field anywhere in the payload, and none in the table. Money lives
on `releases`.

That may be the right model — an amount is arguably a property of what was released rather than
what was asked — but it is **a different model from the one the console has**, and TAB 08 has to
settle it before either side is wired. It is not a mapping problem.

### The consequence

`AssistanceRequestRepository` joins `ProgramRepository` on the list of ports that **cannot be
satisfied from the current API** — not because a route is missing, but because the payload cannot
fill the model. `programId` is typed non-nullable in the domain and arrives `null`;
`reasonForRequest` is required and has no source at all.

No mapper was written. Instead the gaps are **pinned by test** against the recorded payload
(`recorded/assistance-request.spec.ts`), so they are facts somebody can check rather than claims
in a document — and so the day the backend closes them, the tests fail and tell somebody the
mapper can now be written.

### Something the payload does well, worth saying

`available_transitions: ["submitted", "cancelled"]` — the server tells the client which
transitions are legal, so the console never re-derives the transition map. That is the staff-side
equivalent of ADR 0007's `available_actions`, and it is exactly the right shape.

### Also confirmed

`barangay_id` is a number here too (L-15), on a third resource.

---

## Money — walked end to end, and it is where the two sides disagree most

An assistance request was walked `draft → submitted → intake-review → assessment → endorsed`,
approved by a **second** officer, and a cash release scheduled and confirmed against it. Everything
below was observed, not inferred.

### L-19 (P0 for TAB 08) — the API blocks self-release; the console warns

Two refusals, both at the person level rather than the permission level:

- `endorsed → approved` by the endorser: **`The person who endorsed a case may not approve it.`**
- Confirming a release by the approver: **`The person who approved this assistance cannot also release it.`**

The second is the console's `DL-91` exactly — and the console takes the opposite position:

> `isSelfRelease` … the screen warns before the money moves. It **warns rather than blocks**: a
> small office on a bad day may have one person available, and refusing the payout punishes the
> family for the office's staffing.

Both are defensible and **they cannot both be executed.** This is TAB 04's shape — a doctrinal
conflict, not a naming one — arriving on the surface where being wrong costs a family their
payout.

It is worse than a disagreement in the abstract: the console's release screen is built to warn and
then proceed. Against this API the proceed always fails, so **the warning becomes a lie** — it
tells an officer they may continue with care, and the server refuses. A one-officer office on a
bad day gets a family turned away and no way forward in the product.

**This is the office's decision, not engineering's**, and it belongs with the TAB 04 session:
either the API relaxes to warn-and-record (with the self-release audited, which is what the console
assumes), or the console stops offering the path and says plainly that a second officer is
required.

### L-20 (P1) — `available_transitions` advertises a transition the endpoint refuses

The release payload says `available_transitions: ["released", "failed", "deferred", "cancelled"]`.
`POST admin/releases/{id}/status` accepts `in:completed,failed,deferred,cancelled,ready` —
**`released` is not among them.** Handing money over goes through `POST .../confirmation`.

A client doing exactly what the payload tells it gets a `422`. That matters more here than
elsewhere, because `available_transitions` is precisely the mechanism that lets the console stop
re-deriving a transition map — the thing this integration has been praising. On the one surface
where being wrong moves money, the advertised set and the accepted set differ.

### The release vocabulary divergence, now live

Every release is created in **`ready`** — one of the three API statuses the console has no catalog
entry for. So on the current vocabularies the first thing a disbursing officer sees is a **blank
status chip**, on every release. TAB 04 step 4 found this in the enums; this is it on the wire, and
it is the default case rather than an edge case.

### What agrees, and it is the part that matters most

- **Money is `amount_centavos` plus `currency`** — integer minor units on both sides, no floating
  point anywhere in the chain.
- **Goods are counted, never valued.** `in_kind_description` with no amount, exactly `DL-93`.
- **`Idempotency-Key` is accepted on confirmation**, which is TAB 05 step 8's requirement arriving
  from the other direction: the API was already built for the retry semantics the console now
  sends.
- `approval_reference` and `funding_source` line up with `approvingReference` and
  `fundingSourceLabel`.

On the arithmetic of money the two sides agree completely. They disagree about **who may press the
button**, which is a policy question rather than a technical one.

---

## L-18 (P0, **CLOSED**) — a sign-in code is issued, recorded, and never delivered

Found by the resident mobile app's integration sequence and confirmed here against
this API running locally on sqlite. No resident can sign in to the platform.

`AuthenticationController::requestCode` calls `requestSignInCode`, which issues a
code and stores its hash, and then does `unset($code)` — deliberately, so it is
neither returned nor logged. That half is right. The other half does not exist:
**nothing dispatches it.**

Measured, on this machine, with the API serving:

```
POST /api/v1/auth/otp {"mobile_number":"+639170000001"}   → 202
verification_codes                                        → 1 → 2   (issued)
notifications                                             → 0       (nothing recorded)
no SMS, no push, no mail, no log line carrying the code
```

Sign-in **does** work once the code is in hand — proven end to end by reading it
from `AuthenticationService` directly, exchanging it at `auth/otp/verify` for a
token, and calling `GET me` successfully. Every part of the flow is built except
the one that reaches a person.

The comment at the call site says delivery waits on the `Notification` module.
That comment is stale: `Notification` has been implemented since TAB 20 and has
no awareness of sign-in codes.

### Why `Notifier::notify()` is the wrong seam for this

The obvious fix is wrong, and the reason is worth recording before somebody
writes it:

1. **`notify()` persists `title` and `body`**, and that row is read back over an
   authenticated API at `GET me/notifications`. A one-time code stored there is a
   credential in an inbox.
2. **The recipient is not authenticated yet.** A notification addressed to a
   subject who cannot read their inbox until they have used the code is
   circular.
3. `notify()` is inbox-shaped by design — category, priority, deep-link subject.
   None of that applies to a transactional message that must not be recorded.

What is needed is a narrow published contract on `Notification/Application` for
**transactional delivery that persists nothing** — send this text to this number
now, return whether it left. `Identity` may not reach into `Notification`'s
`Infrastructure/` (Article 2.1), so the channel dispatch has to be exposed
deliberately rather than borrowed.

### Closed — and the seam went somewhere the design above did not predict

`Modules\Shared\Contracts\TransactionalSender`, with `TransactionalMessage` and
`TransactionalDelivery` beside it. `AuthenticationService::requestSignInCode` sends through it
and **no longer returns the code at all** — it used to, with the controller doing `unset($code)`,
which worked and put the guarantee in the wrong file: the mint was in `Identity/Application` and
the discipline was in a controller, so nothing stopped a future reader from returning it. The code
now exists in one local variable and goes out of scope.

**The contract is in `Shared`, not in `Notification`, and that was not a preference.** It was
written in `Notification/Contracts` first, next to `NotificationChannel`, exactly as the analysis
above assumed. `ModuleBoundaryTest` refused it: `Notification` already depends on `Identity`, so
`Identity → Notification` closes a cycle — the test named **thirty-nine** of them in one run. The
resolution is the one `Modules\Shared\Contracts\AuditWriter` already uses for the same shape of
problem, and the one the boundary map prescribes: invert it. Shared holds the interface, which
depends on nothing; `Notification/Infrastructure/Transactional` holds the adapters.

Two adapters, and no third. `NullTransactionalSender` is the default and reports `skipped`, never
`sent`. `LogTransactionalSender` writes the code to the log and **refuses to construct** unless the
environment is local/testing/integration *and* `APP_DEBUG` is on — an allow-list alone is not
enough, because environment names are chosen by whoever writes the `.env`, while anything serving a
real resident has debug off. No provider adapter was written, because no provider has been chosen;
see the caveat below.

Measured, on this machine, with the API serving and `TRANSACTIONAL_SENDER=log`:

```
POST /api/v1/auth/otp {"mobile_number":"+639170000001"}  → 202
log: "Your Taytay LGU sign-in code is 456296. It expires in 5 minutes.
      If you did not ask to sign in, ignore this message."
     recipient logged as *********0001 — masked, and the code is not
POST /api/v1/auth/otp/verify {code: 456296}              → 201, Bearer token
audit: identity.code-issued → identity.code-sent → identity.token-issued
```

`tests/Feature/Api/V1/SignInCodeDeliveryTest.php`, 9 tests. The load-bearing one exchanges the
code **read out of the captured message** for a token, because "a message was sent" does not
distinguish a working delivery from one carrying the wrong six digits. Proven red by restoring the
defect verbatim — mint, `unset`, dispatch nothing — which fails four of them with *"Nothing was
sent. This is F16 exactly."*

**Why eighteen passing authentication tests missed this.** They asserted 202, and 202 is what a
platform on which nobody can sign in also returns. The response is deliberately identical whether
delivery succeeded, was skipped, or the number holds no account — anything else is an
account-existence oracle — so the API surface cannot carry this evidence and a test written against
it never will. The outcome goes in the audit trail instead, as `identity.code-sent` or
`identity.code-undelivered`, which is where an operator can see that nobody can sign in.

### The half that is not code — **THERE IS NO SMS PROVIDER**

Not unconfigured. Not chosen. `ChannelRegistry` binds `new NullChannel('sms')` and there is no
adapter for any provider anywhere in the codebase. **A real resident still receives nothing**, and
`NullTransactionalSender` is what a deployment binds today.

Selecting and contracting a Philippine SMS provider is a procurement decision for the LGU, with
credentials an agent must never hold. It is on the mobile repository's master manual-task list. The
seam it plugs into now exists, is bound by one line of config, and is covered by tests — so the
remaining work is a vendor and an adapter, not a design.

### Two more, from the same session

* **No citizen account exists in any seeder.** `DatabaseSeeder` and
  `DemoDataSeeder` create staff and catalogue data; the account used above had to
  be created by hand. Combined with the absence of any self-registration route,
  a fresh environment has nobody who can sign in — which makes reviewer access,
  UAT and acceptance testing impossible to set up without a manual database
  write.
* **`GET newsfeed` answers 401 to a guest** on a route carrying no
  `auth:sanctum`, because `NewsfeedController::assertReadable` gates anonymous
  readers on `newsfeed.public_access`, which defaults false. Reading the route
  file says public; calling it says otherwise. Any client that reasons from the
  route file — as the mobile app did — ships a feed that fails for signed-in
  residents too, because it sends no token by construction.

---

## ADR 0044's second decision, verified — and three refusals both sides make

### Supersede is implementable today, with no backend change

ADR 0044 chose **supersede over merge** on the reasoning that a merged record cannot be un-merged,
and a wrong finding about identity in a welfare registry means one household inherits another's
history. The sweep listed only `POST .../merge`, which made that look like a request for new
backend work.

It is not. Walked against the running API:

1. A near-duplicate resident was created and detection ran — one pair, rule `name-and-birth-date`,
   confidence `exact`.
2. `POST .../decide` with `same-person` and a note recorded `decision`, `decision_note` and
   `decided_at`.
3. **Both residents returned `200` afterwards.** Neither was deleted, neither deactivated.
4. Re-running detection reported `pairs_found: 1, undecided: 0` — the pair stops resurfacing as
   work without either record being destroyed.

That is `DL-74` exactly. **The doctrinal conflict the sweep recorded needs no backend change at
all** — `/merge` simply goes unused, and `/preview` belongs to it (it requires a
`survivor_resident_id`, which is a merge concept).

### L-21 (P2) — the review panel is handed values the console withholds

`DL-73`: *"A `MatchSignal` carries an attribute, an outcome and the rule applied — **never a
value** — so the review panel cannot leak a birth date it was never handed."*

The API sends the **full resident record on both sides of the pair**, birth date included. It does
name the rule it matched on, which is the part the console wanted — but the values come with it.

Not a bug: comparing two records is arguably what a reviewer is for. It is a different answer to
*how much must somebody see in order to decide this*, and the console's answer was reasoned about
rather than assumed. Recorded so it is decided rather than inherited.

### Search — three refusals, reached independently

| Rule | Console | API |
| --- | --- | --- |
| No snippet, context or matched text | `DL-109` | confirmed — the payload has none |
| No matching on free text | `NEVER_SEARCHED` | confirmed — two phrases existing **only** inside a visit observation and a referral reason returned **zero results** |
| A composed view, not records | `SearchHit` | confirmed — `type`, `id`, `title`, `barangay_id`, `status`, and nothing else |

The second is the one that matters. `DL-109`'s reasoning is that *"matching on free text discloses
it even with no snippet rendered: type a condition, get back one resident, and the office has said
what is in that person's file."* The API refuses the same thing, and neither side read the other.

### The tally that is worth keeping

Across the surfaces exercised live, the two sides **independently agree** on: the visit-observation
attribution rule (`DL-85`), the referral lawful-basis requirement (`DL-82`), all three search
refusals (`DL-109`), integer-centavo money with goods never valued (`DL-93`), and identity
resolution by finding rather than deletion (`DL-74`).

They **disagree** on: who may release money (`L-19`, the office's call), how much a duplicate
reviewer may see (`L-21`), and referral destination as controlled vocabulary versus free text
(`L-18`).

Every agreement is on a rule protecting a resident. Every disagreement is a policy question about
how an office works. That is a good shape for a project to be in — the hard parts were understood
the same way by two teams who never spoke.

---

## Newsfeed — the three rules about speaking outward, all held by the server

A post was drafted, published, and then probed for every way back.

### Publication is irreversible on both sides — `DL-124`

The console's rule is `published → archived` and nothing else: *"no unpublish, no retract, no
unsend: archiving removes a post from the feed going forward and reaches nobody who already read
it."*

Probed directly after publishing:

| Attempt | Result |
| --- | --- |
| `published → draft` | **409** invalid state transition |
| `published → scheduled` | **422** not an accepted value |
| `published → unpublished` | **422** |
| `published → retracted` | **422** |

`available_transitions` came back as exactly `["archived"]`. **The console does not have to enforce
this alone** — the server will not let a post be taken back either.

### Alt text is required to publish an image — `DL-125`, and the API is the more careful of the two

Posting media without it is refused:

> `The alt text field is required when is decorative is not present.`

The console's `PostImage.altText` is a **required string with no decorative concept**. The API
distinguishes the two, which is the WCAG-correct position: an image carrying no meaning should have
*empty* alt text, not an invented description of a divider.

Recorded as a **small console gap** rather than a divergence. Nothing breaks — a required string is
stricter, not wronger — but the console cannot express a distinction the API and WCAG both make,
and a caseworker attaching a decorative rule line will be asked to describe it.

### Reach is counts — `DL-126`

*"No method anywhere that could answer which residents reacted, read or shared. A field held 'for
later' is a field somebody displays."*

The entire reaction surface is `POST /newsfeed/{post}/reaction` and
`DELETE /newsfeed/{post}/reaction` — a resident acting on their own — and `admin/newsfeed-metrics`
returns counts of **posts by status**, not reach by person. There is no listing route.

The question is unanswerable at the API, exactly as the console leaves it unanswerable at the port.

**Not asserted by a test, deliberately.** What it claims is the *absence of a route*, and the
console repository holds no copy of the router to assert against — a test here could only compare a
hand-written list to itself and pass forever. TAB 06's contract suite is where an absence on the API
becomes checkable from this side. Until then it is a recorded observation, which is what it is.

### Running tally of independent agreements

Visit-observation attribution (`DL-85`) · referral lawful basis (`DL-82`) · all three search
refusals (`DL-109`) · integer-centavo money, goods never valued (`DL-93`) · identity by finding
rather than deletion (`DL-74`) · **publication irreversible (`DL-124`)** · **alt text required
(`DL-125`)** · **reach is counts (`DL-126`)**.

Eight rules, reached twice, by two teams who never spoke.

---

## TAB 06 — provider verification: the console's expectations, replayed here

The console vendors this API's generated types and fails its own build when they drift. That
protects the console from this repository and protects this repository from nothing.
`ConsumerContractTest` is the reciprocal.

### The expectations are generated, not written

`docs/api/consumers/taytay-admin-web.json` is produced **in the console** by
`tools/emit-consumer-expectations.mjs`, which parses its mappers: the `field(wire, '…')` reads and
the null-guards that decide whether a record survives. It is vendored here with its repository,
full commit SHA and `sha256`.

Hand-writing it would have created a *third* description of this API, beside the controller and
the mapper. Every divergence this integration has found — D1–D8, L-01 through L-21 — had exactly
that shape.

### What is gated, and what is only reported

A **required** field is one whose absence makes the console's mapper return `null`: the record is
dropped, with no error, no empty state and no log. The list is simply shorter.

**L-15 was this exactly.** `barangay_id` is required by `toResident`, this API sends the integer
`2`, the mapper wanted a string, and a resident list would have rendered empty against a healthy
API and a green suite in *both* repositories. So the test asserts the value as well as the key —
a required field arriving as `null` is the same silent drop.

Optional fields are reported and not enforced. Their loss degrades a screen rather than emptying
it, and gating on them would freeze every field this API has ever published.

### Two things the test found about this codebase

1. **No single role can read all eight endpoints, by design.** `audit.view` is deliberately withheld
   from `lgu_admin` — `Role::DataProtectionOfficer` exists so that the trail recording the MSWDO
   head's approvals is not read by the MSWDO head. Verifying through one all-powerful actor would
   have meant granting an administrator the permission a whole role exists to keep from them.
2. **Fixtures and reads need different actors.** The DPO may read the trail and register nobody. The
   test builds every fixture as `lgu_admin` and reads as whoever may see the endpoint — the
   separation working, not an inconvenience.

### Mutation-tested

| Planted regression | Result |
| --- | --- |
| `barangay_id` removed from the resident projection | **caught** |
| `barangay_id` kept but emitted as `null` | **caught** |
| `admin/residents` renamed to `admin/resident-records` | **caught** |
| the vendored expectations edited here to go green | **caught** |

A fifth case is handled by construction: an interaction with no reachable sample record **fails**
rather than being skipped. "Nothing to check" reads as "checked and fine" in a green suite, which
is the failure this TAB exists to stop.

Suite: **933 tests, 6,868 assertions**, `pint --test` clean — including three files left
unformatted by earlier TABs of this integration, now fixed.

### One consumer is verified; three are not, and the test says which

Four clients consume this API (Article 0): citizen web, citizen mobile, the admin console and
verifier devices. Only the admin console publishes expectations, so only the admin console is
verified — **the other three could each lose a field they depend on and this suite would stay
green.**

The consumer list is discovered from `docs/api/consumers/` rather than named in a constant, so
adding a client is a data change: drop the generated file and its provenance beside the existing
one. Naming a constant would have made a second consumer look like a code change and quietly
discouraged it. An empty directory **fails** rather than passing vacuously — mutation-tested by
removing both files.

`taytay-mobile-app` is on this machine and reads the wire in the same shape the console does —
`raw['field']` reads with null-guards that drop the record — across 5 DTOs. Generating its
expectations is a job for whichever TAB takes on that repository; it is outside the two this
Master Command joins, and starting it unasked would be widening the integration by a third
codebase.

---

## TAB 07 — backend gap closure

**Objective met:** every one of TAB 05's 36 `no counterpart` rows now has an endpoint, a mapping to
one that already existed, or a recorded decision. The triage is
[`tab-07-triage.md`](./tab-07-triage.md); this is what building it actually produced.

### The count

| Decision | Rows | Outcome |
| --- | --- | --- |
| Built | 19 | 16 new route patterns across 6 modules |
| Mapped to something that exists | 1 | `EventRepository.metrics` → `registration-summary` |
| Deferred to TAB 08 (money) | 3 | the noun is TAB 08's to decide before the endpoint is built |
| Blocked on ratification | 11 | the whole of `CaseRepository`; ADR 0044 awaits the MSWDO |
| Withdrawn | 1 | `NotificationRepository.create` — the API is read-only for the actor |
| **Total** | **36** | |

Routes: **266 → 284**. Suite: **933 → 1,015 tests**, 7,484 assertions, `pint --test` clean.

### What the command asked for, and where each rule landed

**"Do not create a second family model."** The family read side has no new table and no new entity.
The kinship history needed no event log either, because `family_memberships` and
`resident_relationships` are already effective-dated and append-only — the history was in the
database and had simply never been read back. An event table beside an effective-dated one is two
records of one fact, agreeing until somebody writes to one path and not the other.

**"A projection, never an entity."** No `beneficiaries` table, no beneficiary identifier, no stored
standing; the four standings are derived per read and are not exclusive. A stored standing would be
correct until the next case change and wrong until a job ran, and that window is exactly when
somebody checks whether a family has already been helped.

**"Read-only and derived server-side."** Nothing in the work queues writes. Acting on an item goes
to the task's own endpoints, which already audit — a queue that could also mutate is a second write
path to one record. Alerts are computed per read, so fixing the record clears the alert and there
is no dismiss.

**"Aggregate-first. Suppress small cells rather than rounding them."** Proven against rows rather
than asserted: one case in a barangay comes back withheld with a `null` total; five come back
published. Every reporting response now publishes the threshold and the method.

**"No grouping by caseworker."** `assigned_to` is refused on a report run and still permitted on the
dashboard. A dashboard is how a supervisor reviews a caseload they are responsible for; a report is
what gets pasted into a meeting pack, and a per-worker report is a league table however it was made.

**"Authorize every new route explicitly."** Four permission choices were made deliberately rather
than by default, and each is tested as an attack: the beneficiary registry is `program.view` not
`resident.view`; the team queue is `staff.view` not `task.view`; requirement *history* is
`program.manage` not `program.view`; duplicate findings are `resident.merge`.

**"Paginate every collection."** Including the ones where an argument for an exception was available
— a person's kinship history is small *in practice*, which is a guess about the busiest record
rather than a limit.

### Five defects found by building read sides

Each was found because something was read back that never had been.

1. **A programme requirement could be created once and never amended** (G-28, fixed).
   `storeRequirement` wrote `template_version => '1'` unconditionally against a unique key of
   (program_id, code, template_version), so the second publication of any requirement was refused
   by the database. An office that worded one badly had no way to correct it.
2. **`currentRequirements()` had no version filter despite its name** (fixed) — a programme detail
   would have shown the same requirement twice, once with wording already replaced.
3. **Naming a household head does not enrol them as a member** (G-23) — found by fixtures that could
   not join a family without household membership. Left open: it is a write-path change with a
   backfill behind it.
4. **`family_memberships` records no role** (G-22) — four of the console's six roles are unknowable,
   and none of them is guessed.
5. **A post records when it was archived and not why** (G-30) — the one question worth asking about
   a removed post is the one its history cannot answer.

### Two mistakes of mine, and what they cost

**The beneficiary projection landed in the wrong module.** I put it in `ResidentProfile`, reasoning
that the registry is the spine. `ModuleBoundaryTest` rejected it: `Welfare` already depends on
`ResidentProfile`, so importing back made the dependency graph cyclic. The inversion is also the
better answer on Article 6's terms — a beneficiary standing is a *welfare* fact about a person, and
each fact has one owning module. The architecture test earned its keep.

**The advisory silently found nothing.** `status` is cast to the `CaseStatus` enum on the model and
I compared it against `openValues()` with a strict `in_array`, which matched nothing. It returned
`200` with an empty signal list — and an advisory that finds no signals looks exactly like a clean
record, which is the worst failure mode this endpoint has. Caught by a test asserting the signals
were *not* empty, which is why that assertion exists.

### Where a second description was avoided

Twice the obvious move would have created a rival vocabulary, and both times the existing one was
extended instead:

* **Reports.** `Reporting\Domain\ReportCatalog` already existed for exports. Extending it also
  closed a drift: three reports were computed for the dashboard and had no catalogue entry, so they
  could be seen and not asked for.
* **Classifications.** Built from the same category list as the retention schedule, in the same
  config file. One set of record kinds, two facts about each — two lists are how they come to
  disagree about which categories exist.

### What is not built, and why

`CaseRepository` — eleven rows — is blocked on a working session with the MSWDO head, a social
worker and an intake officer. A case lifecycle is the office's description of its own continuing
involvement with a family, and building seven states to a model nobody has agreed would produce
exactly what the command's risk line names: **building to a guess instead of a measurement**. Here
the measurement is a decision that does not yet exist.

`admin/work/alerts` also cannot report duplicate residents awaiting review (G-26): `Tasks` may not
read `ResidentProfile`'s tables and that module publishes no contract for pending pairs. The alert
is absent and named rather than present and wrong.

---

## TAB 08 — money

P0, public funds. *"Where a retry must never become a second payout."*

### Step by step

| # | Requirement | State |
| --- | --- | --- |
| 1 | Reconcile the nouns | **Done** — the console adopted `release` (`DL-132`) |
| 2 | Map the state machines | **Done**, and it found a defect — see below |
| 3 | Idempotency on every money write | **Done**, and the key is now *required* |
| 4 | Separation of duties server-side | **Done** — attack test with one account holding both roles |
| 5 | Reasons where the domain requires them | **Done** — a blank is refused, not stored |
| 6 | Batches and manifests | **Done** — list and detail added; manifest already had export discipline |
| 7 | Integer centavos at every boundary | **Done** — tested with a value a float rounds wrongly |
| 8 | Concurrency, proven on real PostgreSQL | **NOT MET** — no PostgreSQL exists; recorded, not glossed |
| 9 | Reconciliation view | **Done** — totals by status, programme and period |
| 10 | Audit every act | **Done** — every movement leaves a transition carrying its reason |

### The noun, and why the API kept it

The API's `release` is three tables, a permission persisted in `role_assignments`, and seven URLs
under `/api/v1` read by four clients. Renaming those is a breaking change requiring `/api/v2` under
Article 4, plus a migration. The console's `disbursement` was 451 occurrences the TypeScript
compiler checks exhaustively — and its screens already said "Releases". The second vocabulary lived
entirely in code, which is where a naming divergence is hardest to notice and easiest to keep.

### The defect step 2 found

The command asks for a confirmation that the console offers no control implying a released record
can be rewound. It failed:

```
released → unclaimed → scheduled
```

`released` is "funds or goods issued by the disbursing officer"; `unclaimed` is "not collected". A
payout could be issued, marked uncollected, and returned to a payout list — **the shape in which a
family is paid twice** — and it was reachable from the release detail screen.

The identical edge exists here as `Released → Failed → Ready` and is **correct**, because this
API's `failed` covers a transfer that was sent and did not land. The console has no such state, so
the same shape meant something different and something wrong.

### The two machines split on different axes

This API splits on **whether an attempt was made**; the console on **whose failing it was**. So
`unclaimed → deferred` would have been the `DL-94` harm exactly — writing the office's failing onto
a household's record. It maps to `failed`, which **requires a reason**, and the reason carries the
distinction the console encodes in a state name. No seventh state was added to a published enum for
four clients: vocabulary is not meaning.

### Idempotency: required, not optional

`IdempotencyService` treats a missing key as *"no protection, carry on"* — right for an ordinary
write, wrong when what an unprotected retry produces is a second payout. All five money writes now
**refuse** a request without a key. That is a tightening of a published contract, taken now because
no client is wired to these endpoints yet, so it is the last moment it costs nothing.

### Four of my own bugs, found by the tests

1. **Laravel's `withHeaders` sets a persistent default.** The `money()` test helper leaked a key
   into every later request in the same test, so the test asserting a keyless request is refused
   was passing a keyed one. `withoutIdempotencyKey()` now flushes.
2. **PHP list destructuring drops references.** The reconciliation buckets were copies: totals
   correct, every breakdown empty. Caught by the assertion that the parts must add up to the whole,
   which is the one job that endpoint has.
3. A closure in `addToBatch` did not capture `$actor`.
4. My separation-of-duties test expected `409`; the API correctly answers `403`. The test now
   asserts the *message* too, because the command asks for the refusal to be legible and not merely
   correct.

### What the separation-of-duties test had to become

`lgu_admin` holds approval and **not** release — the role split already prevents the simple case,
and `DisbursingOfficer` exists so that releasing is somebody else's job. So the attack grants one
account both roles, which is what a small office on a bad day does. The refusal still comes from
asking *"is this the same human who approved it"*, which is the only question that survives an
administrator holding everything.

### Step 8 is unmet, and says so

`ReleaseConcurrencyTest` is a single honest skip. SQLite has no row lock, so the test would pass
for a reason unrelated to the code; and a body written blind against a database nobody can run
would look verified and fail as a regression the day PostgreSQL arrives. What must be asserted is
written down in the class docblock.

Suite: **1,026 tests, 7,595 assertions, 1 skipped**, `pint --test` clean.

---

## TAB 10 — newsfeed and events

*"The two modules that speak to residents directly, where nothing can be taken back."*

The command predicted this would be *"mostly reconciliation rather than construction"*, and it was.
Three divergences, one measurement, one blocked item.

### The lifecycle diverged in the direction the command did not anticipate

Step 1 warns: *"If the API is more permissive, the console must not expose the difference."* It was
the **console** that was more permissive — it allowed `archived → published`; `PostStatus::Archived`
here has no outgoing transition.

The console's reasoning was about the office (taking a post down by mistake is ordinary). This
API's is about the reader: resurfacing a post puts it back at the top of the feed carrying its
**original date**, which reads as the municipality announcing something old as though it were new.
In the one module where nothing can be taken back, the reader's argument wins. The console now
matches (`DL-134`), and the mistake case is served by publishing a *new* post — which is what
actually happened.

It was also a control that could not work: the button would have produced a refusal a caseworker
could do nothing about.

### `author_subject_id` reached every reader of a public thread

The comment reader projection published the author's **stable account identifier** to anybody
reading. That lets one person's comments be correlated across the whole feed, and on a welfare
newsfeed that correlation is a profile.

The code had already identified this and retained the field because removing one is breaking,
asking that the removal be *"scheduled deliberately rather than forgotten"*. TAB 10 is that
scheduling: no client reads it — the console holds it only in a captured fixture and the mobile
client never references it — so the cost is a changelog entry and nothing else.

**It moved rather than vanished.** A moderator acting on a repeat offender needs to know it is the
same account, and inferring that from comment bodies is guesswork. It now reaches holders of
`newsfeed.moderate` and nobody else.

### Reach had no source at all (G-31)

`DL-126` defines reach as reaction and comment counts. This API published **neither** — the admin
projection carried status, audience, schedule and transitions and nothing about response. A screen
built to that doctrine had nothing to render.

Both are now counted at read time, so no stored figure can drift from what happened, and
eager-loaded with `withCount` so a page of twenty-five costs one query rather than fifty. Two
numbers, and a test asserting no reactor, reader or sharer listing exists — so adding one breaks a
test rather than passing as a small convenience.

### What was already right, and verified rather than changed

* **Reporting a comment changes nothing about it.** An earlier version moved reported comments to
  `review-needed`, which `visibleComments()` filters out — one resident could have removed another
  from the municipality's feed. Already caught and fixed, with the assertion made from the reader's
  side.
* **Scheduling is the clock's.** `isLive()` requires status *and* an arrived `publish_at`; there is
  no job whose having-run decides visibility.
* **Capacity warns rather than blocks where it should.** A resident cannot register into a
  nonexistent seat, which is correct; staff *promotion* recomputes under a lock and returns an
  empty list when there is genuinely no room, so the office is never blocked from asking and the
  server decides.
* **Cancellation and completion are one-way**, both reaching only `archived`.
* **A registrant row is composed** — reference, resident id, name from the published minimum,
  status — and the projection cannot casually include an address or a vulnerability factor.
* **The resident-facing projection names no member of staff** (`DL-123`).

### Step 10 — the measurement, and the decision

Derivation is inline on publish. Measured on this machine, against a realistic 1600×1200 municipal
photograph (181 KB JPEG), two variants per image:

| images | derivation | per image |
| --- | --- | --- |
| 1 | 78 ms | 78 ms |
| 5 | 367 ms | 73 ms |
| 10 | 753 ms | 75 ms |

Linear, ~75 ms an image. **Decision: accept and document, do not defer to a queue.**

Deferring would create a window in which a post is live and its images are not yet derived — so
residents would see an announcement with missing pictures. For a module whose whole property is
*"residents see what the municipality actually published"*, that trade is the wrong way round: a
staff member waiting a second on a deliberate act is a better outcome than a resident seeing a
half-rendered post.

**What this measurement does not include** is the object-storage write. Two objects per image is
20 network round trips for a ten-image post, and no store is provisioned, so that cost is unknown.

**Revisit when** any of these is true: object storage is real and measured PUT latency pushes a
ten-image publish past ~3 s; a post routinely carries more than ten images; or the production host
proves materially slower than a developer machine. The hard ceiling today is the 50-image position
limit — ~3.8 s of CPU plus 100 PUTs — which is worth remembering before somebody raises it.

### Blocked

Step 9 — *"Verify the resident projection in a mobile client"* — needs the precondition *"at least
one mobile client available in staging"*. There is no staging. The projection was verified by
reading: `publicProjection` carries no `author_subject_id`, so the office is named rather than the
member of staff. That is not the same as watching it render.

---

## TAB 11 — search, saved views and notifications

*"The three services the shell uses on every screen, and the three easiest places to leak."*

### Search was scoped and unaudited

Scoping was already right and is now tested from the angle that matters: a barangay clerk searching
a neighbouring barangay's resident gets **exactly** what they get for a name that does not exist —
same keys, same empty array. Two different answers would make the search box an existence oracle
for the whole municipality.

**Auditing was missing entirely**, and it is the step the command spends the most words on. Every
search now records actor, term and match count.

Recording the term needed a decision, because {@see AuditTrail} exists to refuse this shape:
*"a trail that duplicates the data it protects is a second, less-guarded copy of it."* A search term
on a welfare registry is frequently somebody's name.

It is recorded anyway. The question an audit of this system has to answer is **who has been looking
up whom**, and a trail saying somebody searched four hundred times is not accountability. What makes
it safe is who reads it: `audit.view` is held by the **Data Protection Officer alone**, deliberately
withheld from `lgu_admin` because the auditee must not be the auditor. And the doctrine is not bent
— it forbids copying a *record's contents* into the trail, and a search term is the actor's own
input, which *is* the act being audited.

The command's other half — *"a searchable log of searches is a second copy of the disclosure"* — is
held structurally: `GlobalSearch` searches residents, cases, households and referrals and **never**
`audit_entries`. A test asserts the log cannot be mined through the surface that writes it.

### Saved views: sharing already cost a permission; scope was the thing to prove

`is_shared` already required `saved-view.share`, with the right reason recorded — a shared view's
*name* describes a population to everybody who opens the screen.

What TAB 11 adds is the proof of step 8: an unrestricted administrator saves a view aimed at a
barangay a clerk cannot reach, the clerk sees the view — it is office furniture — and running it
returns nothing. The saved filter is applied **on top of** the reader's scope, so it can only
narrow. The test accepts either a refusal or an empty result, because both mean the same thing: the
author's reach did not travel with the view.

### Notifications: the port was already right, and the mechanism needed a decision

`NotificationRepository` has no `create` — the withdrawal recorded in TAB 07's triage is done, and
no adapter can mint one.

Step 10 asked for an honest delivery mechanism. **The answer is neither poll nor subscribe: on
demand** (`DL-135`). The inbox is fetched when the drawer opens and at no other time; the only
timers in the store dismiss toasts.

That is honest because **the console shows no unread badge**. A badge is a freshness claim, and a
number kept current only by opening the drawer is a claim the system cannot keep. Polling to
support a badge nobody asked for would put a recurring request from every open tab against a shared
municipal API for a feature that does not exist.

The cost is stated rather than hidden: a notification raised by the *server* is not seen until
somebody opens the inbox. Anything genuinely owed to a person belongs in their work queue, which is
`DL-96`'s distinction and a screen they open deliberately. **If a badge is ever added, the decision
must be revisited in the same change.**

Step 12 resolves without work: the console's channels are `toast | inbox | both`, which are
*presentation* surfaces inside one browser tab, not delivery channels. There is no channel it
offers that could have a missing worker. The API's own `email` and `sms` are `NullChannel`s
recording `skipped` and never `sent` — the same honesty, one layer down.

Suite: **1,036 tests, 7,655 assertions**, 1 skipped, `pint --test` clean.
