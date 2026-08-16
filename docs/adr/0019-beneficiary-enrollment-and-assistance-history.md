# ADR 0019 — Beneficiary enrolment and assistance history

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 14
* Supersedes: nothing. Extends ADR 0013 (canonical resident), ADR 0014 (households),
  ADR 0016 (case engine), ADR 0018 (programmes and guidance).

---

## Context

TABs 11–13 built the case engine, intake and the programme catalogue. A case can now be
approved. Nothing yet records the consequence: that a named person is **on a programme's roll**
and receives something because of it.

Four words in the master command name things that look like four tables:

| Word | What it actually is |
| --- | --- |
| **Resident** | the canonical person — `residents`, owned by ResidentProfile |
| **Applicant** | whoever *filed* — `assistance_intakes.submitted_by`, an **account** |
| **Beneficiary** | whoever *receives* — `program_enrollments.resident_id` |
| **Enrollee** | a person *or a household* on a roll — the same row, with `household_id` set |

They are four roles of one person, not four records of four people.

---

## 1. There is no `beneficiaries` table

**Decision: a beneficiary is a canonical resident on a roll. No second person row, anywhere.**

The acceptance criterion asks for "one resident can have many enrollments without duplicate
resident records", and the shortest way to fail it is to create a table that looks helpful.
A `beneficiaries` table would be:

* **a second place a person exists.** It drifts from the canonical record the first time a name
  is corrected on one and not the other — and the drift is invisible, because both rows look
  internally consistent.
* **a second population for duplicate detection.** ADR 0010 and ADR 0013 built matching over
  `residents`. A parallel population means either matching runs twice over two schemas, or one
  population silently goes unmatched — and it would be the one that receives money.
* **a place for authorization to diverge.** Barangay scope is a fact about the resident. Copied
  onto a beneficiary row, it stops moving when the person does.

So `program_enrollments.resident_id` references `residents` by identifier, and the beneficiary's
name, barangay and household are read from ResidentProfile through its application service.
One resident holding four enrolments is four rows in one table and still exactly one person.

**Consequence, accepted:** every roll listing costs a resident lookup. That is the price of
having one truth about who somebody is, and it is small.

---

## 2. Enrolment is effective-dated, and exit closes rather than deletes

Like household membership (ADR 0014 §2) and vulnerability factors (ADR 0015 §1).

`effective_from` / `effective_to`, plus a status of `active`, `suspended` or `exited`.

* **Exit never deletes.** *"Was this household on the roll when the October tranche was
  released?"* has to stay answerable after they leave in November — and that is precisely the
  question asked when somebody alleges a payment went to a person who should not have had one.
  A deleted row answers it "no" and destroys the evidence in the same motion.
* **`exit_reason` is mandatory.** "Graduated", "moved out of Taytay", "no longer eligible" and
  "found to be a duplicate" are four completely different facts about a person. A boolean
  `is_active` collapses all four into one that answers none of the questions anyone asks
  afterwards — and an unexplained removal is indistinguishable from an unauthorised one.
* **`suspended` exists so that "under review" is not spelled "removed".** A household being
  checked for a double claim is neither receiving nor off the roll. Forcing that into
  exit-and-rejoin fills the roll with fragments every time somebody is queried and cleared.
* **An ended enrolment cannot be revived** (`409`). Reviving rewrites a period the beneficiary
  was genuinely off the roll, and any release made during it would retroactively look
  authorised. Rejoining is a new row.

`as_of=<date>` on the roll query answers the release-audit question directly, from the effective
dates rather than from today's status.

---

## 3. Assistance history is a projection, not a table

**Decision: no `assistance_events` table. History is composed at read time from cases,
enrolments and (from TAB 18) releases.**

An events table here would be a **third** record of things that already have two owners: the
case knows it was approved and when, the enrolment knows the roll and the period. Writing a
third row at each of those moments means three things to keep in step, and the failure mode is
a history that disagrees with the case file it describes — with no way to tell which is lying.

`AssistanceHistory` therefore projects:

* **granted cases** — the states that mean something was actually given
  (`approved`, `scheduled`, `released`, `completed`). In-flight cases are absent from history
  entirely; they are tracked through `me/cases`, and listing one under "assistance received"
  would tell somebody they have been given what they have not.
* **enrolments**, including exited ones.
* **`released_amount_centavos: null`** — present and null, deliberately. TAB 18's release ledger
  is the authority on money; this shape is designed for it to **join onto rather than replace**,
  so a client built against this payload does not change when the ledger lands, and a reader can
  see that the money is knowingly not here yet rather than forgotten.

**Consequence, accepted:** the "what did this person receive" query is a composition, not a
single index scan. At Taytay's population that is not a performance question, and TAB 25 can
add a read model behind the same service if it ever becomes one.

---

## 4. A merge must repoint welfare records — a defect this TAB found and closed

**ADR 0013 §6 established that a merge repoints every consumer of `resident_id`.** When it was
written the consumers were Credential, which listens for `ResidentMerged`, and Identity, which
the merge calls directly through `AccountLinkService::reassign()`.

Welfare arrived in TAB 11 and **nothing connected it.** A merge therefore left
`welfare_cases.resident_id` pointing at a soft-deleted resident: the applicant's own `me/cases`
would go empty while staff continued working the file, and each side would be certain it was
right. **Nothing failed loudly** — no exception, no constraint, no failing test, because every
test asserted its own module.

Closed here by `ReassignWelfareRecordsOnResidentMerge`, which moves cases, intakes, unsubmitted
drafts and enrolments to the survivor.

### Which mechanism, and why it is not a preference

* **A direct call**, where ResidentProfile already depends on the module — Identity. The merge
  invokes it inside its own transaction.
* **A `ResidentMerged` listener**, where the call would have to run back *up* the graph —
  Credential and Welfare both depend on ResidentProfile, so a direct call would make the module
  graph cyclic. The event is the inversion (ADR 0013 §6).

The dependency direction decides it. Choosing the other one does not produce a worse design; it
produces a failing `ModuleBoundaryTest`.

### The general lesson, made testable

A domain event with one listener is indistinguishable from a domain event with a missing
listener, and no test asserting a single module can see the difference. A checklist entry would
not have caught this either — Welfare was added correctly by every rule that existed.

So the rule is now a test. `ResidentMergeCoverageTest` reads the **live schema** for every table
carrying `resident_id`, maps each to its owning module, and requires that module to have one of
the two mechanisms above. It reads the schema rather than the source because a model with
`$guarded = ['id']` names none of its columns — which is exactly how this went unseen.

It proves a mechanism *exists*, not that it moves the right rows; the feature tests do that. Its
first run accused Identity, which turned out to be correctly covered by a direct call the scan
could not see, because the collaborator shares the merge service's namespace and needs no
`use` statement. That near-miss is now the test's own negative fixture.

---

## 5. The merge collapses overlapping enrolments

**THE INVARIANT: at most one *open* enrolment per programme per resident.**

Two means the same person counted twice in every roll, every distribution manifest and every
payment run. That is double payment, arriving quietly and from a direction nobody watches.

A merge is the one path that can create it without going through `enroll()`: if both duplicate
records were separately enrolled on the same programme, moving both leaves the survivor holding
two. So the merge **closes the absorbed one as it moves it**, with
`exit_reason: merged-duplicate-enrolment` — the survivor is already on that roll, and the closed
row remains as the history of a real enrolment that really existed.

Enrolments on *different* programmes are not duplicates and are carried across untouched.

### The unique key, and the one that was wrong first

The invariant wants a **partial** unique index, which PostgreSQL has and SQLite does not, so the
first attempt keyed on `(program_id, resident_id, effective_from)`. That is wrong in **two
directions at once**:

* it **permits** unlimited open enrolments as long as the start dates differ — the actual defect
  it was meant to prevent;
* it **forbids** two legitimate same-day rows — breaking both a same-day correction (enrol the
  wrong person, exit, enrol the right one, all within a minute) and the merge collapse, where two
  records really were separately enrolled on one day and one must survive as closed history.

Replaced with a derived `open_key` column: `program_id` while the enrolment is open, `NULL` once
it closes, unique on `(resident_id, open_key)`. PostgreSQL and SQLite both treat NULLs in a
unique index as **distinct**, so closed rows never collide however many accumulate, while a
second open row on one programme collides immediately. The invariant is stated portably, in the
database, in one line.

It is maintained by a model `saving` hook rather than by each call site, because a column that
carries an invariant is only worth having if it cannot be forgotten — and the write that would
forget it is the merge collapse, the one path that does not go through `enroll()`.

**Enforced in two places on purpose.** The service returns the existing row rather than opening a
second, so a double-tapped "enrol" is harmless; the index refuses the second open row outright,
so a write path added in TAB 22 that forgets to ask cannot create one either. The service is the
good manners; the index is the guarantee.

---

## 6. Enrolment is a human decision

**Nothing in this module reads a guidance outcome, an assessment recommendation or a
vulnerability score.**

The chain of deliberately-not-automatic steps now runs end to end: guidance advises
(ADR 0018), an assessment recommends (ADR 0017), a case is approved by someone who did not
endorse it (ADR 0016 §6), and only then does a holder of `enrollment.manage` put a name on a
roll — recorded against theirs.

Gap **G-20** (the vulnerability weights are an unapproved placeholder) stays non-consequential
here for the same reason it did in TABs 11–13: there is no path from a score to a roll. A change
to `config/vulnerability.php` cannot enrol, suspend or remove anybody.

Two permissions, split on purpose:

* **`enrollment.view`** — front-line staff, who answer "am I enrolled?" at the counter;
* **`enrollment.manage`** — `lgu_admin` only. Putting a name on a roll is money-adjacent.

Barangay scope is applied through the **source case**, because an enrolment has no barangay of
its own and denormalising the beneficiary's would be a second copy that stops moving when they
do (ADR 0008 §10). An enrolment with **no** source case — a bulk or legacy import — is visible
only to an unrestricted actor: it carries no barangay evidence, and guessing one would be worse
than admitting there is none.

---

## 7. The citizen view

`GET /api/v1/me/assistance-history` returns programme reference, type, date and outcome, and is
built **additively** like every other citizen projection (ADR 0016 §5): a field is absent until
somebody decides it belongs. No case worker, no internal reason, no assessment, no barangay, no
priority, no programme id.

The resident is resolved from the token. There is no identifier in the contract to tamper with,
which is what stops a history endpoint becoming an enumeration endpoint.

---

## Consequences

* One resident, many enrolments, no duplicate person record — the acceptance criterion, held
  structurally rather than by convention.
* Historical enrolments stay queryable after exit, with a stated reason and a named actor.
* A merge no longer strands welfare records, and cannot produce a double-payment roll.
* Money is not modelled here. TAB 18 owns the release ledger and joins onto this projection.
* Every module added from now on must answer a merge, and `ResidentMergeCoverageTest` fails the
  build if one does not.
