# ADR 0052 — The field probe on PostgreSQL, and the asymmetry it was hiding

* **Status:** accepted
* **Date:** 2026-08-30
* **Related:** ADR 0047 (the field probe and the mirrored-pair class), ADR 0050 (the PostgreSQL
  gate), ADR 0051 (the `now()` audit)

---

## Context

The field probe records which response fields are ever non-null across a whole suite run. It had
been run once, on SQLite, and found no further defect. ADR 0050 made running on PostgreSQL a single
command, so the obvious question was whether the driver that had produced six defects would produce
a seventh here.

## What the comparison found

**Nothing. The two runs are identical to the pair:** 35965 observations, 2101 endpoint/field pairs,
60 fields never non-null anywhere, 478 never-non-null pairs, and zero differences in either
direction. This class of defect is not driver-sensitive, which is worth knowing before anybody
spends the run again.

Two things had to be fixed before that comparison meant anything.

**The probe keyed on the raw URL.** Event detail slugs carry a random suffix, so
`/events/barangay-feeding-programme-48hgqy` was a fresh key every run and those endpoints could
never aggregate. The first diff showed 33 pairs "differing" on each side; they were the same eleven
fields under different slugs. The key is now the ROUTE TEMPLATE, taken from the router rather than
munged out of the URL, which the router knows exactly. That collapsed 74 spurious pairs.

## The defect it did find

**`report_reasons` was non-null on the moderation QUEUE and never once non-null across six
observations of the moderation ACTION.** `moderatorProjection()` is shared by both;
`reportedComments()` loads the `reports` relation and `moderate()` did not. The projection renders
the reasons only when the relation is loaded and the count from a `reports_count` aggregate, so the
response a moderator received the instant they acted reported **zero reports and no reasons for a
comment that had been reported twice** — the comment they were looking at because it was reported.

This is the same defect ADR 0047 fixed, on the sibling endpoint: the mirrored-pair class, where a
fix stops halfway.

## Why it was not found the first time

It was in the first run's data and nothing surfaced it.

* The headline said "166 fields never once non-null" and counted fields null on **at least one**
  endpoint. Only 60 were never non-null anywhere; the other 106 were asymmetric — populated
  somewhere, never here.
* That asymmetric population is the defect's shape, and the tool's own docblock said so, while the
  output ranked everything in one list cut at thirty by raw observation count. A six-observation
  asymmetry sat far below fields null two hundred times.

**Volume is not severity.** The two populations are now counted and printed separately, asymmetries
first, with every endpoint listed rather than the first three.

## Consequences

* The moderation response now loads the relation and the count; a test asserts both, and it fails
  with the old code.
* `report_reasons` needed fixing twice, on two endpoints sharing one projection. A guarded fallback
  — `relationLoaded(...) ? … : null` — hides a missing load once per call site, not once per
  codebase.
* **A no-op is still a finding.** The driver comparison found nothing, and that is recorded with
  its numbers so the next person does not spend the run to learn it.
