# Runbook — Environments, Configuration and Secrets

How local, staging and production differ, what each must have set, how secrets are handled
and rotated, and which controls exist only outside this repository.

Provider responsibilities and the domain model: [deployment topology](../architecture/deployment-topology.md).
Decisions: [ADR 0004](../adr/0004-deployment-topology-and-provider-responsibilities.md)
(topology), [ADR 0005](../adr/0005-cross-origin-authentication.md) and
[ADR 0006](../adr/0006-admin-console-authentication.md) (authentication).

---

## 1. The three environments

| | Local | Staging | Production |
| --- | --- | --- | --- |
| API | `php artisan serve` on a laptop | `api-staging.<domain>` (Linode) | `api.<domain>` (Linode) |
| Browser clients | `localhost` dev servers | `portal-staging` / `admin-staging` (Netlify) | `portal` / `admin` (Netlify) |
| Database | `postgres` container | Managed PostgreSQL, own instance | Managed PostgreSQL |
| Redis | `redis` container | own instance, private network | own instance, private network |
| Objects | MinIO container | own bucket | own bucket |
| Mail | Mailpit — nothing leaves | real transport, seeded addresses only | real transport |
| Push | unset | staging Firebase project | production Firebase project |
| Data | synthetic | **synthetic** | real citizen data |
| `APP_DEBUG` | `true` | `false` | `false` |

**Nothing is shared between staging and production** — not a credential, a bucket, a
database, a Redis instance or a Firebase project. Sharing any one of them makes a staging
mistake a production incident.

**Staging holds synthetic data.** Copying production personal data into staging would put
citizen records in an environment with looser access and no comparable audit — a
proportionality failure under RA 10173, not merely untidy. If staging needs realistic
volume, generate it.

## 2. What every deployed environment must set

Names only; values live in the platform. Anything left unset fails closed.

| Variable | Consequence if unset or wrong |
| --- | --- |
| `APP_KEY` | Application will not boot. Rotating it invalidates every encrypted value. |
| `APP_DEBUG=false` | `true` attaches exception detail — class, message, file, line — to 500 responses. |
| `APP_URL` | Signed URLs and absolute links point at the wrong host. |
| `DB_*`, `DB_SSLMODE=require` | Unencrypted database traffic across the private network. |
| `REDIS_*` with a password | An unauthenticated Redis is a full read/write of cache, queues and rate-limit state. |
| `TRUSTED_PROXIES` | Laravel reads the load balancer as the client: rate limiting collapses to one shared bucket, signed URLs get the wrong scheme, audit trails name the proxy. Prefer the private CIDR over `*`. |
| `CORS_ALLOWED_ORIGINS` | Empty denies every cross-origin browser request — correct default, but the portals stop working, so set it per environment. |
| `OBJECT_STORAGE_*` | Least-privilege keys scoped to **this environment's** bucket only. |
| `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` | Path to a service-account file kept outside the repository. Never the file's contents. |
| `SANCTUM_STATEFUL_DOMAINS` | **Leave empty.** Setting it re-enables cookie/SPA auth and the CSRF surface ADR 0005 refused. |

Verify after deploy with `php artisan lguids:readiness` on the host, and by requesting
`GET /api/v1/health` through the load balancer.

## 3. Cross-origin, cookies and tokens

The rule, in one line: **every client authenticates with a first-party bearer token; no
session cookie crosses an origin.**

### Angular admin console (`admin.<domain>`, Netlify)

* Bearer token obtained from `POST /api/v1/auth/tokens`, held **in memory only** —
  not `localStorage`, not `sessionStorage`, not a cookie (ADR 0006). A page reload
  re-authenticates.
* Its origin must appear in `CORS_ALLOWED_ORIGINS` for that environment.
* `supports_credentials` stays `false` in `config/cors.php`. Turning it on couples the
  CORS allow-list to session security, where one careless entry becomes a session
  compromise.
* No CSRF token exchange and no `/sanctum/csrf-cookie` round trip: a bearer token is not
  attached ambiently by the browser, so there is nothing to forge.
* **The console must be served with a strict Content-Security-Policy.** This is not
  hardening — ADR 0006 accepts that an in-memory token is still readable by injected
  script, and names the CSP as the mitigation. Directives and rationale:
  [deployment topology § Content-Security-Policy](../architecture/deployment-topology.md#content-security-policy-required-by-adr-0005-and-adr-0006).

### Citizen web portal (`portal.<domain>`, Netlify)

Identical rules. Same token mechanism, same CORS treatment, same CSP obligation
(ADR 0005). It is a different delivery of the same services, not a different contract.

### Flutter mobile and verifier devices

Not browsers. CORS does not apply and no cookie is involved. They send
`Authorization: Bearer …`, `X-Client-Channel` and `X-Request-Id`, and an
`Idempotency-Key` on anything retryable. The channel header is telemetry and grants
nothing (ADR 0002).

### Local development

`CORS_ALLOWED_ORIGINS=http://localhost:4200` (Angular) or `:5173` (Vite) as needed. Leave
it empty and cross-origin browser calls are refused — which is the correct default, and a
common first-hour confusion.

## 4. Secrets

**Where they live.** The platform's environment/secret store, injected at process start.
Not in the repository, not in a build variable, not in an image layer, not in a ticket.

**Netlify holds none of them.** Build variables are public — anything Netlify can put in a
bundle is readable by anyone who loads the page. No Laravel secret, database credential,
object-storage key or Firebase service-account material may be configured there
(CLAUDE.md Article 8.2).

**Firebase service accounts** are files on the API host, referenced by path
(`FCM_CREDENTIALS_PATH`), readable only by the application user. Never inline JSON in an
environment variable, where it lands in process listings and crash dumps.

**Never printed.** Not in logs, error messages, tests, fixtures, docs or an agent
transcript. `lguids:readiness` redacts credentials out of driver exceptions before
printing them, because a readiness check that leaks a database password into a CI log has
done more harm than the outage it reported.

**Rotation.** Routine on a schedule, and immediately on any suspicion of exposure or when
someone with access leaves.

| Secret | How | Watch for |
| --- | --- | --- |
| `APP_KEY` | Generate new, deploy, then re-encrypt. **Rotating invalidates every value encrypted with the old key.** Plan a re-encryption step; do not rotate casually. | Encrypted columns, encrypted cache entries |
| Database password | Change on the managed instance, update the secret, restart the app tier | Queue workers and scheduler hold connections — restart them too |
| Redis password | Same shape as the database | Cache and queue state survive; workers need a restart |
| Object-storage keys | Issue a new least-privilege key pair, deploy, then revoke the old | Signed URLs already issued keep working until they expire |
| FCM service account | Create a new key in the Firebase project, replace the file, delete the old key | Keep both briefly so in-flight sends do not fail |
| API bearer tokens | Revoke server-side (`personal_access_tokens`) | Revocation is server-side; a client discarding a token is not revocation |

Every rotation ends the same way: `php artisan lguids:readiness` on the host, then a
request through the load balancer.

## 5. Data protection and retention

* Backups and PITR are **database-native**, plus an independent off-site copy per approved
  policy. Compute snapshots are not a DR plan (ADR 0004).
* Backups contain citizen personal data: encrypt at rest, restrict access as tightly as
  the live database, and record restore tests.
* A restore drill that has never been run is a hypothesis, not a plan.
* Deactivation is never a hard delete — retention of welfare records is statutory
  (see the residents contract in `docs/contracts/`).

## 6. Infrastructure gaps — not verifiable from this repository

These are real and outstanding. Nothing here has been tested against a provider, and no
provider was contacted while writing this.

| Gap | Owner |
| --- | --- |
| **Content-Security-Policy on both Netlify sites.** ADR 0005 and ADR 0006 depend on it; until it ships, the residual XSS risk both accepted is unmitigated. | Netlify config |
| Managed PostgreSQL provisioning, private networking, `sslmode=require` verified end to end | Linode/Akamai |
| Redis instance, password, private-network binding | Linode/Akamai |
| Object-storage buckets per environment with least-privilege keys | Linode/Akamai |
| Cloud Firewall and VPC rules restricting the database to the app tier | Linode/Akamai |
| DB-native backup schedule, PITR window, off-site copy, restore drill | Ops |
| Nginx/PHP-FPM configuration, TLS certificates, `TRUSTED_PROXIES` set to the real CIDR | Ops |
| Queue worker supervision (systemd/supervisor), restart-on-deploy | Ops |
| Log shipping and retention, with PII redaction verified in the pipeline | Ops |
| Separate Firebase projects and service accounts for staging and production | Firebase |
| PSA PSGC dataset for the five Taytay barangays (gap G-11) | Data |

## 7. What this repository cannot do

By constitution (CLAUDE.md Article 9) and by construction: no push, no merge to a
protected branch, no deploy, no production access, no credential change, no destructive
production operation. There is no provider client, token or endpoint configured anywhere
in this repository, and `docker compose` reaches nothing beyond the local machine.
