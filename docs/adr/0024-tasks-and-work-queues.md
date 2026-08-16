# ADR 0024 — Tasks, work queues and workflow automation

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 19
* Extends: ADR 0021 (referrals), ADR 0022 (field visits), ADR 0023 (releases)

---

## Context

A task is the answer to *"what happens next on this case?"* — as a **record**, not an inference. A
screen that derives the next step from a case's status can only say what the process expects; a
task says what this office actually undertook to do, by when, and who owes it.

Three acceptance criteria: overdue tasks are queryable efficiently; completing a task records an
outcome without silently changing unrelated case state; linked entity access is still policy
checked.

---

## 1. `Tasks` is a module that listens and never calls back

Loaded last. It listens to `ReferralBecameOverdue` (TAB 16) and `VisitFollowUpDue` (TAB 17), and
calls into nothing above it. Welfare announces that a referral went overdue and does not know
Tasks exists.

Both events were published **with no listener at all**, deliberately. A seam built before it is
needed is a seam; a seam built after is a refactor. This TAB is the listener, and nothing in
Welfare changed to accommodate it.

---

## 2. A task carries nothing about its subject

**Decision: a task holds a subject *type* and *identifier* and nothing else. No case title, no
beneficiary name, no narrative, no status of the thing behind it.**

The master command asks for row-level object authorization so that team membership alone does not
grant access to a linked sensitive entity. There are two ways to satisfy that:

* check permission on the linked entity for every row in every queue; or
* **put nothing on the row worth protecting.**

The first is what most systems do, and it fails the first time somebody adds a field — a queue is
read by every worker in the building, so anything denormalised onto it is disclosed to all of them
regardless of whether they may open what it points at. The check has to be remembered in a place
where forgetting it is invisible.

The second has nothing to forget. The client follows the pointer to the owning module's endpoint,
which does its own check — so a barangay-scoped clerk can see a task pointing at another
barangay's case and still gets `404` opening it. **A task holds a pointer, not a key.**

The title is written by whoever raises the task, and the automatic ones are written carefully:
*"Chase referral REF-20260816-A1B2C — no response yet"* names the reference and not the client.
Enough to find the file; nothing to somebody who cannot open it.

---

## 3. Automation surfaces work and changes nothing

**Decision: `TaskService` imports no case, referral or release. Completing a task records an
outcome and touches nothing else.**

The master command asks to avoid hidden automation that changes case outcomes without explicit
rules. The strongest form of that is a service which structurally cannot: there is no line to add
one to.

Completing "close the case" does not close the case; it records that somebody says they did. That
distinction matters because a queue action is a low-ceremony click, and case outcomes are
decisions with permissions, reasons and audit entries attached to them.

**One open automatic task per subject**, via a derived `automation_key` with a unique index — the
same portable NULL-distinct trick as ADR 0019 §5. The sweep runs nightly and a referral stays
overdue until somebody chases it; without the key the queue grows a fresh copy every morning, and
within a fortnight it is fourteen identical rows and nobody trusts the queue.

**The key is released when the task closes**, which is what lets a recurrence raise a fresh task. A
key held forever would mean a referral that went overdue, was chased, and went overdue again never
produced a second task — the automation would fall silent exactly when it was needed twice.

**`raised_by_event` is recorded and projected.** "The system noticed this" and "a colleague asked
me to do this" carry different weight, and a queue that hides the difference trains people to
ignore the automatic ones.

---

## 4. `ActorContext::system()`

Added to Shared. A background listener is **not a guest**: a guest is an unauthenticated *caller*
who may yet be offered a public endpoint; a system actor is no caller at all. Conflating them means
a rule later relaxed for anonymous browsing silently applies to background work, or one tightened
against background work breaks a public page.

It carries no permissions and `DataScope::none()`, so it is deny-by-default like every other actor
— a listener needing scoped data must be given a service that scopes, not a context that skips it.
`subjectId` is null, so an automatic task is honestly attributed to **nobody** rather than to a
fictitious account or to whoever's request happened to trigger the sweep.

---

## 5. Overdue is an index question

"Overdue tasks are queryable efficiently" is asked three ways — for me, for a team, for everybody —
so there are three composite indexes, each leading with the column that narrows hardest for the
way that queue is actually opened: `(assigned_to, status, due_on)`, `(team, status, due_on)`,
`(status, due_on)`.

**A task with no due date is never overdue.** It was never a promise about a date, and sweeping it
into an overdue queue would make the queue a list of everything rather than a list of what is
late.

**`mine` resolves from the token**, never from a parameter. A queue filtered by an account id in
the query string is a queue anybody can point at anybody.

Ordering is overdue → priority → soonest → id. The id tiebreak means two workers opening the same
queue in the same second are told to do the same thing.

---

## 6. A `general` task type

The master command's list is introduced with "include", and every entry in it is case-linked. The
schema deliberately allows a task with no subject, because *"ring the barangay about the
distribution venue"* is real work that nothing in this system holds a row for.

Without a general type that task gets filed under whichever listed type is closest, and the type
column stops meaning anything.

---

## Consequences

* Seeing a queue discloses nothing; opening a subject still costs the permission it always did.
* Automation can be turned off by removing two listener registrations, and nothing else changes.
* A task is never the reason a case moved, so "who closed this case" always names a person.
* Teams are a label, not a table. If Taytay formalises team structure, that is a new table and a
  migration from the label (gap **G-32**).
