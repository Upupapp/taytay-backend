# ADR 0037 — Observability, health and recovery

* **Status:** accepted
* **Date:** 2026-08-17
* **Built in:** TAB 32
* **Related:** ADR 0003 (error envelope), ADR 0034 (audit and legal holds), ADR 0036 (queues),
  Article 5.5 (never log identifiers, secrets or addresses)

---

## Context

Thirty-one TABs built a system that works. This one is about finding out when it does not — and
about the fact that a log is the least-guarded copy of anything it contains.

The survey found: one public liveness endpoint, a readiness *command*, no structured logging, no
redaction, no metrics, and no backup or incident documentation at all.

---

## 1. Structured logs carry correlation, not identity

Every record gets `environment`, `service`, `request_id`, `method`, the **route pattern**, the
client channel and the actor's subject UUID.

**The route pattern, not the URL.** `admin/residents/{resident}` groups a thousand requests
together; the resolved URL puts a resident identifier on every line.

**The actor is a UUID and nothing else** — enough to correlate, not enough to identify without a
second authorized lookup. The same trade `OutboundNotification::routingPayload()` makes for push
(Article 8.4), for the same reason: this string travels somewhere less guarded than the record it
describes.

`request_id` is the same string in three places — the response header a citizen can quote, the
audit entry, and every log line of that request. That is what makes a support call tractable.

---

## 2. Redaction happens at the processor, never at the call site

**This is the decision the TAB turns on.**

A rule applied where somebody remembers it holds until the afternoon somebody writes
`Log::error('Upload failed', ['request' => $request->all()])` while chasing a bug. That line looks
entirely reasonable, and it puts a resident's PhilSys number, password and bearer token into a file
that is read by whoever is debugging, shipped to whatever aggregator the LGU eventually buys, kept
longer than the record it describes, and pasted into a support ticket by somebody trying to help.

So `RedactSensitiveData` is a Monolog processor on every channel, and it makes **two passes**
because either alone misses half:

* **by key name** — catches a field whose value looks innocuous, and catches it when the value is
  null, because the presence of the field is itself the finding;
* **by value shape** — catches the same data under a name nobody predicted. `['payload' =>
  '1234-5678-9012']`, a bearer token in a header dump, a driver quoting the SQL it failed on.

The second is the one that matters, because **the dangerous log line is never the one somebody
designed.**

Key matching is by substring, so `philsys_number`, `philsysNumber` and `resident_philsys` all match
one entry. That over-redacts occasionally and the direction is right: an over-redacted log costs
somebody a second look at the database; an under-redacted one cannot be un-written.

A first name is **not** redacted. A redactor that removed everything would be turned off within a
week by whoever needed to read a log — the list is what Article 5.5 names.

### The ordering, which fails silently

Monolog invokes processors last-pushed-first, so redaction must be pushed **first** to run **last**
— over everything the context processor added. The intuitive order does the opposite, and nothing
about the resulting log line looks wrong: the redacted fields are still redacted, because they were
in the original context. The failure would surface only the day the context processor started
carrying something worth hiding.

`ObservabilityTest::redaction_runs_after_the_context_processor` asserts the composition rather than
trusting the comment.

---

## 3. Two health endpoints, and the split is the point

`GET /api/v1/health` is public and says only that this process is alive.
`GET /api/v1/admin/operations/{readiness,metrics}` costs `operations.view`.

**Publishing "postgres: down" to the internet is free reconnaissance, and publishing "postgres: ok"
tells an attacker which dependencies exist to attack.** A load balancer needs the first; a human
diagnosing an outage needs the second.

The readiness endpoint reports **state, never configuration**. No host, no port, no bucket, no
connection string — one that answered "postgres at 10.0.0.4:5432 is ok" would be a network map
behind one permission. The driver *name* is included, because "the queue is on `sync`" is the actual
finding when a production deployment is silently running jobs inline.

A failed probe returns `failed` and **not the exception**. A driver exception carries a host, a
port, a database name and sometimes a credential.

The `lguids:readiness` command stays: a deploy script and a developer after `docker compose up`
both have a shell and neither has a token.

### Metrics are counted, never accumulated

Every number is counted live rather than read from a counter somebody maintains — the same
reasoning as the event seat count (ADR 0031 §1). **A metrics endpoint whose numbers drift from
reality is worse than none, because it is believed.**

Queue depth is reported **per named queue**, because the aggregate hides the finding: a total of
400 is unremarkable if it is all `exports`, and means nobody has been told anything for an hour if
it is all `notifications`.

Auth anomalies come from the audit trail rather than a separate counter — the trail is already the
record of these, and a second source would drift.

Nothing here names a resident. The person on call is not a caseworker.

### A new role, for the same reason as the last two

`Role::OperationsEngineer` holds `operations.view` and nothing else. The person watching queue
depth at 2am is not the MSWDO head, and making them hold the head's permissions to do it would be
handing out casework authority as a side effect of an on-call rota. Same shape as
`DisbursingOfficer` (ADR 0023 §3) and `DataProtectionOfficer` (ADR 0034 §7).

---

## 4. Backup, recovery and incidents are documented, and the targets are not invented

Two runbooks: [backup and disaster recovery](../runbooks/backup-and-disaster-recovery.md) and
[incident response](../runbooks/incident-response.md).

**RPO and RTO are left blank.** The master command says not to invent them, and the reason is
sound: they are business decisions about how much welfare data Taytay can afford to lose and how
long the office can operate without this system. A number written here would be quoted back as
though somebody had decided it.

Three technical positions the runbooks do take:

* **Provider backups are not independence.** Automated backups protect against hardware and
  operator error *inside* the provider; a ransomware actor with the provider console deletes the
  snapshots along with the database. An independent, encrypted, off-site dump is a separate
  control.
* **The database and the object store must be restored to a consistent point**, with the object
  store at or after the database. The database holds the facts and the object store holds the
  evidence; restoring one without the other leaves cases whose documents are missing. An orphaned
  object is harmless — a missing one is not.
* **A backup that has never been restored is a hypothesis.** Monthly restore to staging, quarterly
  PITR, twice-yearly full rehearsal including the off-site copy. Each records the **observed** RTO
  and RPO, not the target — and a divergence is a finding for whoever set the target.

The incident runbook opens with **preserve evidence before you fix**, because the instinct under
pressure is to rotate the key and restart the box, and that destroys the record an NPC notification
needs. Placing a legal hold (ADR 0034 §6) is the first step, not the last.

---

## Consequences

* Every log line is slightly larger and passes through two processors. That is the cost of not
  having to trust every future `Log::` call.
* An operator needs a token and the `operations_engineer` role to see readiness. During an incident
  where authentication itself is broken, the shell command is the fallback — which is why it stays.
* The LGU must set RPO and RTO before go-live, and must run the first restore exercise. Neither can
  be done from here.
