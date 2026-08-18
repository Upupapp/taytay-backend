# ADR 0043 — A mandatory second factor, and when refresh gets built

* Status: **Accepted**
* Date: 2026-08-18
* Deciders: integration TAB 02 (backend + console)
* Relates to: [ADR 0005](0005-cross-origin-authentication.md), [ADR 0006](0006-admin-console-in-memory-bearer-token.md)

## Context

TAB 02 of the integration master command asks two things of authentication:
*"Verify MFA is enforced for every staff role, not merely available"*, and
*"Implement a short-lived, revocable, server-side refresh mechanism instead of yielding"*.

Verifying the first found a defect. Deciding the second found a conflict.

### The defect

`AuthenticationService::signInWithPassword` read:

```php
if ($account->requiresMultiFactor() && $account->confirmedTotpFactor() !== null) {
    return ['status' => 'mfa-required', ...];
}

return ['status' => 'authenticated'] + $this->tokens->issue(...);
```

A staff account whose type **requires** a second factor but which had never **enrolled** one fell
straight through to a full session. The second factor was therefore opt-in by enrolment: a member
of staff who never visited `POST me/mfa` was never asked for one, indefinitely. Three tests in the
suite asserted exactly this behaviour, which is why it survived — the suite encoded the bypass as
the expectation.

A second factor staff may decline is a second factor the office does not have.

### The conflict

`TokenService` has always stamped tokens with a `staff` or `citizen` ability, and **nothing
anywhere checked them** — no `tokenCan()`, no `ability:` middleware, no route constraint. The
grant read like enforcement at the point of issue and enforced nothing at the point of use.

That mattered immediately, because the obvious fix for the defect — refuse the sign-in — is a
lockout, not a control: `POST me/mfa` is itself authenticated, so an office with nobody enrolled
would have no route to compliance.

## Decision

### 1. A staff account with no confirmed second factor gets an enrolment-scoped session

`signInWithPassword` now answers `mfa-enrolment-required` and issues a token whose only ability is
`mfa-enrolment`, with the challenge-length TTL (`identity.mfa.enrolment_ttl_minutes`, 15 minutes)
rather than the 12-hour staff TTL. It is a step in signing in, not a working session.

### 2. Token abilities are enforced, globally and deny-by-default

`Modules\Shared\Http\Middleware\EnforceTokenAbilities` is appended to the API middleware stack. A
token carrying `mfa-enrolment` may reach `v1.me.show`, `v1.me.mfa.begin`, `v1.me.mfa.confirm` and
`v1.auth.tokens.destroy`, and is refused `403 FORBIDDEN` everywhere else.

Registered globally rather than per route, because the rule is deny-by-default (Article 3.5): a
route added next year is refused to a restricted token without anybody remembering to constrain
it. The restriction applies only where a route demands authentication — Sanctum resolves a bearer
token even on a public route, and refusing there would block `POST auth/tokens`, which is the very
route an enrolling staff member must reach to sign in properly afterwards.

`403`, not `404`. The existence of these endpoints is not a secret; the caller is a known staff
member holding a valid password, and telling them what is required is the difference between an
office that enrols and an office that files a support ticket.

### 3. Enrolling does not upgrade the token in place

After confirming a factor, the staff member signs in again and takes the normal
`mfa-required` → `auth/tokens/mfa` path. Mutating a live token's abilities would mean a credential
whose privileges change underneath it, which is harder to reason about and to audit than issuing a
new one.

### 4. Refresh is **not** built yet, and this is the reasoning

ADR 0006 accepted that a reload signs the user out and named the friction as the pressure that
would be applied against it. The master command instructs that the pressure be answered with a
refresh mechanism rather than by moving the token into web storage. Both are right, and neither
can be executed today, because **every place a refresh credential could live is refused by an
accepted decision**:

| Location | Refused by |
| --- | --- |
| `localStorage` / `sessionStorage` | ADR 0006; console constitution §2.5; TAB 02's own guardrail |
| A cookie | TAB 02's guardrail ("no token in a cookie"); and cross-origin delivery needs `supports_credentials`, refused by ADR 0005 and again by TAB 01's guardrail |
| A URL or query parameter | TAB 02's guardrail |
| A BFF holding the session | ADR 0006 considered it the strongest option in principle and rejected it for now: ADR 0004 forbids Netlify Functions owning authentication, so it needs its own first-party deployment |

There is no fifth place. A mechanism that survives a reload must persist something in the browser,
and each candidate is closed.

**So refresh is sequenced after TAB 13, and the reason comes from ADR 0006's own text.** That ADR
states its residual XSS exposure *"is unmitigated"* until the Content-Security-Policy is deployed,
and the CSP is TAB 13's. Introducing a second, longer-lived credential into the browser **before**
the mitigation the first one depends on exists would widen an already-open risk to buy convenience.

This is consistent with NIST SP 800-63B, which requires reauthentication at AAL2 at least every 12
hours regardless of activity. The staff token TTL is already 12 hours; reload-triggered
reauthentication is stricter than the standard requires, not weaker.

**What is done instead, now:** the console ends an expired session locally without a round-trip
that would only `401` again, and returns the user to the screen they were on rather than the
dashboard.

**What TAB 13 must decide, with the CSP in place:** whether refresh is a rotating, revocable,
device-bound refresh token in a cookie scoped to the API origin and a single path — which requires
superseding the `supports_credentials` half of ADR 0005 for that one route, and is a decision for
the security reviewer, not a code-review comment — or a first-party BFF as ADR 0006 anticipated.

## Consequences

* **Positive.** Every staff session now has two factors behind it, or cannot do anything. The
  ability grant is enforcement rather than decoration, and any future restricted token inherits a
  working mechanism.
* **Positive.** Enrolment cannot be skipped and cannot lock the office out.
* **Negative — operational, and it must be planned for.** Every existing staff account without an
  enrolled factor will, at cutover, sign in to an enrolment-only session. That is the correct
  behaviour and it is also a support event: enrolment must be run **before** the console goes live,
  not discovered on the first morning. Carried to TAB 19's runbook.
* **Negative.** A reload still signs the user out until refresh exists. The friction is real,
  deliberate, and now has a dated decision behind it rather than an omission.
* The console cannot yet drive enrolment itself — it reports what must happen and to whom. Building
  that screen is carried to TAB 03, where the console moves onto server-issued permissions and
  touches this surface anyway.

## Alternatives rejected

* **Refuse the sign-in outright when no factor is enrolled.** Safer-looking and in fact a lockout:
  the enrolment endpoints require authentication.
* **Let the enrolment token become a full session on confirmation.** A credential whose privileges
  change underneath it is harder to reason about and to audit than a new one.
* **Enforce abilities per route.** Rejected: 263 routes, and the failure mode of forgetting one is
  a restricted token reaching a screen it should not.
* **Ship refresh now with the token in an API-origin cookie.** Rejected for sequencing, not for
  design — it is the leading candidate, and it needs the CSP deployed and an ADR superseding part
  of ADR 0005 first.
