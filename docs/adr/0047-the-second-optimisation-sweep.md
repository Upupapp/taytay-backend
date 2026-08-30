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

**31 of the 44 endpoints that call `ApiResponse::page` have a budget.** The other 13 were each
examined: config-backed catalogues, an enum, single-subject pages that do no per-row work, two
that batch explicitly, one capped by a domain invariant, and one closed four-value vocabulary.

**The denominator was 54 in the first version of this ADR, and 54 was wrong.** It came from a
scan that read a fixed 4000-character window from each handler, which overruns into the next
method — so ten `ApiResponse::item` endpoints were counted as paginating because a NEIGHBOUR
called `page`. Re-derived with balanced-brace parsing of each method body: 44. This correction
flatters the result, moving coverage from 57% to 70%, which is precisely why it is stated here
rather than quietly applied.

**Four corrections are part of this record, because each changed what got done — and the last two
were made after this ADR was first published, against its own claims:**

* An earlier figure of 29 was reached by grepping every `/api/v1/…` string in the harness, which
  counts **fixture targets** as measured. The true figure at that moment was 25 — and
  `/admin/newsfeed`, `/admin/residents`, `/admin/events` and `/admin/households` were all
  miscounted as covered. One of those four turned out to be broken.
* Six pages were excluded as "scoped to one subject". That is not a bound — one event's history
  can have hundreds of rows. Each was re-read against the real question, and none does per-row
  work; the conclusion held and the reasoning did not.
* The denominator itself was wrong: 54 endpoints "call `ApiResponse::page`" only if you read a
  fixed window past the end of each method. Ten of them are `item` handlers whose neighbour
  paginates. Parse the body, do not window it — the same class of error as counting fixture
  targets, one level up.
* The audit endpoints were excluded because reading the trail WRITES to it (`audit.searched`), so
  a budget fixture would grow the table it counts. True, and not a reason: the extra row is one
  per request whether the page holds two entries or twelve, so it lands in both samples and
  cancels out of the SLOPE. `assertBudget` compares two measurements and never asserts an absolute
  number — the first thing the harness docblock says. **The exclusion contradicted the premise of
  the file it was written in.** Both are measured now, both flat, and this ADR said they had no
  guard for about an hour.

**Count `measure()` calls, not URLs.** The harness docblock says so and gives the command.

---

## Decision

Extend the budgets rather than trust a scan; keep the exclusions written down with their reasons,
so the next person inherits a list of questions already answered rather than a count.

## Consequences

* A per-row query on any of the 31 measured endpoints now fails the suite.
* The four defects are fixed and published.
* **The unmeasured set is not a clean bill**, and the reasons in it deserve more scepticism than
  the conclusions. Three exclusions written during this sweep failed on inspection — "scoped to
  one subject", a coverage tally that counted fixture targets, and the audit endpoints above.
  Each was plausible; none survived being checked.
* **Every budget runs against SQLite.** `ReleaseConcurrencyTest` is still skipped for the same
  reason, and no Docker runtime exists on the machine this was done on. That is open, not solved.
