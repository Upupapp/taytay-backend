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
| `AccessControl` | roles, permission catalog, role→permission mapping, staff scope (office/jurisdiction), authorization decisions | who a person *is*, credential validity | **implemented** |
| `ServiceCatalog` | the catalog of LGU services offered (code, name, category, channel availability, publication state) | applications submitted against a service | **implemented (reference vertical)** |
| `Identity` | accounts, credentials-to-log-in, sessions, tokens, devices, MFA, account lifecycle | resident demographics, ID cards | planned — TAB 02 |
| `ResidentProfile` | resident master record, demographics, addresses, household links, verification tier | login credentials, issued ID cards | planned |
| `Credential` | LGU ID lifecycle (application → review → approval → issuance → active → suspended → expired/revoked), card artifacts, QR credential material | who may approve (asks `AccessControl`) | planned |
| `Verification` | verification attempts, scan events, verifier registry, offline-verification key distribution | credential state (asks `Credential`) | planned |
| `ServiceDelivery` | service applications/transactions against catalog entries (dokumento, buwis, kalusugan, trabaho, national referrals), their state machines and attachments | the catalog itself (asks `ServiceCatalog`) | planned |
| `Notification` | outbound notification dispatch, delivery receipts, per-resident channel preferences | why a notification was triggered | planned |
| `Audit` | append-only audit trail of privileged actions, personal-data reads and lifecycle transitions | anything mutable | planned |

`Identity` and `ResidentProfile` are deliberately **separate**. An account is a way to
authenticate; a resident is a person the LGU serves. They are not 1:1 — a resident may
exist with no account (walk-in, assisted registration), and one account may be authorized
to act for several residents (guardian/representative flows). Collapsing them now would
force a rewrite later.

`Credential` and `Verification` are separate because verification must be able to run at
the edge (kiosk/verifier device, possibly offline) against published key material without
being granted read access to the credential holder's personal data.

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

## 4. Boundary enforcement

* `tests/Architecture/ModuleBoundaryTest.php` — no module references another module's
  `Domain\` or `Infrastructure\` namespace.
* `tests/Architecture/NoFrontendCodeTest.php` — no frontend assets, no bundler config,
  no `package.json`, no view templates.
* `config/modules.php` — the registry; a module that is not listed is not loaded.
