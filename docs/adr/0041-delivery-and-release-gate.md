# ADR 0041 — Delivery, CI and the release gate

* **Status:** accepted
* **Date:** 2026-08-18
* **Built in:** TAB 36 (final)
* **Related:** ADR 0038 (contract), ADR 0037 (health and recovery), ADR 0036 (queues), Article 6
  (expand → migrate → contract)

---

## Context

Thirty-five TABs built a system. This one asks whether it can be promoted safely, and answers
honestly.

---

## 1. Every CI step is required

There is no `continue-on-error` anywhere in the pipeline. **A step that can fail without failing
the build is a step nobody reads**, and this pipeline is the entire mechanism behind "no production
deploy occurs with a failing migration, test or security gate".

It runs on every push **and** every pull request. A gate that runs only on the default branch tells
you after the merge.

### Two steps that are not obvious

**Migrations run against PostgreSQL, not the SQLite the tests use.** Article 1 requires portable
migrations, and the only way to know they are portable is to run them on the engine production
uses. A migration that works on SQLite and not on Postgres fails on a deployment host at the worst
possible moment.

**Migrations are rolled back in CI.** Article 6 requires them to be reversible, and a `down()`
nobody runs is a `down()` that does not work — discovered during the incident where it is needed.

### The clean-clone job

A separate job, sharing no cache and no vendor directory.

**A populated `vendor/` masks a broken install.** The build is green because the packages were
already there, and the repository's actual ability to install is never exercised. This job is the
only one that answers *"can somebody clone this and run it"* — the question a new developer and a
deployment host both ask, and the one whose failure is discovered at the worst time.

It also boots the application and runs `route:list` and `schedule:list`, which resolve every
controller, every middleware alias and every scheduled entry. A container binding that only works
because of a cached config fails here rather than on a deploy.

### `composer audit` will fail on a morning nobody committed anything

Advisory data changes without the repository changing. That is correct and it is the point: a newly
disclosed vulnerability in a dependency is news whether or not it arrived with a commit.

---

## 2. Application code rolls back; schema does not

Everything in the deployment runbook follows from this.

A bad deploy is fixed by putting the previous release back — a minute, and nothing lost. Rolling
back a migration on a live database is a different act: `down()` on a populated table destroys the
data added since, and the data added since is the day's casework.

So **every migration must be safe for the currently deployed code to run against**, which is the
expand → migrate → contract rule. Collapsing it into one deploy is what turns a bad afternoon into
an outage: the migration lands, the code fails, and rolling back the code leaves it looking at a
column that no longer exists.

Migrations run **before** the new code, so the old code runs against the new schema for a few
seconds. That is the moment the rule pays for itself.

Caches are rebuilt **after** the code, never before — a cached route table from the previous
release points at controllers that may no longer exist. Workers restart **after** the code, or they
restart into the old release.

---

## 3. Zero-downtime is claimed only where it is true

With one node there is no zero-downtime deploy. There is a short window where the process restarts,
which is acceptable for a municipal office outside working hours and **should be acknowledged
rather than claimed away**.

With two nodes it is real, and only because nothing in this application holds local state:
sessions, cache, queues and files are all external, so any node can serve any request. That
property is what must stay true if a second node is added — it is not automatic, it is a
consequence of decisions made in earlier TABs.

---

## 4. The gate says NO-GO, and that is the finding

Every automated check is green. 889 tests pass. All thirty-six TABs are built.

**The system is not ready to go live, and none of the reasons are code.**

1. **Nobody can read the audit trail.** `audit.view` belongs to a Data Protection Officer role
   nobody holds — deliberate, because the auditee must not be the auditor, but the trail is being
   written *now* and the first time it is needed is during an incident.
2. **No retention schedule is approved**, so nothing is ever deleted. The safe direction, and not a
   steady state: indefinite accumulation is itself an exposure under RA 10173.
3. **The backup has never been restored.** A backup that has never been restored is a hypothesis.
4. **Capacity safety is asserted, not exercised** — the suite is single-process and SQLite compiles
   `lockForUpdate()` away.

The first three are an appointment, a review and an exercise: days of LGU time, not weeks of
engineering. The fourth is not a go-live blocker — it is a blocker on the first capacity-limited
event, which the LGU controls.

### Why say NO-GO at all

A release gate that reports GO because the tests pass is a gate that measures what is easy to
measure. Every one of those four is something that would be a serious failure in production and
that **no amount of further engineering closes**.

Writing GO here would transfer a decision the LGU has not made into a document that looks like they
had. The honest output of a release gate is sometimes a refusal, and a refusal with four named,
closeable items is more useful than an approval with an asterisk.

---

## Consequences

* CI takes several minutes, most of it the test suite and the Postgres migration run. Both earn it.
* A breaking API change now requires `/api/v2` and a deprecation window rather than a coordinated
  release — **a coordinated release is a coordinated outage waiting for the one client that is
  late**, and one of those clients is an app on somebody's phone.
* The release gate will need re-reading when the four blockers close. It is a document with a
  decision in it, not a checklist to tick.
