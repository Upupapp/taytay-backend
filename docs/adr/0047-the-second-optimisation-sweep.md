# ADR 0047 — The second optimisation sweep: what measuring found that reading did not

* **Status:** accepted
* **Date:** 2026-08-29
* **Related:** ADR 0042 (query budgets and the first sweep), Article 4 (collections are always
  paginated), evidence ledger L-15

---

## Context

ADR 0042 built the query-budget harness after the first sweep and pointed it at four endpoints,
across five measurements. Eleven days and a good deal of feature work later, this asked the
obvious question — what is still unmeasured — and answered it by measuring rather than reading.

**Four defects, on four screens people work through in bulk.**

| Endpoint | Cost | What it is |
| --- | --- | --- |
| `GET /admin/resident-duplicates` | 2 queries per row | `pairProjection()` called `find()` for both sides of every pair |
| `GET /admin/service-providers` | 4 queries per row | `channels()` and `servicesOffered()` each queried, and `problems()` asked for both again |
| `GET /admin/households` | 1 query per row | `member_count` derived per row instead of per page |
| `GET /admin/newsfeed-comments?reported=true` | — | `report_reasons` was null for every row, always |

The first three are N+1s. The fourth is not a performance defect at all: a moderator saw how many
reports a comment had and never why, for as long as the feature had existed.

---

## 1. None of the four was found by reading

A static scan reported `ProviderController` **clean** — the work was behind two innocuous model
accessors, and a third method that called both again. `ResidentDuplicateController` used
`Resident::withTrashed()->find()` directly in a projection rather than a named resolver, so it did
not look like the pattern anyone watches for.

This repeats ADR 0042's own finding, which is worth quoting because it was proven again rather
than merely inherited: a static scan of this codebase "flagged ten possible N+1s and twenty-one
possibly-missing indexes, and almost all of those were wrong in both directions".

**Two detectors were written afterwards, from the known shape of the defects, and run to
exhaustion. Neither would have found any of the four.** They are recorded in
`guarded-fallback-hides-missing-load` as narrowing tools, not as gates.

## 2. The fourth defect needed a different instrument, and now has one

A query budget cannot see a silently-null field: it costs no queries, which is exactly what makes
it silent. `report_reasons` is rendered only when the relation is loaded —
`relationLoaded('reports') ? … : null` — and nothing loaded it. The guard preventing an N+1 is
what made the omission invisible.

`tests/TestCase.php` now records, behind `FIELD_PROBE=1`, which response fields are ever non-null
across a whole suite run; `tools/field-probe-report.php` reports the ones that never are.

**Its first full run found no further defect, and that result is documented in the tool.** 166
fields were never non-null; intersected with what the class can actually be — a projection
rendering from a relation something must load — it reduces to two, both already settled. The rest
are write-path defaulting, enum reads, plain columns, and fields no fixture populates. Run it
after adding an endpoint or a projection, not as a routine sweep.

## 3. Mirrored pairs are where a fix stops halfway

`FamilyController::index()` already carried the exact `withCount` the household list lacked. The
two lists mirror each other; one had it and one did not, and the families budget existed while the
households budget did not, so nothing said.

This programme already names surface parity as a defect class. It is recorded here because the
instance was found by measurement, not by comparing the pair — and because the guard that would
have caught it is a budget on both halves, not a rule about writing them together.

## 4. Coverage, and why the raw number misleads

**29 of the 54 endpoints that call `ApiResponse::page` have a budget.** The other 25 were each
examined: config registers and enums with no rows to grow, pages bounded by a domain invariant or
a `limit`, already-batched queries, and the audit endpoints, which write an entry on every read
and would perturb their own measurement.

Two counting corrections are part of the record because both changed what got done:

* An earlier figure of 29 was reached by grepping every `/api/v1/…` string in the harness, which
  counts **fixture targets** as measured. The true figure at that moment was 25 — and
  `/admin/newsfeed`, `/admin/residents`, `/admin/events` and `/admin/households` were all
  miscounted as covered. One of those four turned out to be broken.
* Six pages were excluded as "scoped to one subject". That is not a bound — one event's history
  can have hundreds of rows. Each was re-read against the real question, and none does per-row
  work; the conclusion held and the reasoning did not.

**Count `measure()` calls, not URLs.** The harness docblock says so and gives the command.

---

## Decision

Extend the budgets rather than trust a scan; keep the exclusions written down with their reasons,
so the next person inherits a list of questions already answered rather than a count.

## Consequences

* A per-row query on any of the 29 measured endpoints now fails the suite.
* The four defects are fixed and published.
* **The unmeasured set is not a clean bill.** The audit endpoints have no guard, and every budget
  runs against SQLite — `ReleaseConcurrencyTest` is still skipped for the same reason, and no
  Docker runtime exists on the machine this was done on. Both are open, not solved.
