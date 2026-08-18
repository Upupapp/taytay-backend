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

## L-18 (P0) — a sign-in code is issued, recorded, and never delivered

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

**Not implemented here.** It is a new cross-module contract in a repository with
its own live TAB sequence, and it belongs to that sequence rather than to a
passing client integration. The design constraint above is the finding.

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
