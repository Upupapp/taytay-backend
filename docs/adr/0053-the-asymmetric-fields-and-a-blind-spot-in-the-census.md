# ADR 0053 — Reading the 105 asymmetric fields, and a blind spot they exposed

* **Status:** accepted for the triage; the Article 4 exposure in §3 is FILED AND OPEN
* **Date:** 2026-08-31
* **Related:** ADR 0047 (the field probe), ADR 0048 (query-budget coverage), ADR 0052 (the probe on
  PostgreSQL), Article 4 (collections are always paginated)

---

## 1. The triage, and its result

ADR 0052 sharpened the probe so that fields *populated on one endpoint and never on a sibling* are
reported first — the shape the moderation defect had, twice. That left 105 such fields to read.

**None of them is that defect. The class is exhausted, and here is why rather than an assurance.**

The defect requires a projection that renders a field from a relation or aggregate something else
must have loaded, falling back to `null` when it has not. Every such site in the codebase:

* **Three `relationLoaded(...)` guards.** Two are `report_reasons`, which was the defect and is
  fixed on both endpoints. The third, `DocumentPresenter::version()`, falls back to a QUERY rather
  than to null — so it cannot render a silent null; it could only cost a query per row. Checked:
  both list paths eager-load `file`, one of them with a comment saying why.
* **Five `..._count ??` aggregate fallbacks.** Four fall back to a live `->count()`, so they cannot
  report a wrong zero. The fifth was `report_count`, fixed with `report_reasons`.
* **The collection-shaped fields** — `head`, `families`, `members`, `relationships`, `team`,
  `problems`, `criteria`, `requirements`, `candidates`, `requests`, `reactions`, `my_reaction`,
  `available_actions`, `findings` — are each built by an explicit query or service call, not by
  reading a relation that may or may not be loaded. There is nothing to leave unloaded.

## 2. What the other 105 actually are, shown rather than asserted

`archived_at` is the archetype and the clearest case to check.

It is **non-null across 89 observations of `/admin/newsfeed/{post}/status`** and null across 105 of
`/admin/newsfeed`. Same field, same controller, and — this is the point — **the same projection
line**: `'archived_at' => $post->archived_at?->toIso8601ZuluString()`. A plain column read with no
relation in it. The list has simply never been called after a post was archived.

That is a fixture gap wearing the defect's clothes, which is the failure mode ADR 0047 already
recorded for `media`. **An asymmetry is a question, and this is what most of the answers look
like.**

## 3. The blind spot, which is the finding worth keeping

`/admin/assistance-requests/{case}/requirements/{requirement}/documents` returns **every version a
document has ever had, unpaginated**, inside an `ApiResponse::item` envelope.

ADR 0048 reported query-budget coverage as 36 of 44, where 44 is the number of handlers calling
`ApiResponse::page`. **A collection returned inside an `item` envelope is invisible to that
denominator.** The census could not have counted it, so "36 of 44" was never the whole surface —
it was the whole surface *of one envelope*.

**A census of the other envelope finds 27 GET endpoints returning a collection inside `item`.**
Some are legitimately bounded — `/staff/authority-catalog` iterates an enum,
`/me/notification-preferences` a fixed set. Others are not bounded by anything:

| Endpoint | Grows with |
| --- | --- |
| `/admin/assistance-requests/{case}/notes` | every note ever added to a case |
| `/admin/assistance-requests/{case}/history` | every transition |
| `/admin/residents/{resident}/history` | every change to a resident |
| `/admin/residents/{resident}/relationships` | every recorded kinship |
| `/admin/release-batches/{batch}/manifest` | every release in a batch — hundreds at a payout |
| `/admin/exports`, `/admin/saved-views`, `/me/sessions`, `/me/devices`, `/me/referrals` | use |

Article 4 says collections are always paginated and never return an unbounded list. On the reading
above, several of these do.

**This is filed, not fixed.** Paginating them is a breaking change to response shape for every
client that reads them, which is an `/api/v2` conversation under Article 4, not a defect to patch
quietly — and the judgement about which are genuinely unbounded belongs with the owner rather than
with the agent that found them.

## Decision

Stop probing for the guarded-fallback class: it is exhausted and the evidence is above, so the next
person inherits an answered question rather than 105 names.

Record the envelope blind spot as the live one. **The lesson is the same one ADR 0047 learned about
a 4000-character window and ADR 0052 about a mislabelled headline, one layer further out: a census
measures what its denominator can see, and the denominator is a choice somebody made.**

## Consequences

* The probe stays a tool to run after adding an endpoint or a projection, not a routine sweep.
* Query-budget coverage should be restated against both envelopes once §3 is decided. Until then,
  "36 of 44" is true and narrower than it sounds, and ADR 0048 should be read with this beside it.
* No code changed in this pass. **A no-op is a finding**, and the alternative — trimming 105 names
  into a list of suspicions — would have read like work and settled nothing.
