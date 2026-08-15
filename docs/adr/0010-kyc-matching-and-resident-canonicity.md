# ADR 0010 — Deterministic matching with human adjudication; no silent resident creation

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 06)
* Relates to: ADR 0009 (accounts ≠ residents), ADR 0008 (schema), ADR 0002 (authorization)

## Context

The LGU needs one record per resident. It will get registrations from people who spell
their own name three ways across three documents, who share a name and a birthday with a
cousin, whose surname is recorded as both "Peña" and "Pena", and who re-register because
they forgot they already had.

Two failure modes, with very different costs:

* **A duplicate resident** — the same person appears twice, gets assessed twice, and the
  LGU cannot see their total assistance. Recoverable, tediously, by merging.
* **A wrong merge** — two people collapsed into one record. One collects the other's
  assistance; the other becomes invisible to the LGU. Close to **unrecoverable**, because
  the evidence that they were two people has already been destroyed.

The second is worse, and a similarity score with a threshold produces it silently.

## Decision

### 1. Three separate things

`account` (Identity) → `kyc_case` (a claim being checked) → `resident` (the canonical
record). Registration creates the first two. **Only a human reviewer creates or links the
third.**

`kyc_cases.claimed_*` holds what the applicant asserted. It is never written into
`residents` until a reviewer accepts it — writing an unverified assertion into the
canonical record is how it quietly becomes official data.

### 2. A lifecycle with no automatic exit

```
draft → submitted → screening → manual-review → approved | rejected
                         ↘ needs-more-information ↗
```

`screening` **cannot reach `approved`.** Its only forward transitions are to
`manual-review`, `needs-more-information` or `rejected`. Even zero candidates goes to a
person, because "no existing resident matches" is precisely the moment a new canonical
record would be created — the highest-consequence decision in the flow.

Every move is validated against the transition table and recorded as an append-only row.

### 3. Deterministic candidates, human decisions

Matching produces candidates with the **rule** that found them and a coarse band
(`exact` / `strong` / `partial`). It never links, merges or approves.

| Rule | Band |
| --- | --- |
| normalised given + family name + birth date | `exact` |
| family name + birth date | `strong` |
| given + family name, any birth date | `partial` |

Normalisation folds case, accents, punctuation and repeated whitespace, so "Peña" and
"PENA " collide as they should. **Middle name is excluded**: it is inconsistently recorded
across Philippine documents — sometimes the mother's maiden surname, sometimes absent,
sometimes an initial — so including it would split one person across several fingerprints
and defeat the matching it was meant to enable.

The fingerprint is stored as a **hash**, not the normalised string: an index of everyone's
name and birth date is a thing that turns up in dumps, replicas and `EXPLAIN` output.

Two guards make the human decision real rather than ceremonial:

* a case with **any undecided candidate cannot be approved**; and
* a reviewer who marked a candidate `same-person` **cannot then create a new resident**,
  and cannot link a resident they never confirmed.

**No confidence score is exposed as a number.** "0.87" invites a reviewer to read it as
certainty and click through; "exact" and "partial" invite them to look.

### 4. Verification tiers

`unverified` → `partially-verified` → `verified`. The tier only ever rises through a human
decision; nothing automatic promotes anyone.

Deliberately **not** the same as Identity's `email_verified` / `mobile_verified`, which
prove control of a contact channel. Being able to receive an SMS is not evidence of who
you are, and conflating the two is how an unverified person ends up holding a verified
record.

Partial verification is enough to **receive assistance** — the LGU must not make help
conditional on paperwork a person cannot produce. Full verification is required only to be
issued an identity document (ADR 0011).

### 5. Minimised, retained, and never biometric

* **No biometric templates.** Not off-by-config — *absent from the schema*. Biometric data
  is irrevocable: a leaked password is changed, a leaked face is not. Under RA 10173 it is
  sensitive personal information, and document review by a clerk already achieves the
  outcome without the LGU holding a template it must protect forever. Enabling it would
  need its own ADR and a privacy impact assessment, and even then must store a
  verification *result*, never a template.
* **No full PhilSys number** (RA 11055). Last four digits only, and encrypted even so.
* **No extracted document numbers.** The reviewer reads the image; storing the number
  creates a second copy of a government identifier for no operational gain.
* **Documents are pointers.** The image lives on the private `object-storage` disk; the row
  holds a path and a hash. Scans in the database would be in every backup and replica.
* **Retention is bounded.** `purge_after` is set at submission; purging clears the objects
  and keeps the case row, so the decision stays auditable after the evidence is destroyed.
  180 days is a working default, not a legal finding — the LGU's records officer should
  confirm it against their retention schedule.

## Consequences

* Positive: no path produces a verified resident without a named human decision, and the
  audit trail says who.
* Positive: a wrong merge requires a reviewer to have affirmatively said "same person",
  which is a reviewable act rather than a silent threshold.
* Positive: the registry is protected from the commonest duplication causes — spelling,
  accents, re-registration.
* Negative: **every registration needs human review**, including the easy ones. That is
  real staff cost, and it is the deliberate price of the guarantee. If the queue becomes
  unworkable, the answer is to make review faster (better candidate presentation, bulk
  handling of obvious cases), not to add an auto-approve threshold.
* Negative: deterministic rules miss real duplicates that fuzzy matching would catch —
  transposed digits in a birth date, a legally changed surname. Those become duplicates a
  staff member finds later, which is the recoverable failure rather than the unrecoverable
  one. A fuzzy *suggestion* layer could be added to the reviewer's screen without changing
  this decision.
* Negative: one open case per account is an application invariant inside a transaction, not
  a unique index — the key would need a nullable "closed" marker and PostgreSQL treats
  NULLs as distinct (ADR 0008 §5), so the constraint would permit exactly what it forbids.

## Alternatives rejected

* **Probabilistic matching with an auto-merge threshold.** Rejected: it produces the
  unrecoverable failure silently, and no threshold is defensible to a resident who was
  merged into somebody else.
* **Auto-approving a single exact match.** Tempting, and rejected: "exact" here means the
  same normalised name and birth date, which siblings, cousins and namesakes genuinely
  share. It would auto-merge precisely the people hardest to tell apart.
* **Creating a resident at registration and reconciling later.** Rejected: it inverts the
  guarantee — the duplicate exists first and cleanup is best-effort.
* **Storing a face template for later automated matching.** Rejected outright, see §5.

## Sources

* Republic Act No. 10173 (Data Privacy Act of 2012) — proportionality, storage limitation,
  and sensitive personal information: <https://privacy.gov.ph/data-privacy-act/>
* Republic Act No. 11055 (PhilSys Act) — handling of the PhilSys Number:
  <https://www.officialgazette.gov.ph/downloads/2018/08aug/20180806-RA-11055-RRD.pdf>
* NIST SP 800-63A — identity proofing and evidence validation:
  <https://pages.nist.gov/800-63-3/sp800-63a.html>
* Philippine naming and civil registry practice (PSA civil registration forms) for the
  middle-name and suffix handling in §3.
* Prior repository evidence: `Taytay_Rizal_Social_Welfare_Angular`
  `docs/reference-audit/decision-log.md` DL-12, which identified resident verification
  state as an open gap.
