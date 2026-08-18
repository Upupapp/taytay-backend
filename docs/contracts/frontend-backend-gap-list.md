# Gap List — Frontend Mocks vs Required Backend

What the clients assume, what the backend actually promises, and what has no persistence
yet. Each gap names its evidence, the risk of leaving it, and who resolves it.

**Severity:** `blocking` — a client cannot be connected until it is settled ·
`high` — wrong behaviour or a privacy risk if built as-is · `medium` — rework later ·
`low` — tidy-up.

| # | Gap | Severity | Owner |
| --- | --- | --- | --- |
| [G-01](#g-01) | Angular's provisional envelope contradicts `/api/v1` | blocking | Angular |
| [G-02](#g-02) | `signInAs(staffUserId)` is an authentication bypass | blocking | Angular |
| [G-03](#g-03) | Admin auth: cookie session vs bearer token | blocking | both — [ADR 0006](../adr/0006-admin-console-authentication.md) |
| [G-04](#g-04) | Permissions derived client-side | high | both |
| [G-05](#g-05) | Two divergent assistance lifecycles | blocking | both — [ADR 0007](../adr/0007-canonical-assistance-lifecycle.md) |
| [G-06](#g-06) | `canCancel` / `isTerminal` implemented in the mobile client | high | backend + mobile |
| [G-07](#g-07) | Sensitive-sector suppression is presentation-only | high | backend |
| [G-08](#g-08) | No resident verification state in the admin model | high | backend |
| [G-09](#g-09) | ~~Catalog holds 2 of ~31~~ → **closed**; superseded by G-09a | — | — |
| [G-09a](#g-09a) | Only 30 of the console's 70 permission keys exist here | high | backend + Angular + LGU |
| [G-10](#g-10) | Two Flutter apps claim the citizen-mobile channel | high | product |
| [G-11](#g-11) | Barangay PSGC codes are `null` | medium | backend |
| [G-12](#g-12) | No persistence behind dashboard, notifications, audit | medium | backend |
| [G-13](#g-13) | Field naming and pagination defaults differ | medium | both |
| [G-14](#g-14) | Client-created notifications | medium | Angular |
| [G-15](#g-15) | No idempotency on money or intake operations | high | both |
| [G-16](#g-16) | Separation of duties asserted only in the client | high | backend |
| [G-17](#g-17) | `request.view-sensitive` vs `case.view-sensitive` | low | Angular |
| [G-18](#g-18) | No file-upload contract | medium | backend |
| [G-19](#g-19) | Angular resident routes point at pre-TAB-08 paths | medium | Angular |
| [G-20](#g-20) | Vulnerability weights are placeholders, not Taytay policy | high | LGU (MSWDO) |
| [G-21](#g-21) | Assessment templates and retention periods are placeholders | high | LGU (MSWDO + DPO) |

---

### G-01
**Angular's provisional envelope contradicts the shipped `/api/v1` contract.** `blocking`

`data/http/api.contract.ts` expects `meta: {page, pageSize, totalItems, totalPages}`,
errors as `{message: string}`, unversioned paths (`residents`), and camelCase query
parameters (`pageSize`, `sort` + `direction`).

The backend ships `meta.pagination: {page, per_page, total, total_pages, has_more}`,
errors as `{error:{code, message, details, request_id}}`, `/api/v1` paths, and
`per_page` / `sort=-field`.

The file says it is provisional and must be reconciled first — this is that moment. The
mobile client already implements the shipped contract exactly
(`lib/core/api/api_envelope.dart`), which is the proof the contract is implementable.

*Resolution:* Angular updates `api.contract.ts` and its adapters. Domain models do not
change; that is what the adapter seam is for. Nothing changes in this backend.

---

### G-02
**`signInAs(staffUserId)` would be an authentication bypass.** `blocking`

`http-repositories.ts:172` posts `{staffUserId}` to `session` with **no credential**. As a
real endpoint, anyone could become the MSWDO head by guessing an identifier.

*Resolution:* never implement it. The backend offers `POST /api/v1/auth/tokens` with real
credentials. The persona switcher is a mock-adapter development affordance and must not
survive the switch to `dataSource: 'http'`. Recorded as `mock-only` in the endpoint matrix.

---

### G-03
**Admin authentication: HTTP-only cookie vs bearer token.** `blocking`

Angular `CLAUDE.md` §2.5: *"No token is placed in `localStorage` by this app; session
credentials travel in an HTTP-only cookie set by the API."*
Backend ADR 0005 rejected cookie/SPA mode for the cross-origin Netlify → Linode split and
chose first-party bearer tokens.

Both positions are defensible and they are incompatible. Settled in
[ADR 0006](../adr/0006-admin-console-authentication.md): bearer token held **in memory
only**, which honours the Angular rule (nothing in `localStorage`) without reintroducing
the cookie scope, credentialed CORS and CSRF surface ADR 0005 refused.

That decision carries a **deployment obligation, not just a client change**: an in-memory
token is still readable by injected script, so it depends on a strict Content-Security-Policy
served by Netlify on both browser clients
([deployment topology](../architecture/deployment-topology.md#content-security-policy-required-by-adr-0005-and-adr-0006)).
Shipping the token change without the CSP leaves the accepted risk unmitigated.

---

### G-04
**Effective permissions are computed on the client.** `high`

`toAuthenticatedUser()` (`domain/access/staff-user.ts:49`) derives the permission set from
a client-side role map plus `additionalPermissions`. If the backend's role catalog ever
differs, the console shows actions the server will refuse — or hides ones it would allow.

The Angular constitution is already clear that client checks are usability only, so this
is a synchronisation defect, not a security hole. But a UI that disagrees with the server
teaches users that errors are random.

*Resolution:* `GET /api/v1/me` returns server-resolved `permissions[]` and `scope`. The
client mirrors that response and stops deriving. The role→permission map stays in the
client only as a fallback label source.

---

### G-05
**Two different assistance lifecycles already exist.** `blocking`

| Client | States |
| --- | --- |
| Angular admin | 13: `draft, submitted, intake-review, returned, assessment, endorsed, approved, rejected, scheduled, released, completed, cancelled, expired` |
| `lgu_ids_taytay` | 17: `draft, submitted, received, underReview, forSocialWorker, forVerification, forHealthOfficeReview, forMswdoReview, needsMoreDocuments, waiting, forInterview, forApproval, approved, scheduledForRelease, released, rejected, cancelled` |

Neither is a superset of the other, and the mobile set encodes routing decisions
(`forHealthOfficeReview`) that the admin set treats as assignment rather than state.

*Resolution:* [ADR 0007](../adr/0007-canonical-assistance-lifecycle.md) — the Angular
lifecycle is canonical because it is the one the office actually operates and the one with
transition rules and separation-of-duties tests behind it. The mobile states become a
**presentation projection** served by the backend, not a second state machine.

---

### G-06
**The mobile client decides cancellability and terminality itself.** `high`

`RequestStatusX.canCancel` and `.isTerminal` (`lib/data/models/models.dart:292-300`) are
business rules living in a shipped mobile build that cannot be patched on demand. If the
office changes when a request may be withdrawn, an old build keeps offering the old rule.

*Resolution:* the backend returns `available_actions[]` on every citizen request
projection. The client renders what it is told. Same rule for `isTerminal` — the server
sends the projected status and whether the case is closed.

---

### G-07
**Sensitive-sector suppression is presentation-only today.** `high`

The mock adapter returns the full record and the list view masks it; `decision-log.md`
DL-19 states plainly that *"the API enforces its own copy"*.

That copy does not exist yet, and "masking" is the wrong verb for an API: a masked field
still crossed the network.

*Resolution:* the backend **omits** sensitive sector values and excludes flagged cases
from list results for actors without `request.view-sensitive`, and rejects a
`sector=vawc-survivor` filter with `403` rather than an empty page. Specified in the
visibility matrix §2.

---

### G-08
**No resident verification state in the admin model.** `high`

The Angular `Resident` carries only `isActive`; DL-12 records the gap and defers it. But
the mobile client already routes on a verification tier
(`AccessLevel.fromVerificationTier`), and verification gates what assistance can be
released.

*Resolution:* the backend owns a canonical `VerificationState` enumeration (not a boolean)
in the residents/identity TAB, exposed as `verification_tier` on `/api/v1/me` and on the
admin resident resource. Unknown tiers must degrade to the least-capable state — the
mobile client already does this correctly.

---

### G-09
**CLOSED — 18 August 2026, TAB 06.** ~~The backend permission catalog holds 2 of about 31
permissions.~~

`Modules\AccessControl\Contracts\Permission` now declares **61**, each against the endpoint it
guards, with role assignment persisted. The entry stood while it was true and is struck rather than
deleted, because a gap list that quietly loses entries cannot be audited.

Measuring it to close it found a larger problem, recorded as G-09a.

---

### G-09a
**Only 30 of the admin console's 70 permission keys exist in this catalog.** `high`

|  | count |
| --- | --- |
| keys the console defines | 70 |
| keys this API publishes | 61 |
| **agreed by both** | **30** |
| console keys with no counterpart here | 40 |
| keys here the console never asks for | 31 |

The 30 that match are the assistance-request lifecycle, referrals, visits, reports, staff, events
and newsfeed. The spine of the product agrees; what surrounds it does not.

**The console fails closed.** `fromServerIdentity` keeps only the keys this API sends and invents
none — deliberately. So a key we do not publish can never be held by anybody, and the console's
guard on it refuses every user in every role. Measured against its router: **24 of 43 guarded
routes are unreachable, including its landing page.**

Two different problems needing two different fixes:

* **Naming divergence over an act both sides implement.** The console splits `resident.create` and
  `resident.update`; we grant `resident.manage`. Its `disbursement.*` family covers what
  `request.release` does here. Whoever owns the vocabulary picks one spelling — this is not a
  matter of taste once a guard depends on it.
* **Concepts this API does not have.** `case.*` awaits ADR 0044, `beneficiary.*` is a projection
  the console defined over the resident registry, and `dashboard.view` and `settings.manage` have
  no server-side counterpart at all. Adding a permission with no endpoint behind it would be
  inventing policy ahead of its use — the same reason G-09 was deliberately left open in TAB 02.

Thirty-one keys run the other way: `kyc.*`, `credential.*` and `services.*` serve the citizen
channels and the admin console rightly ignores them. `safeguarding.*`, `vulnerability.*`,
`privacy.*` and `task.*` are a different matter — the console has screens for that work under other
names.

Nothing is broken today; the console runs on mock adapters. It breaks when it flips to this API,
and it breaks as a blank console rather than an error.

`check:permission-parity` in the console holds the count at 24 and fails in both directions until
somebody decides.

---

### G-10
**Two Flutter applications both claim the `citizen-mobile` channel.** `high`

| App | Evidence | State |
| --- | --- | --- |
| `Taytay_Rizal_LGUIDS_Resident_Mobile_Flutter` | named by the Angular constitution; already implements this backend's envelope, error codes, channel header and bearer auth | narrow: auth, credential, verification, account |
| `lgu_ids_taytay` | named by this backend's `CLAUDE.md` Article 0 | broad: `tulong`, `buwis`, `kalusugan`, `dokumento`, `trabaho`, `balita`, `events`, `qr_scanner`; placeholder API service, no git history |

They disagree on lifecycle (G-05) and on API shape. Building endpoints for both as if they
were one client will produce exactly the duplicate logic this TAB exists to prevent.

*Resolution:* a product decision is required — is `lgu_ids_taytay` the superseded
prototype, the superset target, or a separate LGU-wide app of which social welfare is one
module? **This backend's `CLAUDE.md` Article 0 should be corrected once decided.** Until
then the matrix treats their union as the citizen contract surface, which is safe because
both are served by the same routes.

---

### G-11
**Barangay PSGC codes are `null`.** `medium`

`domain/geography/barangay.ts` leaves `psgcCode: null` for all five barangays, with a
comment that a wrong code is worse than an absent one because DSWD reporting keys off it.

*Resolution:* the backend loads the authoritative PSA PSGC dataset for Taytay, Rizal when
the geography reference table is built, and serves it via `GET /api/v1/barangays`.
Statutory reports (matrix §9) cannot be certified correct until this lands.

---

### G-12
**Several screens have no persistence behind them at all.** `medium`

| Mock | What the backend must own |
| --- | --- |
| `mock-dashboard.repository.ts` | Aggregates computed from real requests/disbursements, scope-filtered per actor |
| `mock-notification.repository.ts` | Per-recipient persisted notifications with read state |
| Audit trail screen | An append-only `AuditEntry` store — nothing writes one today |
| `AuditStamp` on every model | `created_by` / `updated_by` need a real actor, which needs Identity |
| `SubmittedRequirement` files | No document storage; see G-18 |
| Household composition | Referenced by `getHousehold()`, seeded only in mocks |

None of these are contract disagreements — they are simply unbuilt. They are listed so
that "the screen works" against mocks is not mistaken for "the data exists".

---

### G-13
**Field naming and pagination defaults differ.** `medium`

Angular models are camelCase (`referenceNumber`, `philsysLastFour`) and default to
`pageSize: 20`; the backend emits snake_case and defaults to `per_page: 25` (15 for
`citizen-mobile`), max 100.

*Resolution:* the backend keeps snake_case per conventions §6 — it is the published
contract and the mobile client already consumes it. The Angular adapter maps at the
boundary, which is the adapter's job. Page size is a client request; the client sends
`per_page=20` if it wants 20.

---

### G-14
**The client can create notifications.** `medium`

`NotificationRepository.create()` exists as a port. A client able to create its own
notifications can forge an official LGU message.

*Resolution:* no backend endpoint. Notifications are raised server-side as a consequence
of a domain event (ADR 0004: Laravel decides, FCM delivers). The local toast path needs no
backend and stays client-side. Recorded as `mock-only`.

---

### G-15
**No idempotency on money or intake operations.** `high`

Neither client sends an `Idempotency-Key`, although the mobile `RequestContext` already
supports one. A retried release on a flaky connection is a double payout of public funds;
a retried submission creates a duplicate application.

*Resolution:* the backend **requires** `Idempotency-Key` on disbursement transitions and
citizen request submission, and replays the original result for a repeated key
(conventions §7). Clients must send one.

---

### G-16
**Separation of duties is asserted only in the client.** `high`

Angular `CLAUDE.md` §5 and `domain/access/permission.spec.ts` assert that no single
non-administrator role may both approve a request and release its money. That test guards
the *client's* role map.

*Resolution:* the backend asserts the same invariant over its own role catalog, with its
own test, when the catalog lands (G-09). A control that exists only in the frontend is not
a control.

---

### G-17
**Permission name inconsistency inside the Angular reference.** `low`

`resident.ts:37` documents the gate as `case.view-sensitive`; the catalog defines
`request.view-sensitive`. The catalog is authoritative — the comment is stale. Noted so
the backend does not adopt the wrong name.

---

### G-18
**No file-upload contract.** `medium`

Requirement documents, verification ID images and selfies are all uploads with no agreed
mechanism. They are the most sensitive artifacts in the system.

*Resolution:* uploads go to the private `object-storage` disk (ADR 0004) — never the
`public` disk — via an authorization-gated endpoint, and are read back through a
short-lived signed URL issued after a server-side authorization decision. The concrete
request/response shape is specified in the TAB that builds intake.

---

### G-19
**The Angular resident screens call pre-TAB-08 paths.** `medium`

`ResidentRepository` calls `/api/v1/residents…`. TAB 08 built the canonical registry at
`/api/v1/admin/residents…`, matching every other staff surface in this backend — the
`/admin` segment is a routing convention and confers no authority (ADR 0002), but the two
paths do not agree.

The backend was **not** bent to the client here. Serving the registry from an unprefixed
path would put a permission-guarded staff endpoint in the same namespace as the public
catalog, and the next reader auditing "which routes are citizen-reachable" would have to
open every controller to find out.

*Resolution:* the Angular console repoints `ResidentRepository` at the `/admin` paths in
§11d. Until it does, the §4 rows stay `planned` and describe what that client calls today,
so the matrix keeps telling the truth about both sides rather than quietly adopting the
backend's shape as though the client had already changed.

Also settled by TAB 08: **G-08** (no resident verification state) — `verification_tier`,
`verified_at` and an append-only history are now canonical and exposed on the detail and
history endpoints.

---

### G-20
**The vulnerability weights are a plausible ordering, not Taytay policy.** `high`

`config/vulnerability.php` assigns a weight to every factor and a band to every score range.
Nobody at the MSWDO has approved those numbers. The master command forbids hardcoding LGU
policy that has not been supplied, so the ruleset declares itself
`status: placeholder-pending-lgu-approval` and `decision_support_only: true` **inside its own
payload**, and every snapshot carries the version that produced it.

This is recorded as a gap rather than a defect because the alternative — shipping no ordering
at all — would leave case workers with a queue sorted by arrival time, which is worse and also
unstated policy.

*Resolution:* the MSWDO reviews the factor catalog and weights, the version is bumped, and
`status` becomes `approved`. Nothing else changes: the numbers live in one reviewable file
precisely so that approving them is a diff rather than a migration.

**Not blocking**, because no eligibility outcome follows from the score — that is asserted by
`VulnerabilityProfileTest::a_high_score_changes_nothing_about_the_resident_record`. It becomes
blocking the moment any TAB reads the score to decide something, and that TAB must resolve
this first.

### G-21
**Assessment templates and retention periods are placeholders.** `high`

Two provisional policies shipped in TAB 12, both declaring themselves as such:

* `config/assessment.php` — the AICS and medical assessment forms. Plausible instruments, not
  Taytay's. Each carries `status: placeholder-pending-lgu-approval`, and every assessment pins
  the template version it was made against, so approving them is a diff and a version bump
  rather than a migration.
* `config/welfare.php` — draft retention (30 days) and returned-case expiry (60 days). These
  are RA 10173 storage-limitation decisions and belong to the DPO, not to a developer picking
  round numbers.

Recorded rather than silently shipped, for the same reason as G-20: the master command forbids
hardcoding LGU policy that has not been supplied, and the alternative — shipping no form and no
expiry — would have been worse and *also* unstated policy.

**Not blocking.** No eligibility follows from an assessment (the templates carry no weights,
thresholds or totals, by design — ADR 0017 §4), and draft expiry only ever refuses a stale
form rather than deciding anything about a person. It becomes blocking the moment an
assessment answer is wired to an automatic outcome, which the master command permits only
behind an explicit LGU-approved deterministic rule.

*Resolution:* MSWDO reviews the templates and bumps their versions; the DPO sets the retention
figures. Both live in one reviewable file each, precisely so approving them is cheap.

Settled by TAB 18: **G-15** (no idempotency on money or intake operations) — **fully closed**.
TAB 12 settled the intake half; release confirmation is now the second `IdempotencyService` caller,
which is what the money half was waiting for.

Raised and resolved by TAB 18: **money representation.** The master command asks for
"fixed-precision decimal columns"; CLAUDE.md Article 4 and conventions §6 require integer minor
units. Implemented as **integer centavos plus an explicit `currency`**, per ADR 0023 §1: both are
exact and both forbid floating point, the constitution outranks the task instruction, and every
existing money field on both sides is already centavos — including `released_amount_centavos`,
which TAB 14 published as null so this TAB could fill it without a client change. **No client
change required**, which was the point.

Opened by TAB 32: **G-52** — **RPO and RTO are unset, and the first restore has never been run.**
The backup strategy, the encryption and key-custody rules and the restore procedure are written
([backup-and-disaster-recovery.md](../runbooks/backup-and-disaster-recovery.md)); the two numbers
that make them measurable are deliberately blank, because how much welfare data Taytay can afford
to lose and how long the office can operate without this system are business decisions. **A backup
that has never been restored is a hypothesis** — the procedure exists and nobody has executed it,
so the observed RTO is unknown. Owner: LGU management to set the targets, then deployment to run
the first exercise and record what actually happened.

Opened by TAB 32: **G-51** — **nothing alerts.** `/admin/operations/metrics` exposes the numbers
worth watching — queue depth per queue, failed jobs, notification failures, auth anomalies — and
nothing polls them or wakes anybody. Alerting, paging and an on-call rota are deployment concerns
with a real cost, and choosing a tool from here would be choosing one the LGU then has to pay for
and staff. The specific signature worth alerting on is recorded in the runbook: **queue depth
climbing while failed jobs stay flat** means work is arriving and nothing is consuming it, which is
the failure that produces no error and no symptom except a resident who never got a message.
Owner: LGU + deployment.

Opened by TAB 30: **G-50** — **Firebase App Check is not adopted.** The master command permits it as
defence in depth for Flutter and custom-backend traffic, and is explicit that it never substitutes
for authenticated actor and object authorization. It is recorded rather than half-built, because
the two ways to ship it are both wrong without a decision: an attestation check that fails open is
decoration, and one that fails closed needs a staged rollout plan — old app versions, emulators and
rooted devices all fail attestation while belonging to real residents. Owner: LGU, then this
backend.

Opened by TAB 30: **G-49** — **every Eloquent model is `$guarded = ['id']`.** Mass assignment is
prevented at the controller instead: `$request->validate()` returns only validated keys, so an
unlisted field never arrives at the service, and the correction endpoint goes further and refuses
unknown fields by name. That is a real control and it is now tested (`ApiSecurityTest`, OWASP API6).
What it is not is defence in depth — a controller that ever passed unvalidated input through would
breach it with nothing to stop it. Tightening 85 models to explicit `$fillable` lists is a large
change with its own regression risk, and it should be done deliberately rather than as a footnote
to a security pass. Owner: this backend.

Opened by TAB 29: **G-48** — **no RA 10173 §16 request lifecycle.** The master command asks for
correction and access request *hooks*, and the hooks exist: resident correction requests were built
in TAB 09, and a subject-access request is an export the machinery in ADR 0026 §3 already supports.
What does not exist is a lifecycle for a formal data-subject request — received, acknowledged within
the statutory period, actioned or refused with reasons, and the whole thing evidenced. Erasure and
blocking in particular have no path at all, and cannot have one until the retention schedule is
approved (G-47). Owner: LGU DPO to define the process, then this backend.

Opened by TAB 29: **G-47** — **nothing sweeps expired records.** `RetentionPolicy` answers "may this
be purged"; no scheduled job calls it, because nothing may be purged until the DPO approves the
schedule. Wiring a sweeper first would be building the thing whose safety depends on a decision
nobody has made — and deletion is the one operation this system cannot undo. When the schedule is
approved, the sweeper is a job that walks each category and calls `mayPurge()`, which already
enforces the legal-hold check. Owner: LGU DPO first, then this backend.

Opened by TAB 29: **G-46** — **nobody holds `audit.view` until the LGU appoints a DPO.** The
`data_protection_officer` role exists and is the only holder of `audit.view` and `privacy.manage`;
`lgu_admin` deliberately holds neither. Until somebody is assigned that role, **nobody in this
system can read the audit trail at all**. That is intended rather than an oversight — the trail
records the MSWDO head's own approvals, document reads and exports, so granting it to them would be
the auditee reading their own audit (ADR 0034 §7). It is recorded here because it is a real
operational prerequisite: the trail is being written now and will be unreadable until the
appointment is made. Owner: LGU.

Opened by TAB 28: **G-45** — **image derivation runs inline, not queued.** Publishing a post or an
event re-encodes its images during the request. That is fast for two variants of one image and slow
for a post with ten, and the master command asks for the transformations to be queued. It was left
inline deliberately rather than queued speculatively: a queued derivation means a published post
whose image appears some seconds later, which needs a decision about what the feed shows in the
meantime — and that decision belongs with whoever sees the real posting volume. Moving it is a
one-line change behind the same interface, since `MediaPublisher::publish()` is already idempotent
and already called from outside the transition transaction. Owner: this backend, once posting
volume is known.

**CLOSED by TAB 33.** `docs/api/openapi.json` is generated from the router, the PHP enums and the error-code catalogue, committed, and kept current by a build-failing check — so a response-shape change produces a spec diff in the same commit (ADR 0038). `docs/api/types.ts` carries the same enums for TypeScript clients, and `CHANGELOG_API.md` records the versioning and deprecation policy. Originally: Opened by TAB 27: **G-44** — **there is no generated OpenAPI 3.1 document.** The stack baseline names
one and none is produced. The contract matrix is the current specification, and it is not merely
prose: `ContractMatrixTest` checks it against the registered routes in **both** directions, so a
row without a route and a route without a row both fail the build. What a generated document would
add is machine-readable request/response *schemas* — useful for client codegen, and a second
artefact to keep true. Owner: this backend, when a client team asks for codegen.

Opened by TAB 27: **G-43** — **the citizen web portal uses bearer tokens, not Sanctum cookie mode.**
The master command mentions stateful cookie/session authentication for first-party web SPAs "where
deployment domains permit it". ADR 0005 chose first-party bearer tokens instead, because the cookie
route would have required widening cookie scope, enabling credentialed CORS and adding a CSRF
surface — and Article 8.7 says change the approach, not the control. Recorded here so the choice
stays visible rather than being assumed: if Taytay later wants cookie mode, it needs the custom
domains verified first and a new ADR. Owner: LGU + deployment, then this backend.

Opened by TAB 26: **G-42** — **there are no per-event eligibility rules.** The master command says
registration is for an *"authenticated verified/eligible citizen according to event rules"*, and the
only event rules that exist are capacity and the registration window. "Seniors only", "one per
household", "residents of this barangay only" are all plausible and none is expressible. Anybody
with a linked resident record can register for anything published. Owner: LGU (to say which rules
it actually wants), then this backend.

Opened by TAB 26: **G-41** — **staff cannot register somebody at a counter.** An assisted
registration is a real LGU workflow — an elderly resident who does not use the app is signed up by
the clerk in front of them — and the schema already supports it (`account_subject_id` is separate
from `resident_id`, so "who pressed the button" and "whose place it is" are already distinct). What
is missing is the endpoint and the permission decision: an endpoint that lets staff create a
registration for any resident is also an endpoint that can fill an event with names nobody chose,
so it should be deliberate rather than convenient. Owner: LGU, then this backend.

Opened by TAB 26: **G-40** — **the concurrency guarantee is asserted, not exercised.** Capacity
safety rests on `SELECT ... FOR UPDATE` on the event row, and the test suite cannot prove it: it is
single-process and SQLite compiles `lockForUpdate()` to an empty string. The suite proves the
arithmetic (`capacity_is_never_exceeded_however_many_people_try`) and asserts the mechanism exists
(`every_seat_decision_is_taken_behind_a_row_lock`); it does not run two registrations in parallel
against PostgreSQL and confirm that exactly one wins the last seat. **That test should be written
before the first capacity-limited event goes live**, and it needs a real Postgres and a concurrent
runner — neither of which this repository's test harness has. Owner: this backend + deployment.

Opened by TAB 25: **G-39** — **event reminders are not built.** Telling a registrant the day
before, or telling everybody an event was cancelled, is the obvious next thing and it needs
registrations (TAB 26) before there is anybody to tell. The cancellation reason is already stored
and public, so the data a reminder would carry exists; what does not exist is the trigger or the
recipient list. Owner: this backend, after TAB 26.

Opened by TAB 25: **G-38** — **anonymous reading is ON for events and OFF for the newsfeed.** The
asymmetry is deliberate (ADR 0030 §5): a poster with a QR code is read by somebody with no account,
and events have no audience targeting, so the barangay-disclosure risk that keeps G-36 shut does
not arise here. It is recorded as a gap rather than a decision because **the LGU has not been asked
to confirm it**, and because per-barangay events would bring both questions back together. If
Taytay wants events behind a login, that is a middleware line and a test, not a redesign. Owner:
LGU.

Opened by TAB 24: **G-37** — **no moderation provider.** `review-needed` exists as a comment state
that nothing currently sets. The master command asks for a hook and explicitly says not to build AI
moderation now, so the state machine, the queue and the audit trail are all in place and the only
abuse control today is a rate limit. Adding a provider is a listener that writes `review-needed`,
not a migration and a state-machine change. Owner: LGU (to decide whether one is wanted), then
this backend.

Opened by TAB 23: **G-36** — **anonymous newsfeed access is OFF by default** (`NEWSFEED_PUBLIC`).
The master command permits it "only if Taytay explicitly marks Newsfeed public", and defaulting the
other way would have published a barangay's relief schedule to the open internet before anybody at
the MSWDO was asked. Even when enabled, an anonymous reader sees municipality-wide posts only:
audience targeting needs a reader whose barangay is known, and there is no way to ask an anonymous
caller for one that is not also a way to enumerate barangays. Owner: LGU.

Opened by TAB 22: **G-35** — **no search index exists**, deliberately. Search runs as a `LIKE`
scan over a few thousand rows. A driver-guarded trigram migration was written and removed: it
needed a raw `DB::statement`, which `InfrastructureAlignmentTest` has forbidden since TAB 01, and
it is unmeasured optimisation on the same grounds ADR 0026 declined materialised views. An index
has no behavioural effect, so when a measurement justifies one it is an operational change, not a
migration:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_residents_search ON residents
  USING gin ((coalesce(first_name,'') || ' ' || coalesce(last_name,'')) gin_trgm_ops);
CREATE INDEX idx_welfare_cases_search ON welfare_cases USING gin (case_number gin_trgm_ops);
CREATE INDEX idx_households_search ON households USING gin (code gin_trgm_ops);
```

Trigram rather than `tsvector`: these are names and reference numbers, and a clerk typing "Dela
Cru" needs a substring match a stemmer will not give them. Owner: this backend, when measured.

Opened by TAB 21: **G-34** — the small-cell suppression threshold is **5**, the convention most
statistical agencies use, and it is not a Taytay policy decision yet. It is a named constant
(`MetricsService::MINIMUM_CELL`) rather than a literal so approving a different figure is a
one-line change. Owner: LGU (MSWDO + DPO).

Also from TAB 21: **no materialised views exist**, deliberately. The master command says to use
them only when measurements prove they help, and there are none — Taytay's caseload is thousands,
not millions. An unmeasured read model is a second copy of the truth with its own refresh bug.
When the numbers justify one it gets its own ADR. Owner: this backend, when measured.

Opened by TAB 20: **G-33** — **the FCM transport is not wired.** The push adapter, its data-only payload shape, the bounded retry, the dead-token deactivation and the `skipped` behaviour are all built and tested; the OAuth exchange and HTTP post are not, because service-account credentials are environment configuration this repository must not assume. Turning push on is `FCM_PROJECT_ID` + `FCM_CREDENTIALS_PATH` and an implementation of one method. Every TAB 20 acceptance criterion holds without it. Owner: this backend + deployment.

Opened by TAB 19: **G-32** — `tasks.team` is a label rather than a foreign key. Taytay's MSWDO has no formal team structure in this system, and inventing a table to hold a string would be one nobody maintains. If team structure is formalised, that is a new table and a migration from the label. Owner: LGU, then this backend.

Opened by TAB 17: **G-30** — **there is no protection-officer role.** Six permissions now sit with
`lgu_admin` that should not: `vulnerability.view.protected`, `document.view-sensitive`,
`case-note.view-protected`, `safeguarding.view`, `safeguarding.manage`, and
`referral.disclose-protected`. Each was placed there with the same note, and the accumulation is
now the finding: reading a survivor's safety plan is not an administrative convenience. When the
role exists, all six move and the MSWDO head keeps none of them. Owner: LGU (to name the role),
then this backend.

Opened by TAB 17: **G-31** — **no staff field-sync protocol.** The console models an offline
`VisitCapture` with an explicit `held-locally` / `sending` / `sent` / `send-failed` state and no
background queue, because a worker who believes a visit was filed and returns to find it was not
has been failed twice. That is a client concern and stays there; this backend offers ordinary
idempotent endpoints. If field sync is added it needs its own controlled design and its own ADR —
an offline protocol bolted onto endpoints designed for a browser is how duplicate visit records
and lost observations arrive. Owner: deferred, by the master command's own instruction.

Opened by TAB 16: **G-28** — `ProgramCatalog` does not audit its own writes. Publishing a
programme is at least as consequential as editing a directory entry, which TAB 16 does audit.
Not retrofitted here because it belongs with a review of what a programme change means, not with a
referral TAB. Owner: this backend.

Opened by TAB 16: **G-29** — `assistance-history` is a releasable referral field with no value
wired. It is assembled across cases and enrolments, and `disclosureFactsFor()` returns nothing for
it rather than an invented value, so choosing it simply omits the line from the sheet. A client
may offer the field; it will print nothing until this is wired. Owner: this backend.

Sharpened by TAB 16: **G-26** — `document.share` is held by nobody, and that now **blocks a real
workflow**: referral attachments are refused, because attaching a document to a referral is the
same outward disclosure that permission governs. The referral itself sends fine. This is intended
behaviour, not a defect — but it is the point at which the LGU needs to name a holder.

Opened by TAB 15: **G-24** — the admin console receives a full `documentNumber` and masks it in the
view with `maskDocumentNumber`. **This backend never sends one.** Only the last four characters are
stored, and only where the document has no file; the API returns the display form (`••••3456`)
already built. The client change is to render what it is given and delete the local masking of a
value it no longer receives. Owner: Angular. *Not a regression* — the console's own reasoning was
right, it was simply applied one layer too late (ADR 0020 §4).

Opened by TAB 15: **G-25** — three placeholders awaiting an LGU decision, each in one reviewable
file so approving it is a single small act:
* **no malware scanner is configured** (`config/files.php`). Uploads settle at `skipped`, which is
  deliberately not `clean`: served to staff, refused for any outward share. Turning one on is a
  config change; the state machine, queue and download consequences are already wired.
* **retention periods** are engineering estimates on `FileClassification`, not an approved
  schedule.
* **the 30-day expiry warning window** is the office's convention, carried over from the console
  and still unconfirmed against a written issuance.

Opened by TAB 15: **G-26** — `document.share` is held by **nobody**. The outward-sharing path is
built, tested and refused, because every internal read leaves a trail this system controls and a
copy that leaves does not. The first holder should be a decision the LGU makes on the record.
Owner: LGU.

Opened by TAB 15: **G-27** — `kyc_documents` predates the `Files` store and keeps its own table,
scoped to a KYC case with its own review states. It should adopt `Files` by
expand → migrate → contract so there is one document store and one version history. Not folded in
during TAB 15 because migrating live document rows is its own change with its own risk. Owner:
this backend.

Also found and closed by TAB 14: **G-22** — a resident merge left welfare records pointing at the
soft-deleted resident. Not a frontend gap; a backend defect, recorded here because its symptom is
purely client-visible and would have been reported as one. The applicant's own `me/cases` and
`me/assistance-history` went empty while the staff console showed a complete and correct file, so
each side would have been certain the other was broken. Closed by
`ReassignWelfareRecordsOnResidentMerge`, and the class is now barred by
`ResidentMergeCoverageTest` (ADR 0019 §4). **No client change required.**

Also opened by TAB 14: **G-23** — assistance history reports
`released_amount_centavos: null` on every granted case. The field is present and deliberately
unfilled: TAB 18 owns the release ledger and this payload is shaped for it to join onto. A client
rendering assistance history must show the outcome and date, and must **not** render an amount
until TAB 18 lands. Owner: this backend, TAB 18.

Also settled by TAB 12: **G-15** (no idempotency on money or intake operations) — for intake.
`idempotency_keys` had existed since TAB 04 with no caller; `Shared\Application\IdempotencyService`
is now that caller, wired into citizen submission and counter intake. The money half stays
open until TAB 18 wires release confirmation to the same service.

---

Also settled by TAB 10: **G-07** (sensitive-sector suppression is presentation-only) — for
vulnerability factors, safeguarding suppression is now server-side and total: absent from the
list, absent from the score, absent from the published catalog, and `404` on a guessed id.
`resident_sectors` still carries the older sector tags and is not yet served by an endpoint;
when it is, it must adopt the same rule rather than the presentation-layer one.

---

## Not gaps — already aligned

Worth recording, so nobody "fixes" them:

* **Money is integer centavos** on both sides (Angular `Money`, conventions §6).
* **Client permission checks are usability only** — both client constitutions say so.
* **`X-Client-Channel` is telemetry** — the mobile client's `request_context.dart` cites
  ADR 0002 and sends no authority-shaped header.
* **The resident mobile client already implements the shipped envelope**, error codes,
  request-id shape and bearer auth.
* **PhilSys last-four only** (RA 11055) — consistent across the client and this contract.
* **`CaseNoteVisibility`** already distinguishes `internal` from `shared-with-applicant`,
  which the backend adopts as its authorization discriminator.
