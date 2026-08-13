# ADR 0002 — One domain, many clients: authorization is server-side only

* Status: **Accepted**
* Date: 2026-08-13
* Deciders: backend architecture (TAB 01)

## Context

Four client channels consume this API: citizen web, citizen mobile (Flutter), admin
console, and verifier devices. They are separate codebases, released on their own
schedules, and — for the mobile app — installed on devices the LGU does not control. A
released mobile build cannot be trusted, cannot be instantly patched, and can be
decompiled or proxied.

Two failure patterns are common in LGU/enterprise systems of this shape:

1. Forking the backend per client ("citizen endpoint" vs "admin endpoint" with copied
   rules), which lets the copies drift until one of them is missing a check.
2. Letting the frontend decide authority — hiding an admin button, sending
   `is_admin: true`, or trusting an `/admin` URL prefix.

These are OWASP API Security Top 10 #1 (Broken Object Level Authorization) and #5 (Broken
Function Level Authorization), the two most damaging classes for a system holding
citizens' personal data.

## Decision

**One set of domain services, many thin HTTP adapters, and every authorization decision
made server-side from the authenticated actor.**

1. Business logic exists exactly once, in a module's `Application/` layer. Every channel
   calls the same service. Controllers only validate shape, build a command/query, call
   the service, and shape the response.
2. Client-varying behaviour is expressed as an **`ActorContext`** (who is authenticated,
   their server-resolved roles/permissions and scope) passed into the service — never as
   a per-client service, and never as a branch on the client's identity.
3. `X-Client-Channel` is **telemetry and presentation defaults only**. It is recorded for
   audit and may set a default page size. It can never grant, widen or imply permission,
   and a request that supplies an unknown channel is treated as `unknown` and proceeds
   with identical authority.
4. Any authority-shaped value arriving from a client — role name, permission array,
   `is_admin`, hidden field, feature-flag echo — is **ignored**. Roles and permissions are
   resolved server-side from persisted state via `AccessControl`.
5. The `/api/v1/admin/...` prefix is routing convenience only; authority comes from the
   permission check inside the request, not the URL.
6. **Deny by default.** A protected route with no explicit authorization decision is a
   defect; unauthenticated access must be an affirmative, visible choice in the route file.

## Enforcement (evidence, not intent)

* `Modules\Shared\Application\ActorContext` — the only sanctioned carrier of actor
  identity and permissions into a use case; constructed server-side.
* `Modules\AccessControl\Application\AuthorizationService` — the single decision point;
  `deny by default` when a permission is unknown.
* `tests/Feature/Api/V1/ClientChannelIsNotAuthorityTest.php` — asserts that spoofed
  `X-Client-Channel`, `X-Client-Role`, `is_admin` and admin-prefixed URLs grant nothing.
* `tests/Feature/Api/V1/SharedDomainServiceAcrossClientsTest.php` — asserts citizen and
  admin routes resolve the **same** controller action and application service, and differ
  only by authorization outcome.
* `tests/Feature/Api/V1/ActorContextIsolationTest.php` — asserts the actor is resolved per
  request and never survives into the next one.

### One-request-one-actor

The `ActorContext` is memoised on the `Request` object, never as a container singleton or
`scoped` binding. Container-lifetime bindings survive between requests on a long-lived
worker (Octane), which would hand one citizen's authority to the next caller. This was a
real defect during TAB 01, caught by a test that issues two requests with different
actors; the regression test above exists so it cannot return.

## Consequences

* Positive: a new client channel needs no new business logic — only routes and
  authorization decisions.
* Positive: an authorization bug is fixed once, for every channel simultaneously.
* Positive: the mobile app can be safely treated as hostile input, which it must be.
* Negative: convenience shortcuts ("just add an admin-only endpoint that skips the
  check") are prohibited, which occasionally costs extra work up front.
* Negative: clients cannot pre-compute what the server will allow; they must handle a
  `403 FORBIDDEN` for any action. This is intentional — a hidden button is not access
  control.

## Sources

* OWASP API Security Top 10 (2023) — API1:2023 Broken Object Level Authorization,
  API5:2023 Broken Function Level Authorization —
  <https://owasp.org/API-Security/editions/2023/en/0x11-t10/>
* OWASP Application Security Verification Standard, V4 Access Control ("access control
  decisions must be enforced on a trusted service layer") —
  <https://owasp.org/www-project-application-security-verification-standard/>
* Republic Act No. 10173 (Data Privacy Act of 2012) — proportionality, legitimate purpose
  and security-of-personal-information obligations — <https://privacy.gov.ph/data-privacy-act/>
* Laravel authorization (gates/policies) — <https://laravel.com/docs/13.x/authorization>
