# ADR 0008 — PostgreSQL-first relational conventions

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 04)
* Relates to: CLAUDE.md Articles 5 and 6, ADR 0001 (module boundaries),
  ADR 0004 (managed PostgreSQL), ADR 0007 (lifecycle as an explicit state machine)

## Context

Nine modules will add tables to one database over many TABs. Schema conventions decided
once, before the first domain table exists, cost a page of prose; decided per module they
cost a migration per module for the rest of the project, and the seams will not line up.

This system also holds Philippine personal data under RA 10173, including two categories
whose *mere existence* is sensitive (RA 9262 VAWC, RA 9344 CICL). Some of what follows is
therefore a legal obligation expressed as a schema rule, not a preference.

PostgreSQL is canonical (ADR 0004); SQLite backs the test suite. Every convention below
must work on both through Laravel's schema builder, because
`tests/Architecture/InfrastructureAlignmentTest.php` forbids vendor-specific raw SQL in
migrations and that portability is what keeps the managed service a deployment choice.

## Decision

### 1. Identifiers — internal `bigint`, external UUIDv7

Every table gets `$table->id()` (bigint, auto-increment) as its primary key, **and** a
`uuid` column, unique, that is the only identifier ever exposed to a client.

* Joins and foreign keys use the bigint. It is narrow, sequential, and keeps indexes small.
* APIs use the UUID. Sequential integers leak volume and ordering and let a caller walk the
  table by incrementing — the enumeration risk conventions §6 already forbids.
* UUIDs are **version 7** (`Str::uuid7()`), which embeds a timestamp prefix so values are
  time-ordered. Random v4 keys scatter B-tree inserts across the index and fragment it;
  v7 keeps inserts at the right-hand edge. Generated application-side: PostgreSQL 16/17
  has no built-in `uuidv7()` (it arrives in 18), and generating in PHP keeps the value
  known before the insert returns.

Foreign keys are named `<singular>_id` and reference the bigint.

### 2. Time — `timestamptz`, always UTC

All datetime columns are `timestampTz` / `timestampsTz`. Storage and comparison are UTC;
the API emits ISO-8601 UTC (conventions §6); Asia/Manila is a rendering concern.

`timestamp without time zone` is banned. A naive timestamp is a bug waiting for the first
DST-adjacent report or the first server in another region, and the column type will not
tell you which zone the value was in.

Date-only facts (a birth date, an effective date) use `date`. A birth date is not an
instant and must not shift when rendered in another zone.

### 3. Deletion — soft, immutable, or not at all

Three categories, chosen deliberately per table:

| Category | Mechanism | Used for |
| --- | --- | --- |
| **Never deleted** | no delete path at all | audit entries, lifecycle transitions, disbursement records |
| **Soft deleted** | `deleted_at` (`softDeletesTz`) | records that must stay referenceable after withdrawal — residents, staff accounts, programmes |
| **Hard deleted** | ordinary `DELETE` | derived rows with no evidentiary value — cache, idempotency keys past expiry, notification read-state |

**A citizen record is never hard-deleted.** Welfare case retention is statutory, and a
deleted resident row orphans the assistance history that proves what was paid to whom.
Deactivation is a state, not a deletion (`is_active` plus `deleted_at`), and the residents
contract already models it that way.

Soft-deleted rows stay inside unique constraints, which is intentional: a deactivated
resident must not free their identity for reuse.

### 4. Foreign keys and delete behaviour

* **Foreign keys only within a module.** Cross-module references are a bare identifier
  column with **no** FK constraint (CLAUDE.md Article 2.2). This is not laziness — a
  cross-module FK is a schema-level coupling that makes the boundary unenforceable and the
  extraction in ADR 0001 impossible. The column is named `<module>_subject_id` or similar
  to make the absence of a constraint obviously deliberate, and referential correctness is
  the owning module's application service's job.
* Default delete behaviour is **`restrict`**. Deleting a parent that still has children is
  a bug; the database should say so rather than silently widening the blast radius.
* `cascade` only where the child has no independent existence (a requirement row belongs to
  its request and means nothing without it).
* **`set null` is banned on any column that carries meaning.** Nulling `approved_by` when a
  staff account is removed silently rewrites history to say nobody approved it.

### 5. Constraints

* **Unique on every natural key** — a code, a reference number, a UUID.
* **Unique on every relationship pair.** A join table gets a unique index on the pair of
  identifiers it relates. Without it, "assign role" run twice produces two rows and every
  downstream count is wrong. This is the single most common integrity defect in systems
  like this one.
* **Beware NULL in a unique key.** PostgreSQL treats NULLs as distinct, so
  `unique(subject_id, role, barangay_id)` with a null barangay permits unlimited
  duplicates. PostgreSQL 15+ offers `UNIQUE NULLS NOT DISTINCT`, but it is not reachable
  through Laravel's portable schema builder. **Design the nullable column out of the key
  instead** — as `role_assignments` does, keying on `(subject_id, role)` and holding scope
  as attributes.
* **Check constraints** via `$table->enum()` — Laravel's PostgreSQL grammar compiles it to
  `varchar` + `check (col in (…))`, and SQLite similarly, so it stays portable. Use it only
  for **closed** sets that change rarely (a scope type, a classification). Widening an
  `enum` is a column rewrite.
* **Open-ended vocabularies use `string` plus a PHP backed enum.** Role names and status
  values will grow; a check constraint would turn each addition into a table rewrite under
  lock. The application enum is the source of truth, validated at the boundary and asserted
  by tests. Stated plainly: the database does not constrain these values, and that is a
  deliberate trade of one integrity guarantee for safe evolution.
* **Exclusion constraints** (`EXCLUDE USING gist`) would be the correct tool for
  non-overlapping effective-dated rows. They require vendor SQL, so overlap is prevented in
  the application service and asserted by tests until a table needs it badly enough to earn
  an ADR of its own.

### 6. Indexes

* Every foreign key is indexed. PostgreSQL does **not** create one automatically, and the
  omission only shows up as a slow delete on the parent.
* Every column named as a filter in `docs/contracts/frontend-endpoint-matrix.md` is
  indexed. The contract already says which those are.
* Composite indexes are ordered most-selective-first and cover the documented sort order.
* Indexes are named explicitly (`idx_<table>_<columns>`), so a later migration can drop one
  by name rather than by guessing what the generator produced.
* Concurrent index creation cannot run inside a transaction, so any migration needing
  `CONCURRENTLY` sets `public $withinTransaction = false;` and does nothing else.

### 7. Concurrency

* Lifecycle transitions take a row lock inside a transaction (`SELECT … FOR UPDATE`) — two
  officers approving the same request at once must serialise, not interleave.
* Tables whose rows are edited by several actors carry `lock_version` (integer, incremented
  on write) for optimistic concurrency; a stale write is refused rather than silently
  overwriting the other officer's edit.
* Money movement is transactional and idempotent: a disbursement release requires an
  `Idempotency-Key`, which is why `idempotency_keys` is foundation infrastructure rather
  than something the disbursement TAB invents.

### 8. Auditability

* `audit_entries` is **append-only**: no `updated_at`, no update path, no delete path. The
  absence of `updated_at` is the structural signal — there is nowhere to record a change,
  because a change must not happen.
* In deployed environments the application role is granted `INSERT` and `SELECT` on that
  table and nothing else. That grant is infrastructure, not schema, and is recorded as a
  production gap.
* Every row carries who, what, when, and the `request_id` that correlates it to the API log
  a citizen can quote (conventions §2).
* Lifecycle transitions are their own append-only rows, never a mutated status column with
  the history thrown away (ADR 0007).

### 9. Personal data

Every column is classified, and the classification is recorded in the ERD:

| Class | Meaning | Handling |
| --- | --- | --- |
| `public` | published reference data | no restriction |
| `internal` | operational, not about a person | staff only |
| `personal` | identifies or describes a person | authorization + audit on read |
| `sensitive` | RA 9262 / RA 9344 membership, health, biometrics | additional permission, omitted from responses by default, never logged |

Rules that follow:

* **Data minimisation is a schema rule.** A column exists only for a stated purpose. Adding
  a personal-data column requires that purpose in the migration's docblock.
* **PhilSys: last four digits only** (RA 11055). There is no column for a full PSN anywhere,
  and there must never be — a field that does not exist cannot leak.
* **Encrypt at rest, in the column, what would be catastrophic in a dump**: government
  identifier fragments, contact details used for authentication, verification document
  references. Laravel's `encrypted` cast, so rotation is an application concern.
  Encrypted columns cannot be indexed or searched — that is the cost, and it is why the
  classification exists rather than encrypting everything.
* **Never log** a personal column's value (Article 5.5). The audit trail records *that* a
  record was read and by whom, not a copy of it.

### 10. Canonical source of truth

One fact, one owning module, one table (`docs/architecture/domain-boundary-map.md`).
Anything duplicated elsewhere is a cache: it is named so, it is derivable from the owner,
and it is never written to directly. A denormalised counter is a cache with a rebuild path,
not a second truth.

### 11. Effective-dated relationships

Facts that are true for a period carry `valid_from` and `valid_until` (nullable = open),
not a boolean. `is_current` is a computed view of the dates, never a stored flag that can
disagree with them. Overlap prevention is an application invariant with a test until
exclusion constraints are justified (§5).

### 12. Lifecycle and status

Per ADR 0007: one canonical state column plus an append-only transitions table recording
`from`, `to`, actor, reason and timestamp. Never a pair of booleans, never a status
inferred from the presence of a date, never a status assigned directly without a recorded
transition.

### 13. JSON — a narrow, listed exception

**Application state never lives in JSON.** No entity, no relationship, no status, no
amount, no anything the application filters, joins, sums or authorizes on.

JSON is permitted for exactly three things, and each use is listed in
`tests/Architecture/DatabaseConventionsTest.php` with a justification:

1. a verbatim payload from an external system, kept for evidence;
2. a cached HTTP response for idempotent replay;
3. genuinely schemaless annotation that is never queried, constrained or joined.

The reasoning is that JSON columns have no foreign keys, no unique constraints, no NOT NULL
per key, no type enforcement, and no migration story. A `status` inside a JSON blob cannot
be constrained, cannot be indexed usefully by a later requirement, and cannot be migrated
without rewriting every row. The convenience is real and it is paid for later, by someone
else. An allow-list makes each exception a decision rather than a habit.

### 14. Migrations

* **Transactional.** PostgreSQL has transactional DDL, so a failed migration leaves nothing
  half-applied. Do not defeat it by mixing schema and large data backfills in one file.
* **Schema in migrations, data in seeders**, except immutable reference rows.
* **Backfills are idempotent and chunked**, and re-running one must be harmless.
* **Every migration has a working `down()`.** It is exercised in non-production and asserted
  by test; a migration whose rollback was never run is a rollback that does not exist.
* **Production is forward-only.** Deployed migrations are never rolled back — recovery is a
  new forward migration. `down()` exists for local and staging, where re-running from empty
  is routine. Rolling back a deployed migration on live citizen data destroys the data the
  forward migration wrote.
* **Expand → migrate → contract** for every change to an existing shape (Article 6): add
  the new column, backfill, dual-write, cut over, drop the old one in a later deploy. No
  rename in a single step, ever — it breaks the running old code mid-deploy.

## Consequences

* Positive: nine modules add tables that look the same, join the same way, and fail the
  same way. A reviewer can read a new migration and see the deviation immediately.
* Positive: the boundary in ADR 0001 becomes visible in the schema — a cross-module FK is a
  reviewable defect rather than an invisible coupling.
* Positive: privacy obligations are enforced where the data is, not only where it is served.
* Negative: two identifiers per row (bigint + UUID) costs a column and an index on every
  table. Accepted: it is the cheapest way to get both join performance and non-enumerable
  public ids.
* Negative: no DB-level constraint on open vocabularies, and no exclusion constraints for
  effective-dating. Both are stated trade-offs with tests standing in, not oversights.
* Negative: encrypted columns cannot be searched, so any lookup on one needs a separate
  deterministic index column or a different access path. That must be designed per case.

## Alternatives rejected

* **UUID as the primary key.** Rejected: 16-byte keys in every index and every FK, for a
  benefit (non-enumerable ids) already obtained by a separate exposed column.
* **UUIDv4 for the public identifier.** Rejected: random keys fragment the unique index for
  no gain over v7, which is equally unguessable in practice.
* **`enum` check constraints everywhere.** Rejected for open vocabularies: every added role
  or status would become a locking table rewrite, which encourages people to avoid adding
  one honestly.
* **JSON-first / document-shaped tables.** Rejected outright — it is the fastest way to lose
  referential integrity, and this system's whole risk profile is referential.
* **Hard deletes with an archive table.** Rejected: two places for one fact, and the archive
  is invariably written by the code path nobody tested.

## Sources

* PostgreSQL 17 — data types, constraints, indexes, `UNIQUE NULLS NOT DISTINCT`,
  transactional DDL: <https://www.postgresql.org/docs/17/index.html>
* PostgreSQL — `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block:
  <https://www.postgresql.org/docs/17/sql-createindex.html>
* RFC 9562 — UUID versions, including the time-ordered version 7:
  <https://www.rfc-editor.org/rfc/rfc9562>
* Laravel 13 — migrations, schema builder, soft deletes, encrypted casts:
  <https://laravel.com/docs/13.x/migrations>
* Republic Act No. 10173 (Data Privacy Act of 2012) — proportionality, legitimate purpose,
  security of personal information: <https://privacy.gov.ph/data-privacy-act/>
* Republic Act No. 11055 (PhilSys Act) — handling of the PhilSys Number:
  <https://www.officialgazette.gov.ph/downloads/2018/08aug/20180806-RA-11055-RRD.pdf>
* RA 9262 (VAWC) and RA 9344 (Juvenile Justice) — confidentiality of the records this
  schema classifies as `sensitive`.
