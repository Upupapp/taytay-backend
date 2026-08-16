# ADR 0017 — Assistance intake, drafts and assessment

* **Status:** accepted
* **Date:** 2026-08-16
* **Extends:** [ADR 0016](0016-welfare-case-engine.md)
* **Context:** TAB 12 — Assistance Intake & Assessment Backend

---

## Context

TAB 11 built the case and its lifecycle. TAB 12 builds the two ends that touch people: how a
request gets in, and how a social worker records what they found.

Both ends are where the system meets someone having a bad month, so the failure modes are
about them rather than about data:

* a retried submission on a dropped connection opens a second file, and the office finds out
  at payout;
* a half-typed form persists forever because nobody defined when it should not;
* an assessment's recommendation is read as a decision, and money moves without anyone with
  approval authority having decided anything.

---

## Decisions

### 1. One submission path; the channel is provenance, not a rule

A walk-in, a barangay referral, a web submission and a retried mobile submission all reach
`IntakeService::submit()` and produce the same case, opening transition and timeline entry.

`IntakeSource` records *how it arrived*, because the channel changes what the office knows:
a walk-in was taken by a clerk who saw the person; a self-service form was typed at home; a
legacy import was never verified here at all. Those are different evidential positions and
nobody can reconstruct which applied unless it was written down at the time.

What the source must never do is change the rules — that is Article 3.1. The acceptance
criterion "no duplicate domain logic across channels" is met structurally: there is no second
code path to drift, because there is no second code path.

**A citizen client cannot assert its source.** The server derives `citizen-web` or
`citizen-mobile` from the channel. A client claiming `walk-in` would be manufacturing evidence
that a clerk saw the applicant.

### 2. Self-service submissions must carry a privacy-notice acknowledgement

`consent_reference` and `privacy_notice_version` are required before an unattended submission,
and are held on the *draft* so the acknowledgement travels with the form the applicant was
actually looking at.

A counter intake is covered by the clerk's process — the applicant is present and the notice is
given. An unattended submission has no witness, so this is the only evidence RA 10173's
transparency obligation was met.

### 3. A draft is not a case, and it expires

`assistance_drafts` is a separate table, deliberately not a case in `draft` status.

An abandoned half-filled form is not a request the office has been asked to act on. Putting it
in the case queue would fill the backlog with things nobody submitted — and would make it
impossible to purge on a retention timer without deleting casework.

Expiry is **enforced, not decorative**: an expired draft is refused rather than silently
resurrected. A clock that resets whenever somebody returns is not a retention policy, and the
row would live forever. Thirty days is a placeholder; the DPO sets the real figure (G-21).

Ownership is part of every query, never a check after one — another caller's draft id resolves
to nothing rather than to a `403` that confirms it exists. Drafts hold the most revealing text
in the system, unfinished and unverified.

**Discarding a draft really deletes it.** Unusual here, and correct: nobody acted on it, no
decision rests on it, and it holds personal data the applicant explicitly decided not to give.
Keeping it "for the audit trail" would retain data whose only justification was a request that
was never made.

### 4. An assessment recommends. It never approves

`Recommendation` is an advisory vocabulary, and completing an assessment moves nothing. The
endpoint returns `suggested_next_status`; acting on it still goes through the transition
endpoint, still needs that target's permission, and still faces separation of duties.

The separation is enforced in three overlapping places — the vocabulary here, the `endorsed`
vs `approved` states in ADR 0016, and the per-actor rule in ADR 0016 §6 — because collapsing
recommendation into decision is the single most consequential mistake this module could make,
and it would be invisible in the database until an audit asked who authorised a payment.

`recommend-deny` deliberately suggests **nothing**. A refusal is a decision with its own
permission and its own mandatory reason; an assessor recommending denial does not make one.

Templates live in `config/assessment.php` behind a Domain class — the third time this codebase
has chosen config over a table for versioned policy, for the reason given in ADR 0015 §2.
**Every assessment pins its template version at open**, read once and stored. Reading it again
at completion would let a mid-assessment deploy change the version an in-progress form claims
to be, and its answers would be attributed to questions that were not the ones asked.

Required answers must be present before findings can be signed. Months later, an assessment
missing them reads exactly like one where the assessor concluded "none" or "no risk", and the
difference matters when somebody is asking why a case was refused.

The medical template records `billing_status` and `philhealth_applied` and **no diagnosis**. A
diagnosis is health information — the most restricted category under RA 10173 — and what the
office needs to decide is whether there is a bill the household cannot meet.

### 5. Prior history is narrow, and staff-only

`priorCasesFor()` returns identity, category, status and dates. Not narratives, not
assessments, not amounts.

An assessor needs to know this person has come three times this year. Reading what they said
each time is a separate decision with its own audited endpoint. The master command's rule that
"citizen submissions cannot query other residents" is met by construction: the citizen contract
contains no resident identifier at all — the resident is resolved from the token.

### 6. Idempotency, with its first real caller

`idempotency_keys` has existed since TAB 04 with **no caller**. `Shared\Application\IdempotencyService` is it.

The scenario is the normal case for a citizen endpoint, not an edge one: the request reaches
the server, the response is lost, the app retries. Client-side de-duplication cannot fix it —
the client is exactly the thing that lost the answer.

Three outcomes: same key and body replays the stored response **verbatim, status included**;
same key with a different body is `409`, because answering it with the old result would
silently discard the new request; same key still in flight is `409`, because two concurrent
attempts are not a replay.

**Ordering caught a real defect.** With the already-submitted check *outside* the idempotency
wrapper, a same-key retry never reached the replay: the first call had marked the draft
submitted, so the retry took the "here is your case" branch and answered `200` where the
original answered `201`. Safe, but not a replay — and a client comparing status codes sees two
outcomes for one request. The wrapper now encloses the whole operation.

The key is claimed in its own committed write *before* the work, so a rollback cannot erase it
and let a concurrent retry through. The unique index is the arbiter: the loser of a race is
told to retry rather than allowed to proceed.

### 7. The citizen cancellation invariant moved into the application layer

Reviewed as part of this TAB, and it was wrong in two ways.

`MyCaseController::cancel` checked `citizenMayCancel()` **outside** the row lock and then
called `transition()`. Between the read and the lock, staff can move the case to `assessment`
and the withdrawal lands anyway. And the rule lived in one controller, so TAB 12's new citizen
write paths would not have had it — with nothing to say so.

`CaseService::cancelByApplicant()` now re-checks inside the same transaction, after the lock.
This matters specifically because `CaseStatus::Cancelled` has no required permission — it has
two legitimate callers — so the applicant's limit cannot ride on the permission check; there is
no permission check to ride on.

### 8. G-20 re-evaluated: still non-consequential

The vulnerability score touches nothing in this TAB. Intake does not read it; assessment does
not read it; `IntakeService` opens a case at `submitted` and stops. No eligibility, no
priority, no routing.

The master command permits an automatic eligibility path only behind "an explicit LGU-approved
deterministic rule". None has been supplied, so none exists — and the assessment templates
carry no weights, no thresholds and no totals, because a form that computed an eligibility
number would be that automatic decision wearing a questionnaire's clothes.

**No human decision is required to proceed.** G-20 remains open and non-blocking; it becomes
blocking for whichever TAB first wants the score to decide something.

---

## Consequences

**Good.**

* A retried submission cannot open a second case, and the retry is indistinguishable from the
  original.
* Drafts have a defined end, enforced rather than documented.
* A recommendation cannot become a decision by accident.
* The cancellation invariant is now enforced where every caller passes, under the lock.

**Costs, accepted.**

* Four more tables. A single "requests" table holding drafts and submissions would need a
  status column doing two unrelated jobs, and no way to purge drafts without touching casework.
* The idempotency claim is a second committed write before the operation. That is the price of
  it surviving a rollback, which is the only reason it exists.
* Assessment templates are placeholders (G-21), so the questions are provisional. The
  alternative was inventing the MSWDO's instrument.

**Open.**

* **Requirements and documents** — the "missing requirements you may act on" the citizen
  projection promises — are TAB 15's. The timeline event type `requirement.satisfied` is
  reserved for it, to be raised through `CaseTimeline::record()` like everything else.
* **Draft purging** needs the scheduled job in TAB 31. `expires_at` is written and enforced on
  read today, so an expired draft is already inert; the job reclaims the row.
* **`Recommendation::Refer`** suggests no status because referral is TAB 16. When it lands, the
  suggestion should point at whatever state referral introduces rather than being special-cased
  here.
