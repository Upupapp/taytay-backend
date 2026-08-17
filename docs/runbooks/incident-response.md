# Incident response — technical runbook

Status: **the technical hooks.** Who declares an incident, who notifies the National Privacy
Commission, and who speaks to residents are LGU decisions that belong in the LGU's own incident
policy — not here.

What is here is what an engineer does, in what order, and which of those actions are irreversible.

Reasoning: [ADR 0037 §4](../adr/0037-observability-health-and-recovery.md).

---

## Before anything else

**Preserve evidence before you fix.** The instinct under pressure is to rotate the key, restart the
box and move on — and that destroys the record of what happened, which is what a breach
investigation and an NPC notification both need.

1. **Place a legal hold** covering the affected subjects or records:
   `POST /api/v1/admin/privacy/legal-holds`. This is the one action that stops retention from
   destroying evidence while the investigation runs (ADR 0034 §6), and it is cheap to place and
   cheap to lift.
2. **Snapshot the logs and the audit trail** for the window before touching anything.
3. **Note the time**, in UTC, that you first became aware. RA 10173 notification timelines run from
   awareness, not from confirmation.

---

## A. Credential compromise

*A token, database password, object-storage key or service account is believed exposed.*

### Contain

1. **Revoke first, investigate second.** For a compromised user token:
   `POST /api/v1/me/sessions/revoke-all` as that account, or revoke server-side.
2. For an **infrastructure** credential, rotate at the provider — the credential is more dangerous
   than the downtime rotating it causes.
3. If a **staff account** is implicated, deactivate it (`DELETE /api/v1/staff/{staff}`) rather than
   changing its password. A password change leaves existing tokens live.

### Assess

* `GET /api/v1/admin/audit-entries?actor_subject_id=…` — everything that account did.
* `GET /api/v1/admin/audit-entries?risk=high&from=…` — whether anything consequential happened in
  the window.
* `GET /api/v1/admin/operations/metrics` — `auth.sign_in_failures_last_hour` and
  `accounts_locked_last_hour`, which is what a credential-stuffing run looks like.

### Recover

* Rotate the credential and every derived one (§6 of the DR runbook).
* If `APP_KEY` is implicated: **plan before rotating.** It decrypts existing payloads, and rotating
  it invalidates them.
* Restart workers so they pick up the new environment: `php artisan queue:restart`.

---

## B. Suspected data breach

*Personal data may have been accessed or disclosed without authority.*

### Establish scope — before remediating

The question the NPC will ask is **whose data, and what about them**. The audit trail is the
instrument:

| Question | Query |
| --- | --- |
| What did this actor read? | `?actor_subject_id=…` |
| Who touched this record? | `/admin/audit-entries/for-entity?entity_type=…&entity_id=…` |
| Was anything exported? | `?action=report.person-level-export-requested` |
| Were documents opened? | `?action=document.opened` |
| Was the trail itself read? | `?action=audit.searched` |

**An export is the worst case and the easiest to miss.** A copy of a caseload leaves this
application's control entirely (ADR 0026 §3); check whether the produced file has already been
purged by the hourly sweep, because that changes what is still recoverable and what is merely
recorded.

### Contain

* Revoke the actor's access. Do **not** delete the account — deleting the actor destroys the link
  between them and the trail.
* Place the legal hold if you have not already.
* If an object-store URL leaked: the private bucket has no public URL by construction, so the
  leaked thing is a short-lived grant. Confirm expiry rather than assuming it.

### Notify

Timelines and recipients are the LGU's policy and the DPO's decision. What engineering supplies is
the factual record: affected subjects, data categories, time window, and how it was established.
The [personal data inventory](../privacy/personal-data-inventory.md) maps categories to modules.

---

## C. Ransomware or destructive outage

### Contain

1. **Isolate.** Take the application off the load balancer before anything else — a compromised
   node still serving traffic is still writing.
2. **Do not delete anything**, including apparently corrupted data. It is evidence, and it may be
   the only copy of the corruption pattern.
3. **Check backup integrity before restoring.** A backup taken after the compromise contains the
   compromise. Identify the last known-good point using the audit trail's `occurred_at` and the
   first anomalous entry.

### Recover

Follow the restore procedure in the [DR runbook §5](backup-and-disaster-recovery.md), with one
addition: **restore to a clean host, never over the compromised one.**

Recover the database and the object store to a consistent point — the object-store recovery point
at or after the database one, so nothing references an object that does not exist.

### Afterwards

Rotate **every** credential, whether or not it is known to be compromised. The cost of rotating a
key that was safe is an afternoon; the cost of not rotating one that was not is the whole incident
again.

---

## D. Token and key rotation (routine, not an incident)

Rotation should be boring. It is on this page so that the first rehearsal is not during A, B or C.

| Key | Steps | Watch for |
| --- | --- | --- |
| Database password | Rotate at provider → redeploy → `queue:restart` | Workers holding old connections |
| `OBJECT_STORAGE_*` | New key → deploy → verify a document opens → revoke old | A revoked key with a running worker mid-upload |
| `PUBLIC_MEDIA_*` | Same, independently — it is a separate bucket and key | — |
| FCM service account | Rotate in Firebase → deploy | Push fails **silently**; watch `notifications.failed_last_hour` |
| Credential signing material | **Needs its own plan.** Rotating invalidates issued QR credentials | Every card in circulation |
| `APP_KEY` | **Plan first.** Invalidates encrypted payloads | — |

After any rotation: `GET /api/v1/admin/operations/readiness` must report `ready`, and
`metrics.jobs.failed_last_hour` must not climb.

---

## What to look at, in order

1. `GET /api/v1/health` — is the process alive at all?
2. `GET /api/v1/admin/operations/readiness` — which dependency is down?
3. `GET /api/v1/admin/operations/metrics` — queue depth per queue, failed jobs, auth anomalies.
4. `php artisan queue:failed` — what actually broke.
5. The audit trail — what was done, and by whom.

### The failure that looks like nothing

**A queue with depth and no worker.** Nothing errors, nothing alerts, and the only symptom is a
resident who never got a message. `metrics.queues.notifications` climbing while
`jobs.failed_total` stays flat is the signature — work arriving, nothing consuming it.

---

## Hooks this system provides

| Need | Mechanism |
| --- | --- |
| Freeze evidence | Legal holds (ADR 0034 §6) — outrank retention, one direction only |
| Reconstruct actions | Audit trail, append-only, searchable by actor, entity, action, risk and request id |
| Correlate a citizen's report to a log line | `X-Request-Id`, identical in the response header, the audit entry and every log line of that request |
| Revoke access | Per-token, per-session and per-account revocation |
| Confirm dependencies | `lguids:readiness` (shell) and `/admin/operations/readiness` (HTTP) |
| Stop the bleeding without a deploy | Rate limits and feature flags in config |

## Hooks it does not

Alerting, paging and on-call rotation are deployment concerns. This system **exposes** the numbers
worth alerting on; nothing here decides who is woken up (**G-51**).
