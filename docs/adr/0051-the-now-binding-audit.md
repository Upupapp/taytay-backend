# ADR 0051 — Auditing the rest of the `now()` comparisons

* **Status:** accepted
* **Date:** 2026-08-30
* **Related:** ADR 0049 (a role was not in force when it was granted), ADR 0050 (the PostgreSQL
  gate), Article 5.4 (audit records are append-only)

---

## Context

ADR 0049 fixed one comparison — `valid_from <= now()` — where Laravel's whole-second binding met a
column PostgreSQL had rounded UP. The obvious question is how many more there are.

## What the audit found

**Fifteen sites compare a column against the clock.** Five are `whereDate(...)`, which is
deliberately day-granular and unaffected. That leaves ten timestamp comparisons: `expires_at` on
report exports, welfare drafts, personal access tokens and idempotency keys; `ends_at` on events;
`publish_at` on newsfeed posts; `effective_from` on governance records; and two rolling one-hour
windows in the operations console.

**None of them is a defect, and the reason is precise rather than reassuring.**

The truncation only bites when the STORED value carries sub-second precision that the BOUND value
lacks. Laravel serialises both sides through the same `Y-m-d H:i:s` format, so a column Laravel
writes is already truncated when it is stored and the comparison is exactly consistent. The bug in
ADR 0049 existed because `valid_from` was written by the DATABASE, via a `CURRENT_TIMESTAMP`
default, at a precision the binding could not express.

Checked against the live schema: **not one of the ten columns compared against `now()` has a
database default.** The class does not reach them.

Two read paths could in principle be perturbed by second-granular ordering — the audit trail and
the governance register. Both already tie-break on `id`, so their ordering is deterministic
regardless. Checked rather than assumed.

## Decision

No comparison changes. Ten columns elsewhere — every remaining timestamp the database stamps
itself — are widened to microsecond precision, and `SchemaPrecisionTest` asserts the invariant.

## Consequences

* **This is not a fix for a live defect, and saying otherwise would overstate it.** It closes the
  class so that it cannot come back through a different door: the next
  `where('created_at', '<=', now())` written over one of these columns would reproduce ADR 0049
  exactly, and now the schema refuses the precondition rather than the reviewer having to catch the
  call site.
* **`audit_entries.created_at` was stamped up to half a second in the future.** Article 5.4 makes
  that an append-only legal record, and a record of when something happened should not name a
  moment that had not happened yet. That is reason enough on its own terms, independent of any
  query.
* **The guard is on the schema, not the call sites.** "No call site does the dangerous thing" is a
  description of today's code — the same mistake ADR 0048 catalogued in the query-budget
  exclusions, one layer down.
* `failed_jobs` is excluded by name: it is Laravel's own table, a framework upgrade may recreate
  it, and `failed_at` is operational rather than something a citizen relies on.
* The guard is meaningful on PostgreSQL only and SKIPS on SQLite with its reason stated, rather
  than passing and implying a check that did not happen. It runs in `composer check:pg` (ADR 0050).
* **A no-op is a finding.** The audit's answer is "nothing else to fix", and it is written down
  with the evidence so the next person inherits the question already answered rather than
  re-deriving fifteen call sites.
