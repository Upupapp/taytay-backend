# Backend release gate — GO / NO-GO

Status: **NO-GO.**

Not because anything is broken. Every automated gate is green, all thirty-six TABs are built, and
889 tests pass. The blockers below are **decisions and exercises the LGU has not yet made or
performed**, and none of them can be closed from inside this repository.

This document is the honest statement of that. Reasoning: [ADR 0041](adr/0041-delivery-and-release-gate.md).

---

## The four blockers

Each would be a serious failure if this system went live without it.

### 1. Nobody can read the audit trail

`audit.view` is held only by `Role::DataProtectionOfficer`, and **nobody is assigned that role.**
Deliberate — the trail records the MSWDO head's own approvals, document reads and exports, and
giving it to them would be the auditee reading their own audit (ADR 0034 §7).

But the trail is being written *now*. Until somebody is appointed, every entry is unreadable — and
the first time it is needed is during an incident, which is the worst moment to discover it.

**To close:** the LGU appoints a Data Protection Officer and assigns the role.
**Gap:** G-46.

### 2. No retention schedule is approved, so nothing is ever deleted

`RetentionPolicy::mayPurge()` refuses everything while `PRIVACY_RETENTION_APPROVED` is false —
tested against a record twenty years past any plausible schedule (ADR 0034 §5).

That is the safe direction. It is not a steady state: data accumulates indefinitely, which is
itself a privacy exposure under RA 10173, and the categories in `config/privacy.php` are
placeholders nobody has reviewed.

**To close:** the DPO reviews §2 and §3 of the [personal data inventory](privacy/personal-data-inventory.md),
approves or corrects the legal bases and the retention periods, and records who approved them and
when.
**Gaps:** G-47, G-48.

### 3. The backup has never been restored

The strategy, the encryption and key-custody rules, and the restore procedure are all written. **No
restore has been performed.**

A backup that has never been restored is a hypothesis. Every real backup failure the industry knows
about was discovered during a restore.

RPO and RTO are also unset, deliberately — they are business decisions about how much welfare data
Taytay can afford to lose, and a number invented here would be quoted back as though somebody had
decided it.

**To close:** LGU management sets RPO and RTO; deployment runs the exercise in
[backup-and-disaster-recovery.md §5](runbooks/backup-and-disaster-recovery.md) and records the
**observed** figures.
**Gap:** G-52.

### 4. Capacity safety is asserted, not exercised

Event registration cannot exceed capacity because every seat decision happens behind
`SELECT … FOR UPDATE` on the event row. The test suite **cannot prove this**: it is single-process,
and SQLite compiles `lockForUpdate()` to an empty string.

What is proven is the arithmetic and the presence of the lock. What is not proven is that two
concurrent requests produce exactly one winner for the last seat.

**To close:** run two parallel registrations against a real PostgreSQL and confirm one `201` and
one `409`. Needed **before the first capacity-limited event**, not before go-live generally.
**Gap:** G-40.

---

## What is GO

Everything below is built, tested, and enforced by a check that fails the build.

### Automated gates

| Gate | Mechanism |
| --- | --- |
| Formatting | `vendor/bin/pint --test` |
| Test suite | **889 tests** |
| Migrations on a clean **PostgreSQL** | CI runs them against Postgres 18, not the SQLite the tests use |
| Migrations roll back | `migrate:rollback` in CI — a `down()` nobody runs is a `down()` that does not work |
| Published contract is current | `lguids:openapi --check`, `lguids:types --check` |
| Dependency advisories | `composer audit` |
| **Installs from a clean clone** | A separate CI job with no cache — a populated `vendor/` masks a broken install |

### Acceptance criteria from the master command

| | Criterion | Evidence |
| --- | --- | --- |
| ✅ | Admin workflows run against real APIs | 246 documented endpoints; `ContractMatrixTest` checks the matrix against the router **in both directions** |
| ✅ | Citizen web and mobile have documented contracts | [`openapi.json`](api/openapi.json) (221 paths, 53 schemas), [`types.ts`](api/types.ts), [citizen route map](api/citizen-route-map.md) |
| ✅ | Auth, RBAC, scope and object authorization | `AuthorizationMatrixTest`, `ApiSecurityTest`, `CitizenLeakScanTest` — written as **attacks**, not descriptions |
| ✅ | Social welfare workflows persist | Journey 2 carries a case from citizen draft to released money through two actors |
| ✅ | Newsfeed publish / engagement / moderation | Journey 5, including that a moderated comment is **absent rather than marked** |
| ✅ | Event registration is race-safe | Row lock on the event; capacity counted from committed rows, never a counter — **but see blocker 4** |
| ✅ | Notifications queue correctly | `afterCommit()` enforced by scan; named queues per workload class |
| ✅ | Exports and files are private, permission-aware | Two buckets, one writer, no `url` on the private disk, 24h person-level retention |
| ✅ | Audit, security and privacy controls present | One writer, append-only enforced, redaction at the processor, legal holds |
| ⚠️ | Restore procedure tested | Documented; **never executed** — blocker 3 |

---

## Unresolved risks

Ordered by what they would cost if they went wrong.

| Risk | Consequence | Owner |
| --- | --- | --- |
| **Single API node** (if deployed that way) | Total outage on one host failure. The master command permits it for an initial deployment **if documented as a conscious trade-off** — this is that documentation | LGU budget |
| **A queue with no worker** | Silent. Nothing errors; the only symptom is a resident who never got a message. Workers must consume **all six** queue names | Deployment |
| **Six protection permissions parked on `lgu_admin`** | `vulnerability.view-protected`, `document.view-sensitive`, `case-note.view-protected`, `safeguarding.view/manage`, `referral.disclose-protected` sit with the MSWDO head because no protection-officer role exists. Reading a survivor's safety plan is not an administrative convenience (**G-30**) | LGU |
| **No alerting** | The metrics endpoint exposes queue depth, failed jobs and auth anomalies. Nothing polls them (**G-51**) | Deployment |
| **`document.share` held by nobody** | The outward-sharing path is built and refused. The first holder should be a decision on the record (**G-32**) | LGU |
| **Image derivation is inline** | A post with ten images is slow to publish (**G-45**) | Backend, when volume is known |
| **No App Check** | Accepted: fails-open is decoration, fails-closed needs a rollout plan (**G-50**) | LGU |
| **Models are `$guarded = ['id']`** | Mass assignment is prevented by controller allow-lists, which is tested but is not defence in depth (**G-49**) | Backend |

---

## Before the first production deploy

Configuration that is wrong here fails in ways the test suite cannot see.

1. **`CORS_ALLOWED_ORIGINS`** — the exact production custom domains. Never `*`, never a
   `*.netlify.app` pattern: anybody can create a site on that domain.
2. **`TRUSTED_PROXIES`** — the NodeBalancer or private CIDR. Without it every caller shares one
   rate-limit key and every audit entry is attributed to the load balancer.
3. **`HSTS_ENABLED` stays off** until the custom domains and certificates are confirmed. It cannot
   be undone from the server.
4. **nginx `client_max_body_size` above 10 MiB.** If nginx rejects a body first it answers `413`
   without CORS headers, and the browser sees a network failure with status 0.
5. **Separate object-storage keys** — `OBJECT_STORAGE_*` (private) and `PUBLIC_MEDIA_*` (public
   renditions). Least privilege; neither key reads the other's bucket.
6. **`AUDIT_CAPTURE_NETWORK`** — a DPO decision. Off by default.
7. **Workers on all six queues**; scheduler cron on **one** host with a shared cache store.
8. **No Laravel or Firebase secret on Netlify.** Build variables there are public.

---

## The recommendation

**NO-GO until blockers 1–3 are closed.** They are an appointment, a review and an exercise —
days of LGU time, not weeks of engineering.

Blocker 4 is **not** a go-live blocker: it must be closed before the first capacity-limited event,
which the LGU controls.

The system is finished. What is missing is the part only Taytay can supply: somebody accountable
for the audit trail, an approved retention schedule, and proof that the backups restore.
