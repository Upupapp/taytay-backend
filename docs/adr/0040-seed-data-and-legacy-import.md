# ADR 0040 — Seed data, master data and legacy import

* **Status:** accepted
* **Date:** 2026-08-18
* **Built in:** TAB 35
* **Related:** ADR 0014 (effective-dated membership), ADR 0008 §13 (JSON allow-list), Article 5.6

---

## Context

The five barangays and the role/permission catalogue were already seeded. What did not exist was a
coherent demonstration dataset, and any pathway for the legacy data Taytay may eventually supply.

Both are about the same risk from opposite directions: **inventing people who look real**, and
**importing real people badly**.

---

## 1. The demo seeder refuses to run outside three environments

A demo seeder is a program whose entire purpose is to insert invented people into a resident
registry. The one environment where that is catastrophic is also the one where it would be hardest
to unpick: a caseworker cannot tell an invented resident from a real one by looking, and an
invented one who gets assistance approved is a real disbursement to nobody.

The guard is an **allow-list** (`local`, `testing`, `demo`), not `if (! production)`. An
environment name nobody anticipated — `staging-2`, `uat`, an empty `APP_ENV` — is refused rather
than treated as safe. Deny by default, for the same reason it applies to authorization: a
deny-list fails silently and totally.

---

## 2. Fictional is a property, not an intention

The master command says never to use real names, phones, emails or IDs. Picking unfamiliar names is
not enough, because the failure is not that a name collides — it is that a *contact detail*
reaches somebody.

* **Emails end `@example.test`** — a reserved TLD (RFC 6761) that can never resolve. A plausible
  `@gmail.com` address in a demo dataset absolutely can, the first time somebody points a staging
  environment at a real mail provider, and the person who receives the notification is a stranger
  being told about somebody else's welfare case.
* **Mobile numbers come from a fixed reserved block**, not a random generator. A random number can
  be one somebody actually holds, and "unlikely" is not "impossible".
* **No government identifier is seeded at all.** Not even an invented one — a plausible-looking
  PhilSys number in a database is a plausible-looking PhilSys number, and somebody will eventually
  paste one into a form that checks it, a support ticket, or a screenshot.

`SeedAndImportTest` asserts all three against what actually landed in the tables, rather than
against the seeder's source.

---

## 3. Coherence is built, not asserted

Records are created **through the application services**, in dependency order. A seeder that wrote
rows directly would produce data that looks right and violates an invariant the API enforces —
which is worse than no demo data, because it teaches a developer that an impossible state is
possible.

### Two bugs this produced, both worth keeping in mind

**`WithoutModelEvents` broke the UUIDs.** It is standard seeder hygiene and it suppresses the
`creating` hook every model here uses to mint its public identifier. The first run failed on a NOT
NULL constraint; had the column been nullable it would have produced a registry of records the API
cannot address. A seeder that goes through the services needs the services to behave as they do in
production — that is the entire reason for going through them.

**`callOnce` remembered across a database reset.** `RefreshDatabase` wipes tables between tests
while `callOnce` remembers within the process, so the second test in a class got a seeder that
"already ran" and an empty `barangays` table. Every household was silently skipped and the failure
read as a broken seeder rather than a stale memo. `BarangaySeeder` is idempotent by construction,
so `call()` costs five upserts and removes the trap.

---

## 4. The import framework has no mappings, deliberately

The master command: build the framework, and **do not write one-off migration code against
imaginary legacy columns.**

Taytay has not supplied an export. Any mapping written now would encode a guess about column names,
date formats and encodings — and a guess in a mapping is worse than no mapping, because it looks
like knowledge. So `RowMapper` is an interface with **no implementation** outside the test suite,
and a staged row holds its source columns as an opaque payload.

When a real file arrives, a mapper is one small class. The staging tables, the dry run, the
rejection report and the commit do not change.

### The dry run is not a mode

`validate()` always runs and always writes nothing to the registry. `commit()` is a separate call
that refuses a batch which has not been validated, and refuses one with any rejection. **There is
no flag to forget and no argument to get wrong.**

That shape exists because of the failure it replaces: a script that reads a file and writes
residents works on the sample and fails on row 4,812 of the real one, having already written 4,811
residents nobody can now distinguish from the ones that were there before. Separating reading from
committing means a bad file is rejected while it is still a file.

### Rejections and duplicates are different answers

A **rejection** is bad data to fix. A **duplicate** is a record the system already has, and the
right response is usually to skip it. Conflating them makes a re-run report thousands of "errors"
that are nothing of the kind, and the operator stops reading the report.

Duplicates are detected against both what is already imported **and** what has been seen earlier in
the same file — an export joined across two tables is the usual cause, and a check that only looked
at prior imports would let the second copy through on the first run.

`validate()` returns **every** reason a row failed, not the first. Fixing one problem per pass
through a four-thousand-row file is three round trips.

### A batch with any rejection cannot be committed

Stricter than it needs to be, deliberately. Importing the good rows and leaving the bad ones
produces a partial registry that looks complete — and the missing people are the ones whose data
was messiest, **which correlates with the households that need the office most.**

### Chunked, and the rollback plan reports rather than acts

One transaction per chunk: a single transaction over forty thousand rows holds locks for minutes
and loses an hour of work on failure, while one per row leaves a half-imported registry when the
process dies.

Every committed row records what it became, so an import is undone by walking the batch rather than
by guessing which records arrived when. `rollbackPlan()` **produces the list and does not act** —
by the time somebody wants an import undone, a caseworker may already have edited one of the
records, and an automatic reversal would silently discard real work.

### Provenance is kept verbatim

The source row is stored exactly as it arrived, **including the columns nobody mapped**. *"How did
this record get here"* is asked when the record turns out to be wrong, and the answer has to
include what the file actually said — not the subset somebody thought was interesting at the time.

On the ADR 0008 §13 JSON allow-list for the same reason `idempotency_keys` holds a response body:
it is evidence, never filtered, joined or authorized on.

---

## Consequences

* The demo dataset is five households across five barangays. Small, and coherent — a larger one
  would need generated names, which is exactly where "checkably fictional" stops being checkable.
* An import cannot happen until somebody writes a mapper for a file that exists. That is the
  intended friction.
* `isCommittable()` requires `valid > 0`, not `total > 0`: a batch of only duplicates has nothing
  to import, and reporting it as committable would send an operator looking for records that were
  never going to appear.
