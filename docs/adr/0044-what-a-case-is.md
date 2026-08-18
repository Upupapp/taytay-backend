# ADR 0044 — What a case is, and what an assistance request is

* Status: **Accepted in principle, pending MSWDO ratification** (see *How this was decided*)
* Date: 2026-08-18
* Deciders: integration TAB 04
* Extends: [ADR 0007](0007-one-canonical-assistance-lifecycle.md) — supersedes nothing in it
* Cross-referenced: `taytay-admin-web/docs/adr/0044-what-a-case-is.md`

## How this was decided, and what is still owed

TAB 04 asks for a working session with the MSWDO head, a social worker and an intake officer,
walking a real recurring family through both models. **That session has not happened**, and this
ADR does not pretend otherwise.

What follows is a decision taken under a standing authorisation to settle product, schema and
lifecycle questions without interrupting the office, recorded so that the session becomes a
**ratification with a concrete proposal in front of it** rather than an open-ended discussion. Two
things reduce the risk of deciding early:

1. Everything implemented under this ADR so far is **true under all three options** — see
   *What was implemented immediately*.
2. The parts that are option-specific are **not built**, and are listed as outstanding.

If the office chooses differently, what has been built does not have to be unwound.

## Context

The console and the backend both serve something called a "case", and they mean different things.

| | Console | Backend |
| --- | --- | --- |
| Entities | Two: `SocialCase` and `AssistanceRequest` | One: `welfare_cases` |
| Case lifecycle | 7 states, `closed` terminal | The console's **13-state assistance lifecycle**, adopted verbatim by ADR 0007 |
| Meaning | The office's continuing involvement with a household, which outlives several interventions (`DL-52`) | The intervention itself |
| Continuity | `continuesCaseId`, `linkedRequestIds`, no reopen | No `continues_case_id`, no continuing-involvement record in 38 migrations |

**The measured overlap is one state.** `assessment` appears in both vocabularies and nothing else
does. That is worse than disjoint sets: a `CaseRepository` pointed at the assistance route would
render one status correctly and blank the other twelve, and a screen that is *partly* right invites
the conclusion that the data is incomplete rather than that the wiring is wrong. It is now pinned
by test (`status-vocabularies.spec.ts`).

## Decision

**Option A — the two-entity model.** A case is the office's continuing involvement with a
household; an assistance request is one intervention inside it, and a case usually outlives
several.

### Why

1. **It is the model the office already works.** A family that receives a medical grant in March, a
   schooling grant in June and a follow-up visit in September has had one continuing involvement
   and three interventions. Option B can only record three unrelated events, or one request held
   open for six months to carry the history.
2. **It is the only option that keeps a family's history when a request closes.** Under Option B
   closure has to be reversible, or requests stay open for years. Both contradict `DL-53`, which
   makes closure terminal precisely so that "is this family still with us?" has one answer.
3. **It has a documented decision behind it** — `DL-52` to `DL-58`, reasoned about when the console
   was built, rather than inferred now from whichever schema is easier to change.
4. **Option B is cheaper today and is chosen for that reason.** The master command says plainly:
   *do not choose this to save schedule.*

### The second decision, in the same act: supersede, not merge

The backend ships `POST admin/resident-duplicates/{pair}/merge` and a `resident.merge` permission.
The console's domain states there is no merge (`DL-74`): resolving a pair records a **finding**
with a required reason and the reviewer's name, and `same-person` supersedes a record without
deleting it.

**Decision: supersede.** A merged record cannot be un-merged when the finding turns out to be
wrong, and a wrong finding about identity in a welfare registry means one household inherits
another household's history — including, potentially, a VAWC survivor's file attached to the wrong
person. Reversibility is worth more here than tidiness. `resident.merge` is withdrawn from the
canonical vocabulary in favour of `beneficiary.review-duplicates`, which is already held by the
social worker and deliberately withheld from intake (who usually created the second record) and
from the auditor (whose oversight must not alter the identities it checks).

If the office chooses merge instead, it must be reversible, gated on a second reviewer, and
audited as a destructive act.

## What was implemented immediately — true under all three options

**`admin/cases` → `admin/assistance-requests`, all 30 routes.** This is not part of the choice:

* ADR 0007 §2 **already specifies** `POST /assistance-requests/{id}/transitions`. The
  implementation drifted to `admin/cases`, which the sweep recorded as F-23. Renaming is
  conformance to an accepted decision, not a new one.
* Under **A**, `welfare_cases` is the assistance request and a new entity becomes the case. Under
  **B**, there is only the assistance request. Under **C**, this rename *is* the work. In all
  three the backend's current entity is the assistance request, so the name is right in all three.
* It removes the P0 trap now rather than after the session.

912 backend tests pass unchanged. `me/cases` is deliberately **not** renamed: it is consumed by a
shipped Flutter client, `/api/v1` is stable by ADR 0007, and ADR 0007 §3 already gives citizens
their own projected vocabulary. That rename belongs to `/api/v2`.

**The four status vocabularies reconciled** (TAB 04 step 4). Measured, not assumed:

| Vocabulary | Result |
| --- | --- |
| Assistance request | 13 states, identical both sides |
| Referral | 8 states, **identical** |
| Field visit | 5 states, **identical** |
| Enrolment | 3 states, **identical** |
| Release / disbursement | **Diverges — 9 console states against 6 API states, 3 shared** |

The release divergence is not a naming slip. `DL-94` holds that **deferred is the office's failing
and unclaimed is nobody's** — funds that have not arrived, a missing countersignature, a voucher
error, against a household that simply did not come. Mapping `unclaimed` onto the API's `failed`
would blame a family for the office's paperwork, and the record would read that way to every worker
afterwards. TAB 08 owns the reconciliation; the gap is pinned by test so it cannot widen quietly.

## What is not built, and waits on ratification

* **The continuing-involvement module** — migration, entity, endpoints, authorization, tests, with
  `continues_case_id` and an append-only event log. This is the option-specific half.
* **The six permission keys** held back in TAB 03: `case.view`, `case.manage`, `case.note`,
  `case-note.view-protected`, `case.close`, and the `resident.merge`/`beneficiary.review-duplicates`
  alignment. They mean something only once this is ratified.
* **The citizen-facing projection** (step 6): `welfare_case_events` carries `is_citizen_visible`
  and `citizen_message`; the console's `CaseEvent` has no such concept. A caseworker must be able
  to tell which of their notes a resident will read, and today the console cannot show them.

## Guardrails carried forward

* **Closure stays terminal.** No reopen, under any option. A recurrence is a new case naming the
  old.
* **Append-only semantics survive whichever option wins.** Every material change appends an event
  in the same act as the change, and every mutation carries a reason (`DL-54`).
* **The word "case" must not mean two things.** After the rename the backend no longer uses it for
  an intervention on any staff route. If Option A is ratified, the new entity takes the name; if
  the office prefers different words entirely, the names change everywhere — URL, payload, screen,
  and the words staff are trained on.

## Alternatives rejected

* **Option B, one entity.** Discards the distinction between an intervention and the office's
  continuing relationship with a household, and forces either a reopen or perpetually open requests.
* **Option C, keep both and rename one.** The rename is done, but as ADR 0007 conformance rather
  than as a substitute for the decision. Option C without a dated commitment to A ships a Cases
  screen with no backend behind it.
