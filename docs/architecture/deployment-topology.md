# Deployment Topology and Provider Responsibilities

Decision and rationale: [ADR 0004](../adr/0004-deployment-topology-and-provider-responsibilities.md).
Authentication across the origin split: [ADR 0005](../adr/0005-cross-origin-authentication.md).

This page is the operational reference. **One rule governs all of it: Laravel on
Linode/Akamai is the only authority. Every other provider is delivery or transport.**

---

## 1. Who is responsible for what

| Provider | Owns | Must never own |
| --- | --- | --- |
| **Netlify** | Build, hosting and CDN delivery of the Admin (Angular) and Citizen Web portals. SPA fallback redirects, custom domains, HTTPS, deploy contexts. | Authentication authority, welfare workflows, KYC, case state, files, event capacity, moderation — or any secret. |
| **Firebase** | FCM push transport to the Flutter app. Crashlytics, Performance, optional consent-governed Analytics. App Check as an extra signal. | Identity (no Firebase Auth), data of record (no Firestore/RTDB), files (no Firebase Storage), or any authorization decision. |
| **Linode / Akamai Cloud** | The Laravel API, PostgreSQL (canonical data), Redis, Object Storage, networking and firewalling. Every business rule and every authorization decision. | — |

### Netlify boundaries

* Build variables are **public**. Anything Netlify can put in a bundle is readable by
  anyone who loads the page. No Laravel secret, database credential, object-storage key
  or Firebase service-account material may be configured there.
* Deploy Previews use **staging APIs and synthetic data**. A preview URL is effectively
  public; production citizen data must never be reachable from one.
* Netlify Functions / Edge Functions are not the canonical backend. If one needs
  protected data it calls `api.<domain>` as a client and is authorized like any other.

#### Content-Security-Policy (required by ADR 0005 and ADR 0006)

Both authentication decisions depend on this control, so it is an obligation rather than
hardening polish:

* [ADR 0005](../adr/0005-cross-origin-authentication.md) accepts bearer tokens for the
  citizen portal, naming a strict CSP among the mitigations for the XSS exposure that
  choice carries.
* [ADR 0006](../adr/0006-admin-console-authentication.md) has the admin console hold its
  token **in memory**. That removes persistence, but injected script can still read a
  variable.

**If the policy below is not deployed, the residual risk both ADRs accepted is
unmitigated, not merely undocumented.**

Served as response headers from Netlify (`netlify.toml` `[[headers]]` or a `_headers`
file) on **both** browser clients — `admin.<domain>` and `portal.<domain>`, and their
staging hosts. Baseline:

| Directive | Value | Why |
| --- | --- | --- |
| `default-src` | `'self'` | Deny by default, as everywhere else. |
| `script-src` | `'self'` | **No `'unsafe-inline'`, no `'unsafe-eval'`.** Allowing either defeats the whole control. |
| `connect-src` | `'self' https://api.<domain>` | The API origin for this environment only — never a wildcard, never the production API from a staging or preview host. |
| `object-src` | `'none'` | No plugin content. |
| `base-uri` | `'none'` | Stops injected `<base>` from re-pointing relative URLs. |
| `frame-ancestors` | `'none'` | Clickjacking; a caseworker must not be framed into approving. |
| `form-action` | `'self'` | Stops injected forms exfiltrating to another origin. |
| `img-src` | `'self' data:` | App assets and inline data URIs only. |
| `upgrade-insecure-requests` | — | No mixed content. |

Alongside it: `Strict-Transport-Security` (with a long `max-age`),
`X-Content-Type-Options: nosniff`, and
`Referrer-Policy: strict-origin-when-cross-origin` — a referrer carrying a case
identifier to a third party is a personal-data leak.

Two implementation notes so the policy is not silently weakened to make the build work:

* Angular emits component styles as inline `<style>` blocks. Use its `ngCspNonce` support
  with a per-response nonce rather than adding `'unsafe-inline'` to `style-src`.
* Deploy Previews get generated `*.netlify.app` origins and must keep `connect-src`
  pointed at the **staging** API. A preview that can reach the production API contradicts
  the Deploy Preview rule above.

Not configured from this repository — Netlify holds no backend code and this backend
issues no CSP for pages it does not serve. Recorded here because it is a binding
deployment obligation, and it is verified by inspecting the deployed response headers.

### Firebase boundaries

* Laravel decides that a notification is warranted, who may receive it, and what it may
  contain. FCM only carries the message.
* Laravel calls **FCM HTTP v1** with short-lived OAuth credentials derived from a service
  account. `config/services.php` holds only the *path* to that file
  (`FCM_CREDENTIALS_PATH`); its contents are secret and are never committed or logged.
* Separate Firebase projects for staging and production.
* **Never send** citizen PII, case narratives, document identifiers or welfare details as
  analytics properties, crash keys or push payload fields. A push payload crosses a third
  party and is visible on a device lock screen — send an identifier and a type, and let
  the app fetch the detail over the authenticated API.
* App Check attests that a request came from a genuine app build. It does not say who the
  user is or what they may do; it never substitutes for authentication, RBAC, object-level
  authorization or rate limiting.

### Linode / Akamai boundaries

* Laravel behind Nginx/PHP-FPM. `TRUSTED_PROXIES` **must** be set to the private-network
  CIDR or NodeBalancer address, or Laravel will read the proxy as the client — collapsing
  rate limiting to a single key, breaking signed-URL schemes, and attributing every
  audited action to the load balancer.
* **PostgreSQL** (Akamai Managed where regionally available) is the canonical store.
  Migrations stay portable — no vendor-specific raw SQL — so the managed service remains a
  deployment choice.
* TLS in transit; access restricted by ACL/VPC/private networking to the application tier
  and an approved admin path.
* **Redis is never publicly reachable.** It backs queues, cache, locks and rate limits.
* **Akamai Object Storage** is the S3-compatible production store, wired as the
  `object-storage` disk: private visibility, least-privilege keys, no public base URL.
  Objects reach a citizen through an authorization-gated streaming endpoint or a
  short-lived signed URL issued *after* a server-side authorization decision.
* Cloud Firewall in front of everything.
* **Compute snapshots are not a DR plan.** Database-native backup with PITR plus
  independent off-site backups, per approved policy.
* Scale out with NodeBalancer and a second stateless API node only when HA or measured
  load requires it. No Kubernetes without measured justification.

---

## 2. Environments

| | Production | Staging |
| --- | --- | --- |
| Admin console | `admin.<domain>` (Netlify) | `admin-staging.<domain>` |
| Citizen portal | `portal.<domain>` (Netlify) | `portal-staging.<domain>` |
| API | `api.<domain>` (Linode/Akamai) | `api-staging.<domain>` |
| Database | Managed PostgreSQL (prod) | separate instance |
| Object storage | production bucket | separate bucket |
| Firebase | production project | separate project |

Production and staging **never share** a credential, bucket, database or Firebase project.
Authenticated production traffic uses first-party custom domains, never a
provider-generated hostname.

---

## 3. What the backend must have set per environment

These are names only — values live in the environment and nowhere else
(CLAUDE.md Article 5.6). See `.env.example`.

| Variable | Why it matters |
| --- | --- |
| `TRUSTED_PROXIES` | Correct client IP and HTTPS detection behind Nginx/NodeBalancer. Empty = trust nothing. |
| `CORS_ALLOWED_ORIGINS` | The Netlify portal origins for this environment. Empty = deny all cross-origin browser requests. |
| `DB_CONNECTION=pgsql`, `DB_SSLMODE=require` | Canonical store, encrypted in transit. |
| `OBJECT_STORAGE_*` | Akamai Object Storage bucket, endpoint and least-privilege keys. |
| `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` | Push transport; path only, never the key contents. |
| `REDIS_*` | Queues, cache, locks, rate limits — on the private network only. |
| `APP_DEBUG=false` | Debug detail is attached to 500 responses when true. |

Verify after any deploy or rotation with `php artisan lguids:readiness` on the host — it
writes and reads back through each dependency rather than pinging a port. Setting these
per environment, secret handling and rotation:
[runbooks/environments-and-secrets.md](../runbooks/environments-and-secrets.md).

---

## 4. Deliberately not in this repository

TAB 01 fixes boundaries and configuration seams. It does **not** contain Terraform,
CI/CD pipelines, Nginx or server provisioning, FCM client code, or Netlify configuration —
that is operational work for later TABs, and the seams above are where it lands.
