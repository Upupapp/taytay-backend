# Deployment and rollback

Status: **the procedure.** Reasoning: [ADR 0041](../adr/0041-delivery-and-release-gate.md).

Nothing here has been executed against production. That is stated rather than implied — see the
[release gate](../backend-release-gate.md).

---

## The rule everything else follows from

**Application code rolls back. Schema does not.**

A deploy that goes wrong is fixed by putting the previous release back, which takes a minute and
loses nothing. Rolling back a migration on a live database is a different act with a different risk
profile: `down()` on a populated table destroys the data added since, and the data added since is
the day's casework.

So **every migration must be safe for the currently deployed code to run against.** That is the
expand → migrate → contract rule (Article 6), and it is what makes the one-minute rollback possible.

### Expand, migrate, contract

Never rename or drop in one deploy.

| Release | Schema | Code |
| --- | --- | --- |
| 1 — **expand** | Add the new column, nullable | Writes both, reads the old |
| 2 — **migrate** | Backfill | Reads the new, still writes both |
| 3 — **contract** | Drop the old column | Reads and writes only the new |

Between each, **the previous release still works**. Roll back release 2 and release 1's code finds
the schema it expects.

Collapsing this into one deploy is what turns a bad afternoon into an outage: the migration lands,
the code fails, and rolling back the code leaves it looking at a column that no longer exists.

---

## Environments

Three, with **separate databases, separate object-storage keys, separate Redis and separate
Firebase projects** (Article 8.6).

| | `local` | `staging` | `production` |
| --- | --- | --- | --- |
| Database | local Postgres | its own | Akamai Managed |
| Object storage | local disk | its own buckets | its own buckets |
| Firebase | none | staging project | production project |
| Demo seeder | runs | runs | **refuses** |
| Retention | unapproved | unapproved | **must be approved before go-live** |

Staging shares **nothing** with production. A shared Redis means a staging worker can pick up a
production job; a shared Firebase project means a staging push reaches a real resident's phone.

Promotion is `development → staging → production`. Production deploys originate only from the
approved branch or tag.

---

## Deploying

### 1. Before anything

- [ ] CI is green on the exact commit being deployed. **Not "on main" — on the commit.**
- [ ] `git log <deployed-sha>..<new-sha>` read. What is actually shipping.
- [ ] Any migration reviewed against the expand/migrate/contract rule.
- [ ] **Backup taken and its restorability confirmed** if the release contains a migration. Not
      "a backup exists" — see the [DR runbook](backup-and-disaster-recovery.md).

### 2. Schema first, and only backward-compatible schema

```
php artisan migrate --force
```

Run **before** the new code, so the currently deployed code runs against the new schema for a few
seconds. That is the moment expand/migrate/contract pays for itself, and the moment a collapsed
migration breaks production.

### 3. Application code

Put the new release in place. Then:

```
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Caches **after** the code, never before — a cached route table from the previous release points at
controllers that may no longer exist.

### 4. Workers, after the code

```
php artisan queue:restart
```

Signals workers to exit after their current job; the supervisor restarts them. **Run it after the
new code is in place** — a worker that restarts into the old code has achieved nothing, and a
long-lived PHP process keeps running whatever it booted with.

### 5. Prove it

- [ ] `GET /api/v1/health` → `200`
- [ ] `GET /api/v1/admin/operations/readiness` → `ready: true` (needs the `operations_engineer`
      role)
- [ ] `php artisan schedule:list` renders. **If it throws, the scheduler is not running at all and
      the failure is silent.**
- [ ] `metrics.queues` — depth falling, not climbing.
- [ ] `metrics.jobs.failed_last_hour` — flat.

### 6. Watch, for longer than feels necessary

The failure that produces no error: **queue depth climbing while failed jobs stay flat.** Work is
arriving and nothing is consuming it. The symptom is a resident who never got a message, and it
arrives hours later.

---

## Zero-downtime, honestly

With **one node** there is no zero-downtime deploy — there is a short window where the process
restarts. That is acceptable for a municipal office outside working hours and it should be
acknowledged rather than claimed away.

With **two nodes behind a NodeBalancer**, drain one, deploy it, let it pass readiness, return it,
repeat. This works only because sessions, cache, queues and files are all external: any node can
serve any request. **Nothing in this application holds local state**, and that is what must stay
true if a second node is ever added.

---

## Rolling back

### Application code — the normal case

Put the previous release back and `queue:restart`. A minute, and nothing is lost.

This works because the schema is backward-compatible. If it is not, the release should not have
shipped.

### Schema — the case to avoid

**Do not roll back a migration on production to fix a bad deploy.** Roll back the code instead.

If a migration genuinely must be reversed:

1. Stop the workers. A worker mid-job against a half-reverted schema writes corruption.
2. Confirm the backup and **read its timestamp** — reversal may lose everything since.
3. Reverse the specific migration, not `migrate:rollback` with a step count that catches others.
4. Verify against live counts, not "it started".

A migration marked as destructive in its own file (Article 6) **has no safe reversal**. The
recovery is a restore, and the [DR runbook](backup-and-disaster-recovery.md) is the procedure.

---

## Release order across all three clients

The backend goes first and stays compatible, because the mobile app is on somebody's phone and may
not be updated for months.

1. Backup; confirm restore readiness.
2. **Backward-compatible** migration.
3. Laravel API and workers; health and readiness pass.
4. Netlify web clients, against the versioned API.
5. Flutter mobile through its distribution process, with the right Firebase project selected.
6. Observe logs, queues, FCM failures and database health.

**A backend deploy must never require a simultaneous client deploy.** If it does, it is a breaking
change and needs `/api/v2` and a deprecation window (`CHANGELOG_API.md`) — not a coordinated
release, which is a coordinated outage waiting for the one client that is late.

---

## Netlify

Frontends only. No Laravel secret, database credential, object-storage key or Firebase
service-account material may be configured there — **build variables are public** (Article 8.2).

- Separate sites for Admin and Citizen Web, with per-context API URLs.
- SPA fallback redirects configured.
- Production deploys from the approved branch or tag only.
- **Deploy Previews point at staging**, with synthetic data. Never production credentials.
- Custom domains with HTTPS. HSTS preload **only** once every required subdomain is HTTPS-ready —
  it cannot be undone from the server.

## Firebase

Separate staging and production projects, so device tokens and diagnostics cannot cross. FCM
credentials live **only** on the backend secret path. Verify push on both platforms, plus token
refresh and revocation — **push fails silently**, so watch `notifications.failed_last_hour` after
any credential change.

---

## Deployment order, per release (TAB 18)

*"The API deploys before the console when the console needs a new endpoint; the console deploys
before the API when the API removes one. Write down which, per release."*

The order is not a judgement somebody makes — it is a fact about the diff. What makes it hard is
that **one of the two directions is invisible**.

Adding an endpoint is deliberate: whoever writes the console call knows the API must ship first.
Removing one is not. A controller method deleted during a tidy-up, a route file reorganised, a
resource collapsed into another — none of those feel like a breaking change to the person making
them, and the console that still calls the path finds out in production.

So the published surface is committed:

```
docs/api/routes.published.json     287 routes, generated by `php artisan lguids:routes --write`
```

`PublishedRoutesAreStableTest` fails when the live router and that file disagree in either
direction. Removing a route now requires editing the file in the same change, which is what turns
an invisible removal into a line a reviewer sees.

### Reading the diff

| What the diff shows | Order for this release |
| --- | --- |
| Lines **added** to `routes.published.json` | **API first**, then the console |
| Lines **removed** from it | **Console first**, then the API |
| Both, in one release | **Split the release.** No single ordering is safe — see below |
| Neither | Either order |

### Why "both" means split, rather than pick one

A release that adds an endpoint the new console needs *and* removes one the old console calls has
no safe ordering:

* API first — the removal lands while the old console is still deployed, and it 404s.
* Console first — the new console calls an endpoint the API does not serve yet, and it 404s.

The answer is two releases, not a shorter deployment window. This is exactly the case a
per-release note written from memory never catches, because each half looked fine to the person who
made it.

### Rollback follows the same table backwards

Rolling back an API-first release means rolling back the console first. Recorded here because the
order is the opposite of the one everybody has just rehearsed forward, and it is decided under
pressure.
