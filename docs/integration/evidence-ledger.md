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
