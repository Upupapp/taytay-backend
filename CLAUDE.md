# Taytay Rizal LGU IDS — Backend Constitution

This file is the highest-authority document in this repository. Every change, review and
generated artifact must comply with it. Where a task instruction and this constitution
conflict, raise the conflict explicitly before writing code.

---

## Article 0 — What this repository is

This repository is the **backend-only** system of record for the Taytay, Rizal LGU
Identity & Services platform (LGU IDS). It exposes a versioned REST/JSON API consumed by
**multiple independent clients**:

| Channel | Client | Repository |
| --- | --- | --- |
| `citizen-web` | Citizen web portal | separate frontend repo |
| `citizen-mobile` | Flutter app (`lgu_ids_taytay`) | separate frontend repo |
| `admin-console` | LGU staff/admin console | separate frontend repo |
| `verifier-device` | QR/credential verification device or kiosk | separate frontend repo |

**No frontend code is ever generated, committed or maintained in this repository.**
No Blade UI, no Vue/React/Svelte, no Tailwind, no bundler, no `resources/js`, no
`resources/css`, no `package.json`. The Laravel skeleton's frontend scaffolding has been
deliberately removed and this is enforced by an automated test
(`tests/Architecture/NoFrontendCodeTest.php`). Do not reintroduce it.

The only non-JSON responses this backend may emit are operational artifacts that have no
UI role (for example a generated PDF/PNG credential asset or a CSV export).

---

## Article 1 — Technology baseline

* PHP **8.3+**, Laravel **13.x**.
* **Modular monolith** — one deployable, hard internal domain boundaries.
* REST/JSON only, under **`/api/v1`**. No GraphQL, no RPC.
* Tests: PHPUnit via `php artisan test`.
* Style: Laravel Pint (`vendor/bin/pint`).
* Persistence: **PostgreSQL** in real environments (Akamai Managed PostgreSQL where
  regionally available), SQLite in tests. Migrations stay portable — no vendor-specific
  raw SQL — so the managed service remains a deployment choice, not a lock-in.

---

## Article 2 — Modular monolith rules

Domain code lives in `modules/<Module>/`, autoloaded as `Modules\<Module>\`.
`app/` holds framework wiring only (bootstrap providers, global middleware) — it must not
accumulate business logic.

Each module has the same internal shape:

```
modules/<Module>/
  Domain/          # entities, value objects, domain rules, domain events. No framework HTTP.
  Application/     # use cases (actions/queries) — the ONLY entry point for other modules.
  Contracts/       # the module's published vocabulary — the only types others may import.
  Infrastructure/  # persistence, external adapters, implementations of Domain contracts.
  Http/            # thin controllers + form requests + resources (adapters, no logic).
  Providers/       # module service provider (bindings).
  Routes/          # api_v1.php
```

Rules:

1. **A module may not reach into another module's `Domain/` or `Infrastructure/`.**
   Cross-module communication happens only through the other module's `Application/`
   services or published `Contracts/`. Enforced by
   `tests/Architecture/ModuleBoundaryTest.php`.
2. **No cross-module Eloquent relationships or joins.** Reference other modules by
   identifier, resolve through their application service.
3. `Modules\Shared` is the only module every other module may depend on. `Shared` may
   depend on nothing but the framework.
4. Adding a module requires: the directory shape above, registration in
   `config/modules.php`, and an entry in `docs/architecture/domain-boundary-map.md`.
5. Modules are units of *ownership and dependency*, not deployment. Do not split this
   into microservices without a new ADR.

---

## Article 3 — Multi-client rules (non-negotiable)

1. **One domain, many adapters.** Business logic exists exactly once, in
   `Application/`. Citizen web, mobile, admin console and verifier devices call the same
   application services. A behaviour that exists for only one client is still implemented
   in the shared service and *authorized* per actor — never forked per client.
2. **Never build a client-specific business rule into a controller.** Controllers may
   only: validate shape, build a command/query, call the application service, and shape
   the response.
3. **The client channel is telemetry, never authority.** `X-Client-Channel` is recorded
   for auditing and may adjust presentation/pagination defaults. It must never grant,
   widen or imply permission. Enforced by
   `tests/Feature/Api/V1/ClientChannelIsNotAuthorityTest.php`.
4. **No business-critical authorization may be delegated to frontend UI state.** Any
   value that arrives from a client — role name, permission list, `is_admin` flag, hidden
   form field, feature-flag echo, "admin" route prefix — is untrusted input. Every
   protected operation resolves the actor server-side and asks the server-side
   authorization service. A hidden button is not access control.
5. **Deny by default.** A route with no explicit authorization decision is a bug. New
   endpoints must be authorized explicitly, and unauthenticated access must be an
   affirmative choice recorded in the route file.
6. **Response shape is stable across channels.** Clients differ in *what they are allowed
   to see*, not in envelope format. Field-level redaction is a server-side authorization
   concern.

---

## Article 4 — API conventions

Defined once in `Modules\Shared\Http` and used by every endpoint. Full specification:
`docs/api/conventions.md`.

* Versioning: `/api/v1`. Breaking changes require `/api/v2`, never an in-place mutation.
* Success envelope: `{ "data": ..., "meta": { ... } }`.
* Error envelope: `{ "error": { "code", "message", "details", "request_id" } }`.
  `code` is a stable machine-readable `SCREAMING_SNAKE_CASE` string; `message` is for
  logs/operators and must never leak internals, stack traces or SQL.
* Every response carries `X-Request-Id`; it is echoed inside error payloads so a citizen
  can quote it to a support desk.
* Collections are always paginated — never return an unbounded list.
* Timestamps are ISO-8601 UTC. Money is integer minor units. Identifiers exposed to
  clients are UUIDs; never expose auto-increment primary keys.

---

## Article 5 — Security, privacy and audit

1. **Privacy by design and by default.** This system holds Philippine personal data and
   is subject to the Data Privacy Act of 2012 (RA 10173). Default to the least data.
2. **Data minimization.** An endpoint returns the minimum fields its use case needs.
   Adding a personal-data field to a response requires a stated purpose.
3. **Least privilege / strong isolation.** A resident may only ever reach their own
   records. Staff access is scoped by role *and* by office/jurisdiction. Cross-resident
   access without an authorization decision is a critical defect.
4. **Explicit audit trails.** Every read of another person's personal data, every
   credential lifecycle transition, and every privileged administrative action is
   auditable. Audit records are append-only and are never edited or deleted.
5. **Never log** government identifiers, credential secrets, QR signing material, tokens,
   passwords or full addresses. Redact before logging.
6. **Secrets** live in the environment only. Never read, print, commit or echo `.env`
   values, keys or tokens — including into tests, fixtures, docs or error messages.
7. Authentication is server-side and token/session based. Verification of a credential is
   a server-side cryptographic decision; a client claiming "valid" is not evidence.

---

## Article 6 — Data and schema evolution

* **Canonical source of truth.** Each fact has exactly one owning module and one owning
  table. Duplication elsewhere is a cache and must be labelled and derivable.
* **Expand → migrate → contract.** Never rename/drop a column in one deploy: add the new
  shape, backfill, dual-write, cut over, then remove in a later change.
* Migrations are forward-only and must be safe to run against a populated table.
  Destructive operations require an explicit note in the migration.
* Lifecycle state (applications, credentials) is an explicit, enumerated state machine
  with recorded transitions — never an inferred boolean pair.

---

## Article 7 — Testing and definition of done

A change is done when:

1. `php artisan test` passes.
2. New behaviour has a test at the correct level — application services get unit/feature
   tests; every endpoint gets a feature test including its **unauthorized** path.
3. Architecture tests still pass (no frontend code, no module boundary violation).
4. `vendor/bin/pint --test` passes.
5. Docs updated when a boundary, convention or decision changed; material decisions get
   an ADR in `docs/adr/`.

---

## Article 8 — Infrastructure boundaries (binding)

Providers are fixed. Full detail: `docs/architecture/deployment-topology.md`, decided in
ADR 0004 (topology) and ADR 0005 (cross-origin authentication).

1. **Laravel on Linode/Akamai is the sole authority.** Netlify delivers the browser
   portals; Firebase carries mobile push. Neither is a backend. A second place where
   authority *appears* to live is the same defect as Article 3.4, one layer down.
2. **Netlify hosts frontends only.** Build variables are public — no Laravel secret,
   database credential, object-storage key or Firebase service-account material may be
   configured there. Netlify Functions/Edge Functions must never own authentication,
   welfare workflows, KYC, case state, files, event capacity or moderation. Deploy
   Previews use staging APIs and synthetic data.
3. **Firebase is transport, not authority.** Laravel decides that a notification is
   warranted, who may receive it and what it may say; FCM only delivers it, via HTTP v1
   with short-lived OAuth credentials from a securely stored service account. **Firebase
   Auth, Firestore, Realtime Database and Firebase Storage are not used** — introducing
   any as a parallel authority or store requires a new ADR. App Check is an extra signal
   only and never replaces authentication, RBAC, object authorization or rate limiting.
4. **Never send personal data to a third-party telemetry or push channel.** No PII, case
   narrative, document identifier or welfare detail in an analytics property, crash key or
   push payload. Send an identifier and a type; let the client fetch detail over the
   authenticated API.
5. **Sensitive objects are private.** Akamai Object Storage (`object-storage` disk) with
   least-privilege keys, delivered by an authorization-gated endpoint or a short-lived
   signed URL issued after a server-side authorization decision — never a public link.
   Nothing citizen-derived may be written to the `public` disk.
6. **Redis and PostgreSQL are never publicly reachable**, and staging never shares a
   credential, bucket, database or Firebase project with production.
7. **Do not weaken a control to make an integration work.** Where cookie-based SPA auth
   would have required widening cookie scope, enabling credentialed CORS and adding a CSRF
   surface, ADR 0005 chose first-party bearer tokens instead. Take the same route: change
   the approach, not the control.

---

## Article 9 — Operational prohibitions for agents

Never push, force-push, merge protected branches, deploy, rotate credentials, touch
production infrastructure or production data, or expose secrets. Local commits only, and
only when explicitly authorized. Preserve existing uncommitted work — inspect and
reconcile the working tree before editing; never reset, clean or revert someone else's
changes for convenience.
