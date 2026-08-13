# ADR 0004 — Deployment topology: Netlify, Firebase and Linode/Akamai responsibilities

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 01, infrastructure revision)

## Context

The platform's hosting providers are now fixed: Netlify delivers the browser clients,
Firebase carries mobile push, and Linode/Akamai Cloud runs the canonical backend. Three
providers means three chances to accidentally create a *second* place where authority
appears to live — a Netlify Function that "just checks a role", a Firestore collection
that becomes the real case store, a Firebase Auth identity that outranks ours.

That failure mode is precisely what ADR 0002 exists to prevent, restated at the
infrastructure layer.

## Decision

**Laravel on Linode/Akamai is the sole authority. Everything else is delivery or
transport.**

### Netlify — web delivery only

* Hosts and deploys the Admin (Angular) and Citizen Web portals, with separate
  production / staging / preview contexts, SPA fallback redirects, custom domains and
  HTTPS.
* Carries **only public build configuration**. Laravel secrets, database credentials,
  object-storage keys and Firebase service-account material must never reach a browser
  variable — anything shipped to a browser is public, whatever it is named.
* Deploy Previews point at staging/test APIs with synthetic data. A preview URL is
  effectively public and must never reach production citizen data.
* **Netlify Functions and Edge Functions are not the business backend.** They must not
  own authentication authority, welfare workflows, KYC, case state, files, event capacity
  or moderation. If a function needs one of those, it calls `api.<domain>` like any other
  client.

### Firebase — mobile messaging and app operations

* FCM is the primary push transport for the Flutter app. **Laravel remains the
  notification and business-event authority**: it decides that an event happened, who may
  be told, and what may be said.
* Laravel calls FCM HTTP v1 using short-lived OAuth credentials derived from a securely
  stored service account (`config/services.php` holds the *path* only).
* Separate Firebase projects/apps for staging and production, with separate credentials.
* Crashlytics and Performance may be used. Analytics is optional and consent-governed.
  **No citizen PII, case narrative, document identifier or welfare detail may ever be sent
  as an analytics property or a crash key.**
* App Check is an additional signal only. It never replaces Laravel authentication, RBAC,
  object-level authorization or rate limiting — a client attesting to its own integrity is
  the same category of evidence as a client claiming to be an admin.
* **Firebase Auth, Firestore, Realtime Database and Firebase Storage are not used.**
  Introducing any of them as a parallel authority or store requires its own ADR.

### Linode / Akamai Cloud — canonical backend

* Laravel API on Linode/Akamai compute behind Nginx/PHP-FPM.
* Production relational data on **Akamai Managed PostgreSQL** where regionally available.
  Migrations stay portable PostgreSQL — no vendor-specific raw SQL — so the managed
  service is a deployment choice, not a lock-in.
* Redis backs queues, cache, locks and rate limits, and is **never publicly reachable**.
* **Akamai Object Storage** (S3-compatible) is the production object store. Sensitive
  objects stay private with least-privilege keys, delivered through an
  authorization-gated streaming endpoint or a short-lived signed URL — never a permanent
  public link. Configured as the `object-storage` disk.
* Cloud Firewall plus VPC/private networking; database access restricted to the
  application tier and an approved admin path.
* **Compute backups are not a DR plan.** Database-native backup with PITR, plus
  independent off-site backups per approved policy.
* NodeBalancer and a second stateless API node when HA or measured load requires it.
  No Kubernetes without measured justification.

### Environment and domain model

| | Production | Staging |
| --- | --- | --- |
| Admin console (Netlify) | `admin.<domain>` | `admin-staging.<domain>` |
| Citizen portal (Netlify) | `portal.<domain>` | `portal-staging.<domain>` |
| API (Linode/Akamai) | `api.<domain>` | `api-staging.<domain>` |

Staging has its own database, object storage and Firebase credentials. Production and
staging never share a credential, a bucket or a database. Authenticated production traffic
uses first-party custom domains, never a provider-generated hostname.

## Consequences for this repository (what changed in TAB 01)

* Trusted proxies come from `config('api.trusted_proxies')` (`TRUSTED_PROXIES`), **empty
  by default**, applied in `SharedServiceProvider::boot()`. Behind Nginx/NodeBalancer,
  untrusted proxies mean Laravel sees the balancer as the client: rate limiting collapses
  to one shared key, signed URLs get the wrong scheme, and audit trails attribute every
  action to the load balancer.

  The first implementation put this in `bootstrap/app.php` using `env()`. That is a
  **silent no-op** — the `withMiddleware` closure runs when the HTTP kernel is resolved,
  before Laravel loads `.env`. It was caught by probing a forwarded request, which still
  reported the proxy as the client. `tests/Feature/Api/V1/TrustedProxyTest.php` asserts
  the behaviour, not the presence of a config key, because the broken version had the key.
* `config/filesystems.php` gains the private `object-storage` disk; the `public` disk is
  documented as forbidden for anything citizen-derived.
* `config/services.php` gains the `fcm` seam (project id + credentials *path*).
* `config/cors.php` already denies by default; the allow-list is the Netlify origins.
* Authentication across the `portal`/`api` origin split is decided in **ADR 0005**.

Deliberately **not** done in TAB 01: no Terraform, no CI/CD pipelines, no Nginx or server
provisioning, no FCM client code. Those are operational work for later TABs; TAB 01 fixes
the boundaries and the configuration seams so that work has somewhere to land.

## Alternatives rejected

* **Netlify Functions as a lightweight backend for "simple" reads.** Rejected: it creates
  a second authorization surface that will drift from Laravel's, which is the exact
  failure ADR 0002 forbids.
* **Firestore as the case/document store.** Rejected: it would split the canonical source
  of truth across two systems with two authorization models, breaking CLAUDE.md Article 6.
* **Firebase Auth as the identity provider.** Rejected: authentication must be a
  server-side decision this backend can reason about, audit and revoke.

## Sources

* Netlify — build environment variables are exposed to the browser at build time:
  <https://docs.netlify.com/environment-variables/overview/>
* Firebase Cloud Messaging HTTP v1 (OAuth 2.0 service-account credentials):
  <https://firebase.google.com/docs/cloud-messaging/migrate-v1>
* Firebase App Check is an anti-abuse signal, not authorization:
  <https://firebase.google.com/docs/app-check>
* Linode/Akamai Managed Databases, Object Storage, Cloud Firewall, VPC, NodeBalancer:
  <https://techdocs.akamai.com/cloud-computing/docs>
* Laravel trusted proxies: <https://laravel.com/docs/13.x/requests#configuring-trusted-proxies>
* Republic Act No. 10173 (Data Privacy Act of 2012): <https://privacy.gov.ph/data-privacy-act/>
