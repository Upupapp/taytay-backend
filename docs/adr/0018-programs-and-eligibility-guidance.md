# ADR 0018 — Programmes, requirements, and eligibility guidance that flags but never decides

* **Status:** accepted
* **Date:** 2026-08-16
* **Extends:** [ADR 0015](0015-vulnerability-as-explainable-decision-support.md), [ADR 0016](0016-welfare-case-engine.md), [ADR 0017](0017-assistance-intake-and-assessment.md)
* **Context:** TAB 13 — Programs, Services, Requirements & Eligibility Guidance

---

## Context

A case has to be *about* something: a programme, with conditions, requirements, dates and an
office that owns it. TAB 13 supplies that, and with it the most dangerous instruction in the
whole master command:

> The engine may flag likely matches/mismatches but must not become an opaque denial system.

That sentence describes a failure mode rather than a feature, and the failure is quiet. Nobody
sets out to build an opaque denial system. It arrives one reasonable step at a time: a rule
engine, then a score, then a threshold, then a screen that shows "ineligible" in red, then a
clerk who stops arguing with it — and at no point does anybody decide to automate a refusal.

TAB 13 is also where two open gaps converge. G-20 (unapproved vulnerability weights) and G-21
(placeholder assessment forms) both become dangerous the moment something *consequential* reads
them, and eligibility is the first consequential thing this system has built.

---

## Decisions

### 1. Programmes are rows; the vulnerability ruleset and assessment forms stay config

Three versioned policy artefacts now exist, and they are stored differently on purpose:

| Artefact | Store | Why |
| --- | --- | --- |
| Vulnerability weights (ADR 0015) | config | Changes at the pace of policy review; needs a diff and a reviewer |
| Assessment templates (ADR 0017) | config | Same |
| **Programmes and their guidance** | **table** | Changes at the pace of *events* |

An MSWDO officer opens a relief programme on Tuesday because a storm landed on Monday. A config
deploy is the wrong instrument for that, and would make disaster response wait on a developer.
That is also the acceptance criterion for this TAB — "policy/config updates do not require
rewriting controllers" — and it is tested by changing a criterion row and watching the outcome
change.

### 2. Publication and visibility are separate facts

`status` (draft/published/retired) and `is_citizen_visible` are two columns, not one.

An internal referral programme can be fully published and operational while remaining invisible
to the public catalogue. Collapsing them would force staff to leave a live programme in `draft`
to hide it — and a draft programme accepts no applications, so the workaround breaks the thing
it was working around.

Both are filtered **at the query**, so an unannounced programme is absent from the rows *and*
from the pagination total. A count that included it would tell an anonymous caller how many
programmes the LGU runs that it has not announced. A guessed id returns `404`, never `403`.

A programme cannot be published with no requirements. A published programme that asks for
nothing sends an applicant to a counter to be told what they should have brought — the
commonest way a person makes two trips they cannot afford.

### 3. Guidance flags. It does not decide. Four structural controls

**(a) The verdict vocabulary has no `ineligible`.** `EligibilityOutcome` is
`likely-eligible` / `likely-ineligible` / `needs-review`. The absence is the control: the first
thing a later feature would do with an `ineligible` is read it as the answer, and the second
thing somebody would do is wire it to a refusal. `likely-ineligible` cannot be mistaken for a
determination in a code review, on a screen, or in a conversation with an applicant.

**(b) Every criterion carries its own explanation, mandatory at the schema level.**
`citizen_explanation` is NOT NULL and required by validation. A criterion nobody can explain to
the person it excludes *is* the opaque denial, so there is nowhere to store one.

**(c) There is no score, no threshold, no auto-deny column.** The schema gives an opaque denial
nowhere to live. `is_blocking` is the strongest thing present and it still decides nothing — it
marks a criterion whose failure means the office should look closely, and it is reported as
exactly that.

**(d) The facts are a short closed set, and the vulnerability score is not among them.**
`EligibilityFact` allows age, barangay, sector, household size, verification tier and income —
every one of them something a clerk can look up, point at, and explain at a counter. There is
no `vulnerability_score` fact and there will not be one without a new ADR: that score is
unapproved placeholder weighting (G-20) that declares `decision_support_only: true` in its own
payload, and wiring it into eligibility would make an unapproved ordering consequential *one
layer removed from anybody who could see it happening*. A caseworker reading "likely
ineligible" has no way to know a placeholder weight put it there.

There is also no rule-expression language. One would let somebody encode policy nobody
reviewed, in a syntax nobody at the MSWDO reads, producing refusals nobody can explain.

**Absence is `unknown`, never `not-met`.** A missing income figure means nobody has asked yet,
not that the applicant earns too much. Treating absence as failure would turn every incomplete
record into a refusal — and incomplete records belong overwhelmingly to the people least able to
complete them: those without documents, without an address history, without anyone to help them
fill a form. Any unknown sends the whole check to `needs-review`, **outranking even a clear
blocking mismatch**, because the unknown might be the thing that explains the mismatch.

### 4. National programmes are tracked, not administered

`authority` distinguishes `local`, `national` and `partner`. 4Ps and similar are referred and
tracked here, but DSWD sets their eligibility.

The citizen payload says `decided_by` plainly, because an applicant deciding whether to travel
to an office deserves to know the LGU does not control the answer. `ProgramSummary` carries
`locallyDetermined` so no consumer can present local guidance against a national programme as a
determination.

### 5. Funding source is a label, not a ledger

`funding_source_label` holds "Local funds" or "DSWD AICS". There is no `budget_remaining`.

This backend tracks welfare operations, not appropriations. A budget column here would be a
second, unreconciled copy of a figure the treasury owns, and the first time the two disagreed
somebody would trust the wrong one — probably the one on the screen in front of them.

### 6. The guidance version used is pinned to the case

`welfare_case_eligibility_checks` records the programme, the guidance version and every
criterion outcome with its observed value, append-only, at the moment the check ran.

This is the acceptance criterion "the eligibility guidance version used in a case is retained
for audit", and it is also the snapshot pinning ADR 0015 §3 deferred out of TAB 10 — it lands
here because here it finally has a caller and something to justify.

Opening a new guidance version **copies the criteria forward** rather than editing in place.
Editing under a published version would rewrite the rules a past decision was made against, and
checks pinned to that version would resolve to criteria that never applied to them.

The observed value is stored beside each outcome so a caseworker can *check* the result rather
than trust it. That is the difference between guidance and an oracle.

### 7. ResidentProfile decides what may be read for eligibility

`ResidentDirectory::eligibilityFactsFor()` assembles the facts. Neither ServiceCatalog nor
Welfare reaches into residents, households or sectors to build them.

Three consequences, all wanted:

* The decision about what may be used for eligibility lives in **one** place, rather than three
  that drift — and the one that drifts is always the one nobody reviewed.
* **Safeguarding sectors are excluded.** A criterion reading `vawc-survivor` would leak
  protection status to everyone who can see a guidance result — the same disclosure ADR 0015 §4
  keeps out of the vulnerability score, arriving by a different route.
* A caller who cannot see income produces no income fact, the criterion reads `unknown`, and the
  outcome degrades to "a human should look". The right failure, reached without the guidance
  engine knowing what it was not shown.

It returns a plain keyed array rather than a typed object, because importing ServiceCatalog's
`EligibilityFact` into ResidentProfile would be a downward dependency the boundary map forbids.

### 8. There is no citizen eligibility endpoint

Same reasoning as the vulnerability score in ADR 0015 §5. "You are likely ineligible" reads as a
refusal however carefully it is worded, and it is not one — nobody has decided anything. An
applicant hears an outcome when a person with authority makes it, together with the reason and
the route to appeal.

The public programme detail *does* carry the conditions in words, so somebody can judge whether
to apply. It carries no comparators, thresholds or blocking flags: publishing the exact numbers
would turn an assistance programme into a form to be gamed, and the people who would game it
successfully are not the ones it exists for.

---

## Consequences

**Good.**

* Policy changes are rows, so the office can respond to a disaster without a deploy.
* Every advisory outcome is explainable, checkable, and pinned to the rules that produced it.
* An unapproved score cannot reach a consequential decision, and the schema has nowhere to put
  one if somebody tried.
* Incomplete records route to humans instead of being refused by default.

**Costs, accepted.**

* Eight tables. A JSON `rules` blob on the programme would have been one column and unqueryable,
  unversionable and unexplainable — every property this decision depends on.
* Guidance cannot express compound conditions ("over 60 **or** PWD"). Deliberate: expressiveness
  is exactly what turns a reviewable rule list into an unreadable policy language. A programme
  needing that gets two criteria and a human reading both.
* Facts are assembled per check rather than cached. Correct — a cached fact is a stale fact, and
  this one decides who is advised toward help.

**Open.**

* **Requirement satisfaction** — matching uploaded documents to `program_requirements` — is
  TAB 15's. The `requirement.satisfied` timeline event is reserved for it.
* **`program_approvers`** records policy and nothing reads it yet. TAB 18 should consult it as
  one input to segregation of duties, alongside the role catalog.
* **G-20 stays open and non-blocking.** It was re-examined here, at the first consequential
  decision point, and deliberately kept out. It becomes blocking only if a future TAB proposes
  reading the score, which would need an ADR superseding this section.
