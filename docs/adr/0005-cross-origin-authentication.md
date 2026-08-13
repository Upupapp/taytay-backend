# ADR 0005 — First-party bearer tokens, not Sanctum cookie SPA auth

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 01, infrastructure revision)

## Context

ADR 0004 fixes the domain model: the browser clients are served by Netlify at
`portal.<domain>` and `admin.<domain>`, while the API runs on Linode/Akamai at
`api.<domain>`. The infrastructure revision requires that, before adopting Sanctum's
cookie/SPA mode, we **explicitly verify same-site, cookie, domain, CORS and CSRF
behaviour against the real domains** — and that if it cannot be made safe, we document and
approve a first-party token or BFF alternative *rather than weakening controls*.

This ADR records that verification and the resulting decision.

## Verification against the real domains

Sanctum's cookie mode authenticates a browser with the Laravel session cookie plus CSRF
token. Checked against `portal.<domain>` → `api.<domain>`:

| Concern | Finding |
| --- | --- |
| **Same-site** | `portal.<domain>` and `api.<domain>` are different hosts under one registrable domain. A cookie scoped to `.<domain>` is *same-site* but *cross-origin*. It works, but only by widening the cookie's scope to every present and future subdomain. |
| **Cookie scope** | Making it work requires `SESSION_DOMAIN=.<domain>`. The session cookie is then sent to every subdomain — including any future marketing, docs or third-party-operated host. One weak subdomain becomes a session-theft path. |
| **CORS** | Cookie mode requires `supports_credentials => true` plus an exact origin echo. `config/cors.php` currently sets credentials **off** by design; turning it on couples our CORS policy to session security, where one careless origin entry becomes a session compromise. |
| **CSRF** | Cookie auth is ambient: the browser attaches it automatically, so every state-changing endpoint needs CSRF protection and a `/sanctum/csrf-cookie` round trip before each session. Bearer tokens are not ambient and are structurally immune to CSRF. |
| **Preview contexts** | Netlify Deploy Previews get generated `*.netlify.app` origins. Under cookie mode those origins would need to be trusted to test authenticated flows — trusting a shared provider domain with production session cookies is not acceptable, and ADR 0004 already points previews at staging. |
| **Other clients** | The Flutter app and verifier devices cannot use cookies at all, so cookie mode would mean **two** authentication paths to keep correct. Article 3.1 exists to prevent exactly that duplication. |

## Decision

**All four clients authenticate with first-party Sanctum bearer tokens over HTTPS.**
Sanctum's cookie/SPA mode is not enabled.

* One authentication path for citizen web, citizen mobile, admin console and verifier
  devices, so an auth fix lands everywhere at once.
* `config/cors.php` keeps `supports_credentials => false` and its deny-by-default origin
  allow-list. No `SESSION_DOMAIN` widening; no wildcard subdomain cookie.
* No CSRF surface: a bearer token is never attached ambiently by the browser.
* `config('api.actor_guard')` stays `sanctum`, which is what TAB 01 already implemented —
  this ADR records *why*, having now checked it against the real domains.

Consequences the browser clients must handle, and the mitigations required of them:

* A token in browser storage is readable by injected script, so **XSS becomes the primary
  token-theft risk**. This is accepted deliberately, against a cookie's ambient-CSRF and
  subdomain-scope risks, and is mitigated by: short token lifetimes with refresh, strict
  Content-Security-Policy on the Netlify-hosted portals, server-side revocation, and
  binding tokens to a client channel for audit.
* Logout must revoke server-side. Discarding a token client-side is not revocation.

## Revisit criteria

Reopen this decision if any of these become true — with a superseding ADR, not a config
change:

1. The portals move to the same origin as the API (a reverse-proxied `/api` path), which
   removes the cross-origin problem entirely and makes cookies the better choice.
2. A BFF is introduced on a first-party origin to hold the session and forward
   server-to-server, which would also be preferable to tokens in browser storage.
3. A security review judges the XSS-vs-CSRF trade-off to have inverted for these clients.

Option 1 or 2 is the preferred long-term direction. Neither is available today, because
ADR 0004 fixes the portals on Netlify and the API on Linode as separate origins.

## Alternatives rejected

* **Sanctum cookie/SPA mode with `SESSION_DOMAIN=.<domain>`.** Rejected: it buys XSS
  resistance by widening cookie scope to all subdomains, enabling credentialed CORS and
  adding a CSRF surface — weakening three controls to strengthen one, which the
  infrastructure revision explicitly forbids.
* **A BFF on Netlify Functions holding the session.** Rejected under ADR 0004: Netlify
  Functions must not own authentication authority.
* **Two auth modes — cookies for web, tokens for mobile.** Rejected: two paths means two
  places to get authorization wrong, contrary to CLAUDE.md Article 3.1.

## Sources

* Laravel Sanctum — SPA authentication, stateful domains, CSRF requirements:
  <https://laravel.com/docs/13.x/sanctum#spa-authentication>
* OWASP — cross-site request forgery and token-storage trade-offs:
  <https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html>
* MDN — `SameSite` cookies and the site-vs-origin distinction:
  <https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite>
