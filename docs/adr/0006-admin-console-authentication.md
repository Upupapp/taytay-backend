# ADR 0006 — Admin console authenticates with an in-memory bearer token

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 02)
* Relates to: [ADR 0005](0005-cross-origin-authentication.md), gap G-03

## Context

ADR 0005 chose first-party bearer tokens over Sanctum's cookie/SPA mode, after checking
cookie mode against the real `portal.<domain>` → `api.<domain>` origin split.

The Angular staff console was written to a different assumption. Its constitution states:

> *"No token is placed in `localStorage` by this app; session credentials travel in an
> HTTP-only cookie set by the API."* — `CLAUDE.md` §2.5

Both positions are sound, and they are incompatible. The Angular rule exists because a
token in `localStorage` is readable by any injected script — a real XSS consequence. The
backend rule exists because cookie mode across origins requires widening the cookie to
`.<domain>`, enabling credentialed CORS, and adding a CSRF surface.

Read narrowly, this looks like a choice between XSS exposure and CSRF exposure. It is not,
because "bearer token" and "`localStorage`" are not the same decision.

## Decision

**The admin console authenticates with a first-party bearer token held in memory only.**

* `POST /api/v1/auth/tokens` with real credentials returns a short-lived access token.
* The console holds it in a JavaScript variable — **not** `localStorage`, **not**
  `sessionStorage`, **not** a cookie. It does not survive a page reload, which is the
  point.
* On reload the console re-authenticates. A refresh mechanism may be added later, but it
  must not become a long-lived credential in web storage without superseding this ADR.
* `config/cors.php` keeps `supports_credentials => false` and its deny-by-default origin
  allow-list. `SANCTUM_STATEFUL_DOMAINS` stays empty.
* Logout revokes server-side. Discarding the variable is not revocation.

This satisfies the Angular constitution's actual requirement — nothing sensitive in web
storage — without reintroducing anything ADR 0005 refused.

## Rationale

1. **The two constraints were never in conflict.** The Angular rule forbids
   `localStorage`; it does not require cookies. An in-memory token satisfies both
   documents as written.
2. **A memory-only token narrows the XSS window** from "everything ever stored" to "this
   tab, while open". It does not eliminate XSS risk — injected script can read a variable
   too — but it removes persistence, which is what makes stolen tokens useful hours later.
3. **No CSRF surface at all.** A bearer token is not attached ambiently by the browser, so
   there is nothing to forge.
4. **One authentication path for four clients.** Mobile, verifier and both browser clients
   present the same kind of credential. An auth fix lands everywhere at once, which is
   CLAUDE.md Article 3.1 applied to authentication.
5. **It does not weaken a control to make an integration work** (Article 8.7). The
   integration changed instead.

## Consequences

* Positive: `config/cors.php`, ADR 0005 and the shipped mobile client all stay as they
  are. No backend change is required by this decision.
* Positive: the console has no ambient credential, so a malicious cross-origin page can do
  nothing on a staff member's behalf.
* Negative: a page reload signs the user out until refresh exists. For a console used in
  long casework sessions this is real friction, and it is the main pressure that will be
  applied against this decision. **Refresh must be solved with a short-lived, revocable,
  server-side mechanism — not by moving the access token into web storage.**
* Negative: XSS in the console still yields a usable token for the tab's lifetime. Mitigated
  by a strict Content-Security-Policy on the Netlify-hosted console, short token lifetimes,
  and server-side revocation. The console's existing lint and template rules help; a CSP is
  a Netlify configuration task recorded in the deployment topology.
* The Angular repository must update `api.contract.ts` and its HTTP adapters (gap G-01) and
  delete the credential-less `signInAs` path (G-02) before switching `dataSource` to
  `'http'`.

## Alternatives rejected

* **Cookie/SPA mode for the admin console only.** Rejected: it reopens the exact controls
  ADR 0005 closed, and it would give the platform two authentication models — one for
  browsers, one for devices — which is the duplication Article 3.1 forbids.
* **Token in `localStorage`.** Rejected: directly contradicts the Angular constitution, and
  turns one XSS into a durable credential leak.
* **A BFF holding the session.** The strongest option in principle, and the natural answer
  if reload friction becomes unacceptable. Rejected for now because ADR 0004 forbids
  Netlify Functions owning authentication, so a BFF would need its own first-party
  deployment — new infrastructure for a problem a refresh endpoint solves.

## Revisit criteria

Supersede this ADR — do not quietly reconfigure — if:

1. reload friction proves unworkable in real use and a refresh mechanism cannot fix it;
2. a first-party BFF origin becomes available; or
3. the console moves behind the same origin as the API, which makes cookies the better
   choice and removes the cross-origin problem entirely.

## Sources

* Laravel Sanctum — token abilities, SPA authentication, revocation:
  <https://laravel.com/docs/13.x/sanctum>
* OWASP — HTML5 / client-side storage guidance on not persisting session tokens:
  <https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html>
* OWASP — Cross-Site Request Forgery Prevention:
  <https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html>
