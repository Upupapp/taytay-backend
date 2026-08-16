# ADR 0016 — The welfare case engine: one state machine, projected per audience

* **Status:** accepted
* **Date:** 2026-08-16
* **Implements:** [ADR 0007](0007-canonical-assistance-lifecycle.md) (amends its resource naming)
* **Extends:** [ADR 0012](0012-staff-scopes-and-provisioning.md), [ADR 0015](0015-vulnerability-as-explainable-decision-support.md)
* **Context:** TAB 11 — Social Welfare Case Management Engine

---

## Context

ADR 0007 settled the lifecycle during TAB 02 but built nothing: it chose the Angular staff
console's 13-state machine as canonical, defined the citizen projection, and left the
implementation to the TAB that owns casework. This is that TAB.

Three things had to be decided before writing code, and two of them are conflicts.

---

## Decisions

### 1. The lifecycle is ADR 0007's thirteen states — not TAB 01 §E's fourteen

TAB 01 §E lists a "universal welfare status vocabulary": *Draft, Submitted, Pending Review,
Under Verification, Assigned, Processing, Waiting Requirements, Approved, Rejected, Ready for
Release, Released, Completed, Cancelled, Archived.*

ADR 0007 chose a different set, derived by reading the real Angular source
(`domain/assistance/assistance-request.ts`) during the frontend contract audit.

**ADR 0007 wins**, and the §E list is treated as a paraphrase of it. Implementing §E
literally would have discarded an accepted ADR grounded in the actual frontend contract, and
broken the console this backend exists to serve. The mapping:

| TAB 01 §E | Canonical | Note |
| --- | --- | --- |
| Draft | `draft` | |
| Submitted | `submitted` | |
| Pending Review | `intake-review` | |
| Under Verification | `intake-review` | same stage; §E splits one step in two |
| Assigned | — | **assignment, not state** (ADR 0007 §5) |
| Processing | `assessment` | |
| Waiting Requirements | `returned` | |
| Approved | `approved` | |
| Rejected | `rejected` | |
| Ready for Release | `scheduled` | |
| Released | `released` | |
| Completed | `completed` | |
| Cancelled | `cancelled` | |
| Archived | — | **a flag, not a state** — see below |
| — | `endorsed` | §E has no term for a recommendation distinct from approval |
| — | `expired` | §E has no term for an abandoned `returned` case |

Two entries are deliberately not states:

* **Assigned.** Routing is assignment. A case in `assessment` routed to the health office is
  one state and one assignee; encoding the destination in the state name multiplies states by
  offices, which is the specific defect that made the citizen app's 17-state list unusable.
* **Archived.** A rejected case and a completed case both archive. Collapsing them into one
  terminal status loses the outcome, which is the single most important thing about a closed
  case. `archived_at` is a timestamp on a case that has already reached a terminal state.

And `endorsed` — absent from §E — is load-bearing: without a state for "recommended but not
yet approved" there is nothing for separation of duties to separate.

### 2. One transition endpoint, and legality is checked before permission

`POST /admin/cases/{case}/transitions` takes a target state and resolves the permission from
it. Nine verbs would be nine places the transition map could be forgotten, and the tenth
somebody adds in a hurry would be the one that skips it.

The check order is load-bearing:

1. legal per the transition map → else `409 INVALID_STATE_TRANSITION`
2. reason present where the target demands one → else `422`
3. actor holds the target's permission → else `403`
4. separation of duties → else `403`

**Legality precedes permission** (contract matrix §5). If permission came first, a caller
could probe which permissions they hold by watching whether an illegal transition answers 403
or 409, and map the authorization table from outside.

Reasons are required for `rejected`, `cancelled`, `returned`, `completed` and `expired` —
every state somebody will later be asked to justify. An unexplained rejection is
indistinguishable after the fact from an arbitrary one, and it is the applicant who bears
that.

### 3. Three history tables, because they answer different questions

* `welfare_case_transitions` — what state, when, by whom, why. Append-only.
* `welfare_case_assignments` — who held the file, effective-dated. "Who was responsible on the
  12th" is a different question from "what state was it in", and the one asked first when
  something has gone wrong.
* `welfare_case_events` — the material timeline, including events raised by other modules
  (a field visit, a satisfied requirement, a confirmed release) that have no state of their
  own.

### 4. Priority is a human judgement. The vulnerability score is not an input

`CasePriority` is set by a person, and `urgent` requires a recorded reason — moving somebody
ahead of everyone else waiting needs a name against it.

**The vulnerability score from ADR 0015 does not touch casework.** Not priority, not queue
order, not routing, not any decision. It is placeholder weights awaiting MSWDO approval
(gap G-20), it declares `decision_support_only: true` in its own payload, and safeguarding
factors contribute nothing to it by design. Wiring it into queue order would make an
unapproved ordering consequential — and would do it invisibly, because nobody reading a case
list can see where the order came from.

The case payload does not even embed a snapshot: embedding it would make it read as case data
rather than as something a worker chose to consult. Clients call the vulnerability endpoint
when a worker asks. That is what decision support means — a human reads the evidence and takes
responsibility for the judgement, against their own name.

This was re-examined at the start of TAB 11 rather than inherited. **G-20 is not blocking
here**, because nothing in this TAB needs the score; it becomes blocking for whichever TAB
first wants one.

### 5. The citizen projection is additive, not subtractive

`MyCaseController` builds the applicant payload by *listing what may be shown*. It never takes
the staff payload and removes things.

A subtractive projection leaks the first time somebody adds a column and forgets the
deny-list. An additive one fails closed: a new field is absent until a person decides it
belongs.

Absent by construction: the internal `reason` on every transition, staff identities, assignment
history, priority and its reason, `needs_home_visit`, `is_escalated`, barangay, and every
timeline event not explicitly written with a message for the applicant.

Two mechanisms make the timeline safe:

* `is_citizen_visible` is decided **at write time**, by the code that knows what the summary
  contains. A rule applied at render time is one the next endpoint forgets.
* An event is shown only if the flag is set **and** a `citizen_message` was written. Otherwise
  a mis-flagged event would fall back to the operator summary — precisely the staff-deliberation
  leak the split exists to prevent.

`reason` and `applicant_message` are separate columns for the same reason. Collapsing them is
how "claimant's account inconsistent with neighbour statements" ends up rendered in a citizen
app.

**Protective cases are invisible without `request.view-sensitive`** — absent from the list,
absent from the pagination total, and `404` rather than `403` on a guessed id. Knowing that a
protection case exists for a named person is most of the disclosure, so a 403 would defeat the
control. Opening one requires the same permission: starting a protection case is itself a
protection decision.

### 6. Separation of duties is enforced per case and actor, not per role

**The person who endorsed a case may not approve it.**

An endorsement is a social worker's recommendation; an approval commits public money to it.
One person doing both is the single-signature path every audit of a benefits programme looks
for first.

Enforced by checking the transition log for an `endorsed` row by this actor — so two staff who
both happen to hold both permissions still cannot self-approve, which a role-level check alone
would allow.

The role catalog reinforces it: `lgu_staff` holds intake/assess/endorse and **not** approve;
`lgu_admin` holds approve/reject/schedule/close and **not** endorse. Neither holds
`request.release`, which TAB 18 will grant to a role that does not approve — asserted now by a
test over this backend's own catalog rather than inherited from the client's copy of the rule.

---

## Consequences

**Good.**

* One state machine, one transition endpoint, one authorization table.
* An applicant's payload cannot leak deliberation, and the mechanism fails closed.
* Casework prioritisation is a recorded human judgement, not an unapproved formula.
* Who held a file, and who decided what, both survive independently of the case's current
  state.

**Costs, accepted.**

* Staff routes carry `/admin`, while the contract matrix documents them unprefixed. Folded
  into **G-19** with residents and households — one deviation class, one client change,
  rather than two conventions inside one backend.
* `welfare_cases.barangay_id` is denormalised from the resident so the queue can be scoped
  without asking ResidentProfile per row. A labelled cache (ADR 0008 §10), refreshed on write;
  the resident's own barangay stays canonical.
* Four tables and three history logs for what a naive design would do with one status column.
  That column is what makes a case's history unreconstructible.

**Open.**

* **TAB 12** adds intake and assessment onto this case. It should raise timeline events through
  `CaseTimeline::record()` rather than writing the table, so every row passes the same
  visibility decision.
* **TAB 18** builds release. It must introduce a role holding `request.release` and not
  `request.approve`, or `no_role_holds_both_approval_and_release` will fail — which is the
  intent.
* **`expired`** has no scheduler yet. A `returned` case sits there indefinitely until TAB 31
  adds the job that ages it out; the state and its reason requirement exist so that job has
  somewhere to land.
