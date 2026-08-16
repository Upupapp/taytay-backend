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
| [G-09](#g-09) | Backend permission catalog holds 2 of ~31 permissions | high | backend |
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
**The backend permission catalog holds 2 of about 31 permissions.** `high`

`Modules\AccessControl\Contracts\Permission` has `services.view_unpublished` and
`services.manage`. The admin console references 31 across 7 roles.

Deliberately **not** added in TAB 02: permissions without the endpoints they guard, and
without persisted role assignment (`config/access_control.php` is still provisional), is
policy invented ahead of its use. The endpoint matrix records each permission against the
operation it guards so the catalog can be added with its endpoints.

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
`lgu_admin` that should not: `vulnerability.view.protected`, `document.view.sensitive`,
`case-note.view-protected`, `safeguarding.view`, `safeguarding.manage`, and
`referral.disclose.protected`. Each was placed there with the same note, and the accumulation is
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
