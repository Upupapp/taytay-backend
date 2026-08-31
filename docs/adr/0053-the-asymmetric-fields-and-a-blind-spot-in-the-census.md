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

### The 27, classified

Each was read for what actually bounds it, rather than sorted by how the name sounds.

**Bounded by code or config — Article 4 satisfied in substance (6).** Nothing a fixture or a busy
year can grow.

| Endpoint | Bound |
| --- | --- |
| `/staff/authority-catalog` | `Permission::cases()` and `Role::cases()` — PHP enums |
| `/admin/assessment-templates` | `config('assessment.templates')` — never touches the database |
| `/me/notification-preferences` | one row per notification type, a closed vocabulary |
| `/admin/exports` | an explicit `limit(self::EXPORT_HISTORY_LIMIT)` — **the only one of the 27 with a limit** |
| `/me/sessions`, `/me/devices` | active, unexpired tokens only |

**Bounded by the shape of the domain, not by code (9).** Each is one subject's small set — a
household's members, a programme's requirement template, one visit's checklist. They can be
argued about, none is a plausible operational problem, and none has a cap in code.

`/admin/assistance-requests/{case}/requirements`, `/me/cases/{case}/requirements`, `/me/household`,
`/admin/visits/{visit}`, `/admin/residents/{resident}/account-links`,
`/admin/residents/{resident}/relationships`, `/admin/residents/{resident}/households`,
`/admin/residents/{resident}/safeguarding`, `/me/assistance/drafts`.

**Grows with activity, bounded by nothing (12).** These are the decision.

| Endpoint | Grows with |
| --- | --- |
| **`/admin/release-batches/{batch}/manifest`** | **every release in a payout batch** |
| `/admin/assistance-requests/{case}/notes` | every note ever written on a case |
| `/admin/assistance-requests/{case}/history` | every lifecycle transition |
| `/admin/assistance-requests/{case}/eligibility-checks` | every check ever run |
| ~~`/admin/assistance-requests/{case}/prior-cases`~~ | **WRONG — capped at 50 by `priorCasesFor()`.** Found while writing its budget; the classification above read the controller and not the service behind it |
| `/admin/assistance-requests/{case}/document-requests` | every request raised |
| `/admin/…/requirements/{requirement}/documents` | every version of one document |
| `/admin/referrals/{referral}` | notes on the referral |
| `/admin/releases/{release}` | transitions on the release |
| `/admin/residents/{resident}/history` | every change to the record |
| `/admin/saved-views` | staff usage |
| `/me/referrals` | a resident's referrals |

**The manifest is the one with a concrete operational risk, and it is not a hypothetical.**
`manifestQuery()` selects every release in the batch with no limit, nothing caps batch size when a
batch is created, and a municipal payout batch is hundreds to thousands of beneficiaries. It is
also the document staff open at the payout table, on a phone, on an LGU connection — the worst
place for an unbounded response. Everything else on that list is a page somebody opens at a desk.

### The ruling, and what was done

The owner ruled: **bound the manifest, budget the rest.** Both halves are done.

The manifest is paginated with its totals aggregated in the database, so a page can never be
reported as the payout — see the commit for why bounding it naively would have been worse than
leaving it alone.

**All twelve now carry a query budget** (two already did), each mutation-proven: every one fails
when a per-row lookup is introduced into its projection. That closes §1's loose end as well —
`DocumentPresenter::version()` falls back to a query per version when `file` is not loaded, and
dropping the eager load now fails the document-history budget rather than merely being true today.

Two corrections came out of writing them, both worth more than the tests:

* **`prior-cases` is capped at 50** and the table above called it unbounded. The classification
  read the controller and not the service behind it.
* **The `prior-cases` budget passed while measuring nothing.** Its `rowsSoFar()` arm counted
  `welfare_cases` table-wide, which the fixture's own case already satisfied, so the growth loop
  ended immediately, no rows were created, and the budget compared zero against zero. It was caught
  by the MUTATION NOT BITING, not by the test failing — a green test that asserts nothing looks
  exactly like a green test. Every budget added in this pass now asserts what the page actually
  renders.

### The exclusions were re-audited, and a second one was false

`prior-cases` being misclassified prompted re-reading the eight endpoints still excluded from the
budgets. **`/me/privacy/consents` was excluded on a floor that does not exist.**

The exclusion said the page was capped at four rows because `privacy.legal_bases` names exactly
four purposes whose basis is consent. Four is the number of PURPOSES, and the page does not render
purposes — `consentsFor()` returns every consent RECORD the subject holds, and `GovernanceRegistry`
says so in its own comment: *"withdrawn rows carry NULL and accumulate freely."* Grant and withdraw
one purpose ten times and the page has ten rows. It is now budgeted and mutation-proven.

**Both failures are the same failure**: the floor was read off the controller, or off a vocabulary,
rather than off the query that builds the page.

The remaining seven were re-checked by tracing one level deeper than the handler and each holds —
this time with the enforcing code named. The families page rests on an invariant that IS enforced:
`HouseholdMembershipService` refuses a second open family membership with `ErrorCode::Conflict`,
which this programme had asserted for some time and never located.

Coverage is 37 of 44.

Article 4's pagination question is unchanged and still open.

**The pagination is filed, not fixed.** Paginating them is a breaking change to response shape for every
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
