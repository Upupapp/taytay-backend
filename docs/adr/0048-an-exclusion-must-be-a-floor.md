# ADR 0048 — An exclusion must be a floor, not a reading of the current code

* **Status:** accepted
* **Date:** 2026-08-30
* **Related:** ADR 0042 (query budgets), ADR 0047 (the second optimisation sweep), Article 7
  (definition of done)

---

## Context

ADR 0047 left thirteen paginating endpoints without a query budget and recorded a reason for each.
This pass tried to take that number to zero and found that the reasons fell into two kinds that had
been treated as one.

**Some endpoints cannot grow.** An event's history renders three columns of a single row and a
post's history four; `/admin/work/alerts` builds at most two alerts from two fixed `count()`
queries; `/me/privacy/consents` reads a four-value vocabulary; `/admin/reports` iterates a PHP
enum; the two `/services` routes read config; `/admin/residents/{id}/families` is capped at one row
by `familiesOf()`'s `whereNull('effective_to')` combined with the one-open-membership rule that
answers 409. No fixture can add a row to any of them without a code change.

**The rest were excluded because the code, read carefully, did no per-row work.** That is a
different claim. It describes an implementation rather than a bound, and it is exactly the thing a
regression test exists to hold still.

## Decision

**An endpoint may be left unmeasured only when it cannot grow.** "It batches explicitly" and "it is
a single grouped query" are descriptions of today's code and are therefore arguments FOR a budget,
not against one.

Five endpoints were measured on that basis: the team workload board, a citizen's own event
registrations, a resident's kinship history, a resident's duplicate findings, and a programme's
requirement templates. Coverage is **36 of 44**, and the eight without a budget are the floors
above.

## Consequences

* **One of the five was an N+1.** `/admin/programs/{id}/requirement-templates` cost six queries for
  one template version and eleven for six, because `requirementProjection()` read
  `$requirement->acceptedDocuments()` — the relation METHOD — once per row. This is the same defect
  class ADR 0047 fixed in `ServiceProvider::channels()`, surviving in a second place precisely
  because the endpoint had been reasoned about instead of measured.
* **The same call sat in the public programme detail**, so every citizen opening a programme paid
  one query per requirement. Nothing measured that page either. Both read paths share
  `requirementsFor()`, which now eager-loads.
* **All five budgets are mutation-proven**: each fails when the batching it guards is removed. A
  budget that never bites is not a guard, and a no-op control mutation was run first to show the
  harness could tell the difference.
* **The coverage prose went stale within the hour it was written.** The paragraph naming
  `/admin/residents`, `/admin/events` and `/admin/households` as unmeasured survived the pass that
  measured all three. The `measure()` grep is the only figure worth quoting.
* The first scan written for this pass reproduced ADR 0047's own error: it read single-quoted URL
  assignments only, so two measured endpoints came back unmeasured — one of them ADR 0042's
  headline defect. Corrected before it reached a count anybody acted on.
