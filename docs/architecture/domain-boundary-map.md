# Domain Boundary Map

Status: **authoritative** for module ownership. Changing a boundary requires an ADR.

Every fact in this system has exactly one owning module. If you need data you do not own,
call the owner's `Application/` service — do not query its tables, and do not create an
Eloquent relationship across the boundary.

---

## 1. Module inventory

| Module | Owns (canonical source of truth) | Must NOT own | Status |
| --- | --- | --- | --- |
| `Shared` | API envelope, error codes, pagination, request context, actor context, base contracts | any business rule, any table | **implemented** |
| `AccessControl` | roles, permission catalog, role→permission mapping, staff scope resolution and enforcement, explicit barangay grants, staff provisioning, authorization decisions | who a person *is*, staff accounts themselves (asks `Identity`), credential validity | **implemented** (TAB 07) |
| `ServiceCatalog` | the catalog of LGU services offered; assistance **programmes**, their requirement templates and their eligibility guidance; the **service provider directory** of external offices referrals are sent to | applications submitted against a service or programme (asks `Welfare`); who a person *is* (asks `ResidentProfile`); any determination of eligibility — the guidance advises and never decides (ADR 0018 §3); referrals themselves, which are casework | **implemented** (reference vertical; programmes TAB 13; providers TAB 16) |
| `Identity` | accounts, credentials-to-log-in, sessions, tokens, devices, MFA, account lifecycle | resident demographics, ID cards | **implemented** (TAB 05) |
| `ResidentProfile` | resident master record, demographics, addresses, verification tier, KYC cases, change history and aliases, duplicate pairs and merges, correction requests, account→resident links, households, families, effective-dated membership, kinship, vulnerability observations and the decision-support snapshot | login credentials, issued ID cards, eligibility decisions (the vulnerability score gates nothing — ADR 0015 §3) | **implemented** (TAB 06, TAB 08, TAB 09, TAB 10) |
| `Credential` | LGU ID lifecycle, card artifacts, QR credential material | who may approve (asks `AccessControl`) | **implemented, feature-flagged off** (TAB 06) |
| `Verification` | verification attempts, scan events, verifier registry, offline-verification key distribution | credential state (asks `Credential`) | planned |
| `Welfare` | social welfare cases: the canonical lifecycle, transitions, assignment, priority, the case timeline, assistance intake, drafts, assessment, **programme enrolment and assistance history**, **referrals and their disclosure records**, **field visits, the running record and safeguarding** | who a person *is* (asks `ResidentProfile`), the programme catalogue and the **provider directory** (asks `ServiceCatalog`), document storage (asks `Files`), eligibility decisions derived from a vulnerability score (ADR 0016 §4), money movement (TAB 18) | **implemented** (TAB 11; enrolment TAB 14; referrals TAB 16) |
| `Files` | stored objects on the private disk, the documents presented against them, their append-only version history, and single-use access grants | **who may see a document** — it cannot know; the owning module authorises and then calls in (ADR 0020 §1). Also: what a document *means* to a case, and any HTTP surface of its own | **implemented** (TAB 15) |
| `Tasks` | work queues, the tasks in them, and the listeners that raise tasks from domain events | **what a task points at** — it holds a type and an identifier and never a title, a name or a narrative; and **case outcomes**, which a queue action must never change (ADR 0024 §2–3) | **implemented** (TAB 19) |
| `ServiceDelivery` | service applications/transactions against catalog entries (dokumento, buwis, kalusugan, trabaho, national referrals), their state machines and attachments | the catalog itself (asks `ServiceCatalog`); welfare casework (asks `Welfare`) | planned |
| `Notification` | outbound dispatch, delivery receipts, device push registrations, per-person channel preferences | **why** a notification was triggered — Welfare decides that a case moved, this module decides how to say it; and any push payload beyond routing information (ADR 0025 §2) | **implemented** (TAB 20) |
| `Reporting` | dashboard aggregates, the closed report catalogue, and the export request/build/download lifecycle | **any fact** — every number is counted from another module's tables, and a read model that could write would be a second authority; also any per-caseworker grouping (ADR 0026 §1, §4) | **implemented** (TAB 21) |
| `Search` | record discovery and saved filter presets | **any record and any index** — every searcher runs a scoped query against the owning module's table, because an index maintained alongside the authorization rules eventually disagrees with them, invisibly (ADR 0027 §1) | **implemented** (TAB 22) |
| `Audit` | append-only audit trail of privileged actions, personal-data reads and lifecycle transitions | anything mutable | planned |

`Identity` and `ResidentProfile` are deliberately **separate**. An account is a way to
authenticate; a resident is a person the LGU serves. They are not 1:1 — a resident may
exist with no account (walk-in, assisted registration), and one account may be authorized
to act for several residents (guardian/representative flows). Collapsing them now would
force a rewrite later.

`Credential` and `Verification` are separate because verification must be able to run at
the edge (kiosk/verifier device, possibly offline) against published key material without
being granted read access to the credential holder's personal data.

`AccessControl` owns *authority*; `Identity` owns *accounts*. Staff provisioning therefore
spans both, as two collaborating application services rather than one writing the other's
tables: `AccessControl\Application\StaffProvisioningService` decides and records who may do
what, and calls `Identity\Application\StaffAccountProvisioner` for the account itself
(ADR 0012). Neither reads the other's schema.

---

## 2. Allowed dependency directions

```
                       Shared
                          ^
      ┌────────────┬──────┴──────┬─────────────┬──────────────┐
  AccessControl  Identity  ServiceCatalog  Notification     Audit
      ^   ^          ^            ^
      │   └───── ResidentProfile ─┘
      │                ^
      │            Credential ──────────> Verification
      │                ^
      └──────── ServiceDelivery ──────────┘
```

* Everything may depend on `Shared`.
* Everything may ask `AccessControl` for an authorization decision, and may emit to
  `Audit` and `Notification`.
* No cycles. If you need a cycle, you have found a missing module or a domain event.
* Downward calls (a lower module needing a higher one) must be inverted with a domain
  event, not a direct call.

Both rules are now **enforced**, by
`ModuleBoundaryTest::the_module_dependency_graph_has_no_cycles()`. They were documentation
only until TAB 08, and the first thing that happened when a real case arrived was that the
rule got broken: a resident merge has to repoint the absorbed person's credentials, and
calling `Credential\Application` from `ResidentProfile` closed the cycle
`ResidentProfile → Credential → ResidentProfile`. Every existing assertion passed, because
the import was of a public layer — only the *direction* was wrong.

The inversion is the pattern to copy (ADR 0013 §6):

```
ResidentProfile  ──dispatches──>  Contracts\ResidentMerged
                                        │
Credential  ──listens (registered in its OWN provider)──┘
            └─> reassigns its own rows, returns the count
```

ResidentProfile announces what happened and knows nothing about who cares. The listener is
registered in `CredentialServiceProvider`, not in ResidentProfile, which is what keeps the
dependency one-directional. Listener return values are collected by the dispatcher, so the
merge record can still report how many credentials moved without this module knowing that
credentials exist.

Such listeners run **synchronously, inside the originating transaction**. Queued handling
would let a merge commit while a credential still pointed at a soft-deleted resident.

**A module with no routes is the other shape this takes.** `Files` publishes application services
and nothing else, because it cannot answer *may this caller see this document* — only the module
owning the record can. Everything crosses that boundary through `DocumentLibrary`, in UUIDs and
published views, so a consumer holds no Eloquent model: it cannot reach a file's bytes and cannot
rewrite a version's history even by mistake. Two guarantees that would otherwise be habits are
properties of the code instead (ADR 0020 §1).

**The inversion is not a style preference.** Where ResidentProfile *already* depends on a module
it calls it outright — `AccountLinkService::reassign()` repoints `accounts.resident_id` directly,
because ResidentProfile → Identity is the established direction. The event exists only for the
modules that depend on ResidentProfile and would therefore close a cycle. Picking the wrong one
does not produce a worse design; it produces a failing `ModuleBoundaryTest`.

**And a module with neither is the failure this pattern actually has.** Welfare was added in
TAB 11 storing `resident_id` on four tables and nothing connected it to the merge, so a merge
stranded welfare cases on a soft-deleted resident with no exception, no constraint and no red
test — a domain event with one listener looks exactly like a domain event with a missing one.
Since TAB 14, `ResidentMergeCoverageTest` reads the live schema and requires every module holding
`resident_id` to have one of the two mechanisms. **Adding a module now means answering it**
(Article 2.4).

---

## 3. Multi-client mapping

The same application service serves every channel; only the *actor* and the
*authorization decision* differ.

| Use case | `citizen-web` / `citizen-mobile` | `admin-console` | `verifier-device` |
| --- | --- | --- | --- |
| List services | published services, channel-filtered | all services incl. drafts (`services.view_unpublished`) | n/a |
| View a resident | own record only | scoped by office/jurisdiction + audited | n/a |
| ID application | submit, track own | review, approve, reject | n/a |
| Verify a credential | show own QR | n/a | validity + minimal display fields only |

Consequences, enforced in code:

* There is no "admin service" and "citizen service" pair. There is one service plus an
  `ActorContext`.
* The `/api/v1/admin/...` prefix is **routing convenience only**. It confers nothing.
  Authorization is the permission check inside the request, not the URL.
* A client-supplied role, permission list or channel header is untrusted input.

---

## 4. External integration boundaries

Module boundaries govern code. These govern the providers the modules talk to
(ADR 0004, `deployment-topology.md`). The rule is the same one, restated: **a fact has one
owner, and it is always a module in this repository.**

| External system | Role | Owning module | Never |
| --- | --- | --- | --- |
| Akamai Managed PostgreSQL | canonical relational store | every module owns its own tables | shared tables across modules |
| Akamai Object Storage (`object-storage` disk) | private blob store for documents, ID artifacts, attachments | `Credential`, `ServiceDelivery` | public URLs; anything citizen-derived on the `public` disk |
| Redis | queues, cache, locks, rate limits | `Shared` (infrastructure) | a source of truth — it is a cache, and Article 6 requires caches be derivable |
| Firebase Cloud Messaging | push **transport** to the Flutter app | `Notification` | deciding *whether* to notify, *who* may receive, or *what* may be said; carrying PII in a payload |
| Firebase Crashlytics / Performance / Analytics | app operations telemetry | n/a (client-side) | any citizen PII, case narrative, document identifier or welfare detail as a property or key |
| Firebase App Check | anti-abuse signal | n/a | substituting for authentication, RBAC, object authorization or rate limiting |
| Netlify | delivery of the two browser portals | n/a | holding secrets; owning auth, workflows, KYC, case state, files, capacity or moderation |

Two consequences worth stating plainly:

* **`Notification` owns the decision; FCM owns the delivery.** When `Notification` is
  built, its application service decides that an event warrants telling someone, resolves
  who may be told through `AccessControl`, and records the dispatch. FCM is an adapter
  behind an interface in `Notification/Infrastructure/` — swappable for SMS or email
  without touching a single business rule.
* **No Firebase Auth, Firestore, Realtime Database or Firebase Storage.** Each would
  create a parallel identity or store with its own authorization model, which is exactly
  the split-brain this map exists to prevent. Adding one requires a new ADR.

---

## 5. Boundary enforcement

* `tests/Architecture/ModuleBoundaryTest.php` — no module references another module's
  `Domain\` or `Infrastructure\` namespace.
* `tests/Architecture/NoFrontendCodeTest.php` — no frontend assets, no bundler config,
  no `package.json`, no view templates.
* `tests/Architecture/InfrastructureAlignmentTest.php` — no Firebase parallel authority or
  store, private object storage, portable migrations, no browser-exposed secrets.
* `tests/Architecture/DatabaseConventionsTest.php` — ADR 0008 holds: no application state
  in JSON, every migration reversible, time zones on datetimes, a public UUID per table,
  and no cross-module foreign key. Schema shape is verified against a live database by
  `tests/Feature/Database/FoundationSchemaTest.php`.
* `tests/Architecture/LocalInfrastructureTest.php` — the local stack mirrors the
  production topology, images are pinned, stateful services bind to loopback, and no
  credential or deployed hostname reaches `.env.example` or `docker-compose.yml`.
* `tests/Architecture/ContractMatrixTest.php` — the published contract
  ([`docs/contracts/`](../contracts/README.md)) and the registered routes agree in both
  directions, and no endpoint is specific to one client.
* `config/modules.php` — the registry; a module that is not listed is not loaded.
