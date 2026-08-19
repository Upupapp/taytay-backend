# Backup and disaster recovery

Status: **a technical strategy awaiting LGU approval of its targets.**

**RPO and RTO are deliberately blank.** They are business decisions about how much welfare data
Taytay can afford to lose and how long the office can operate without this system, and inventing
numbers here would put words in the LGU's mouth about exactly that. Everything else — what is
backed up, how, where, and how a restore is proven — is decided and written below.

Reasoning: [ADR 0037](../adr/0037-observability-health-and-recovery.md).

---

## 1. The targets the LGU must set

| | Value | Who decides |
| --- | --- | --- |
| **RPO** — how much data may be lost | **to be agreed** | MSWDO + LGU management |
| **RTO** — how long recovery may take | **to be agreed** | MSWDO + LGU management |
| Backup retention | **to be agreed** | with the DPO, alongside the record retention schedule (ADR 0034 §5) |

Two things to know while deciding:

* **RPO is bounded by the backup mechanism.** Daily snapshots alone mean an RPO of up to 24 hours.
  Point-in-time recovery narrows it to the WAL shipping interval — usually minutes. If the answer
  to "how much can we lose" is less than a day, PITR is not optional.
* **RTO is dominated by the restore, not the backup.** A database that backs up in four minutes
  may take an hour to restore and verify. Only a rehearsal produces a real number, which is why
  §5 exists.

---

## 2. What must be recovered, and what cannot be rebuilt

| Asset | Store | If lost |
| --- | --- | --- |
| **PostgreSQL** | Akamai Managed PostgreSQL | Everything. Residents, cases, releases, the audit trail. **Not rebuildable from anywhere.** |
| **Private objects** | Akamai Object Storage (`object-storage`) | Every uploaded document — KYC IDs, certificates, proofs of residence. A resident can re-submit; a case's evidence of what was submitted *when* cannot be reconstructed |
| **Published media** | Akamai Object Storage (`public-media`) | Derived renditions only. **Rebuildable** by republishing, since the originals are private and survive (ADR 0033 §1) |
| Application code | git | Redeployable |
| Environment secrets | secret store / operator custody | See §6 — losing these is a rotation, not a restore |
| Redis | — | **Nothing durable.** Queue jobs in flight are lost; every job is idempotent or single-attempt (ADR 0036 §2), so a lost job is a missed notification, not a corrupted record |

**The asymmetry worth planning around:** the database holds the facts and the object store holds
the evidence. A restore that recovers one and not the other leaves cases whose documents are
missing, or documents belonging to cases that no longer exist. **They must be restored to a
consistent point**, which means the object-store recovery point must be *at or after* the database
one — an orphaned object is harmless, a missing one is not.

---

## 3. PostgreSQL

### Backups

* **Akamai Managed PostgreSQL automated backups**, at the plan's cadence.
* **Point-in-time recovery** where the plan and region provide it. Verify the actual capability
  before production — the master command is explicit that plan and region features must be checked
  rather than assumed.
* **An independent, off-site logical dump** (`pg_dump --format=custom`), encrypted, to storage in a
  different failure domain from the database.

The third is not redundant with the first. Provider automated backups protect against hardware and
operator error **inside** the provider; they do not protect against the account itself being lost,
suspended or compromised — and a ransomware actor with the provider console deletes the snapshots
along with the database.

### Encryption and keys

* Dumps are encrypted **before** leaving the host. A backup encrypted only at rest by the provider
  is readable by anybody who can read the provider.
* The backup encryption key is **not** stored beside the backups, and **not** in the same secret
  store as the application's runtime secrets. A compromise that yields the application environment
  must not also yield the backups.
* Key custody is dual-control: at least two named LGU officers can reach it, and no single person
  can both delete backups and decrypt them.

---

## 4. Object storage

* **Versioning enabled** on both buckets. Versioning is what turns an accidental or malicious
  delete into a recoverable event — without it, the object-store half of a ransomware incident is
  simply gone.
* **Lifecycle rules must not silently expire versions** faster than the agreed backup retention.
* **Separate credentials per bucket**, least privilege (ADR 0033 §4). The key that writes derived
  media cannot read a KYC document.
* **Off-site copy of the private bucket** on the same principle as §3: provider-internal redundancy
  is not independence.

---

## 5. Restore testing — the part that makes the rest real

**A backup that has never been restored is a hypothesis.** The master command asks for periodic
restore testing and it is the single most valuable item on this page: every real backup failure the
industry knows about was discovered during a restore, not during a backup.

### Cadence

| Exercise | How often | Proves |
| --- | --- | --- |
| Restore latest backup to staging | **monthly** | The backup is readable and the schema loads |
| PITR to an arbitrary past timestamp | **quarterly** | Point-in-time actually works, not just snapshots |
| Full DR rehearsal — database *and* objects, to a clean host | **twice yearly** | The RTO number is real |
| Restore from the **off-site encrypted** copy | **twice yearly** | The key is reachable and correct |

### Procedure

1. Provision a clean staging database. **Never restore over a live one** — a restore that fails
   halfway has then destroyed the thing it was insuring.
2. Restore the database. **Record the wall-clock time.**
3. Restore the object store to a point **at or after** the database recovery point (§2).
4. Run `php artisan migrate --pretend` — it must report nothing pending. Anything pending means the
   backup predates a migration and the restore is not the shape the code expects.
5. Run `php artisan lguids:readiness`. Every dependency must answer.
6. Verify against **live counts**, not just "it started": resident count, case count, audit entry
   count, and the most recent `occurred_at` in `audit_entries` — the last is the real RPO
   measurement, because it is the timestamp of the last thing the system knows happened.
7. Spot-check a document: open one through the authorization-gated endpoint and confirm the bytes
   are there. A database restore with an unrestored object store passes every other check on this
   list.
8. **Record the observed RTO and RPO.** Not the target — the observed. If they differ from the
   agreed targets, that is the finding, and it belongs in front of whoever set the targets.

### Recording

Each exercise produces: date, who ran it, backup timestamp used, observed RTO, observed RPO, and
anything that did not work. Kept where the LGU keeps its operational records, not only in this
repository.

---

## 6. Secrets are rotated, not restored

Losing an environment secret is not a recovery scenario — the secret is regenerated and every
consumer updated. What matters is knowing **what exists**:

| Secret | Consumer | Rotation |
| --- | --- | --- |
| `APP_KEY` | session/cookie encryption | **Rotating invalidates encrypted payloads.** Plan before touching |
| Database credentials | application, workers | Rotate in the provider, then redeploy |
| `OBJECT_STORAGE_*` | private uploads | Least-privilege key, private bucket only |
| `PUBLIC_MEDIA_*` | derived media | Separate key, public bucket only |
| FCM service account | push | Rotate in Firebase; never on Netlify, never in git (Article 8.3) |
| Redis credentials | queue, cache, locks | Rotate, then restart workers |

**None of these may ever be configured on Netlify** — build variables there are public (Article 8.2).

---

## 7. What this repository does not decide

* The retention period for backups — with the DPO, alongside ADR 0034 §5.
* Whether Linode compute Backups are enabled. They are same-datacenter snapshots with limited
  retained points and are **not** a DR plan on their own; the master command says so explicitly.
* Which off-site provider holds the independent copy.
* Who is on call, and how they are reached.

---

## Schema changes after go-live (TAB 18)

The expand-migrate-contract pattern is **rehearsed**, not just described:
`tests/Feature/Database/ExpandMigrateContractRehearsalTest.php` runs all four steps against a
populated table and asserts at every step that the *previously deployed* code still works and that
no value is lost.

It also demonstrates the failure the pattern exists to prevent — a one-line `renameColumn`, shown
breaking the release that is still serving traffic. That version passes every other test in this
repository, which is why it needs a test of its own.

### The step every description leaves out

A row written **during** the backfill, by the still-running old code, has the old column set and the
new one null. A row count would not notice: the count is right and one value is missing. So the
release that backfills must also dual-write, and the cut-over waits until the null count is zero.

### Migration reversibility

`tests/Architecture/MigrationSafetyTest.php` migrates up, rolls all 39 back, and migrates up again,
asserting the schema is identical. The second half matters more: a `down()` that leaves an index or
a sequence behind rolls back "successfully" and fails on the way up — mid-incident, with the
rollback already committed.

**It runs on SQLite, and that is materially weaker than the PostgreSQL run TAB 18 asks for.** The
weakness is specific: Laravel's SQLite grammar rebuilds a table to drop a column, and SQLite does
not enforce a foreign key against a table being dropped — so the two rollback failures that actually
happen in production are exactly the two it cannot report. Four static rules carry that weight
instead, and running the real thing against real PostgreSQL stays on the master TODO.
