# ADR 0023 — Release and distribution tracking

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 18
* Extends: ADR 0016 (case engine), ADR 0017 (idempotency), ADR 0019 (assistance history)

---

## Context

This is the TAB where money leaves the building. Three acceptance criteria: a retry cannot create
a duplicate release; a released record traces to an approved case, programme and beneficiary; and
money is stored at fixed precision.

**The boundary the master command draws is worth restating first: this is social welfare
operational tracking, not a treasury ledger.** There are no journal entries here, no double-entry,
no account codes, no bank posting and no reconciliation state. The question these tables answer is
*did this family actually receive what was approved for them, and who handed it over*. What the
municipality's books say is the treasury's, and inventing a shadow ledger here would produce two
sets of figures that disagree — with this one having no auditor.

---

## 1. Money is integer centavos — a conflict, resolved and recorded

**The master command asks for "fixed-precision decimal columns". The constitution (Article 4) and
`docs/api/conventions.md` §6 require integer minor units. This implements integer centavos.**

Raised rather than silently picked, per CLAUDE.md's own instruction:

* **Both satisfy the actual requirement.** The prohibition both documents care about is floating
  point, and a scaled integer is exact in precisely the way `DECIMAL` is. "Fixed-precision" is
  true of centavos.
* **The constitution outranks the task instruction**, and says so in its first paragraph.
* **Everything already uses centavos**: `residents.monthly_income_centavos`, the Angular client's
  `Money` type, and the gap list's own "not a gap — already aligned" entry recording that both
  sides agree on integer centavos.
* **TAB 14 already published `released_amount_centavos` as a contract**, deliberately present and
  null so this TAB could fill it without a client change. Switching to a decimal now would break
  that promise for no gain.

An explicit `currency` column accompanies every amount, which both documents ask for and which
matters more than it looks: an amount with no currency is the field every integration eventually
guesses at.

**In-kind releases carry no amount at all.** A relief pack has a notional value, and recording it
would put a peso figure against a family that received rice — which then appears in every total as
though cash had been handed over. The manifest total sums cash only.

---

## 2. Three controls, guarding three different failures

The one operation that moves money carries all three, and none substitutes for another.

| Control | Guards | Why the others do not cover it |
| --- | --- | --- |
| **`Idempotency-Key` on confirmation** | a retry over a weak connection | A retry is one client, one intent, two requests. A lock does not help — each request is its own transaction, and a rolled-back first attempt leaves the second finding `ready`. |
| **Row lock + status re-check inside the transaction** | two staff at two tables at one distribution | Two clients hold two different keys, so idempotency sees two legitimate operations. Both load the record showing `ready`; both click. |
| **Approver ≠ releaser, checked on the person** | deliberate misuse | Neither of the above cares who is acting. |

A payout table has a weak connection and a queue behind it. That is not an edge case; it is the
normal operating condition, and it is why the key is on this endpoint rather than merely available.

**One release per instalment per case**, as a unique key on `(welfare_case_id, sequence)`. It does
not prevent a genuine schedule — that is sequence 1, 2, 3, assigned inside a lock on the case — but
it does prevent two rows claiming to be the same instalment, which is the shape a double payment
takes.

---

## 3. Segregation of duties, and the role that makes it operable

**Decision: a new `disbursing_officer` role holds `request.release` and approves nothing. The
approver of a case may not release its money, checked against a snapshot.**

Until this TAB **nobody held `request.release` at all**. That was deliberate and recorded in the
contract matrix — `lgu_admin` holds approval and not release, so no single non-administrator role
could do both. It was also correct while there was nothing to release.

Leaving it unheld now would make the feature inoperable, and the obvious shortcut — granting
release to `lgu_admin` — would collapse the split this system has maintained since TAB 11. So the
role exists, which is exactly what the master command asks for: *"permission hooks should allow
recommendation/approval and release confirmation to be assigned to different roles"*.

`disbursing_officer` holds `request.view`, `request.release`, `request.schedule`,
`enrollment.view`, `program.view`, `resident.view` — enough to see what was approved and confirm
the person in front of them is on the roll — and deliberately **not** `request.approve`,
`request.endorse`, `request.assess`, `resident.manage` or `enrollment.manage`. A disbursing
officer who could also approve, or put a name on a roll, would be a single signature between an
empty case file and money leaving the building.

**And the check is on the *person*, not only on the permission.** Two roles is the design; one
person holding both is the failure, and it arrives the moment somebody is granted a second role to
cover a colleague's leave. `releases.approved_by` is snapshotted at preparation and compared at
confirmation — so a later change on the case cannot retroactively make a past release compliant or
non-compliant.

---

## 4. `Released` and `Completed` are different claims

Ready → Released → Completed, with Failed, Deferred and Cancelled as secondary outcomes.

`Released` means the office handed it over. `Completed` means the handover is acknowledged and the
record is closed. Between them sits the real case: a cheque given to a relative who has not yet
confirmed, a bank transfer sent but not landed. Collapsing them would make "we paid them" and
"they have it" the same claim, and only one of those is ever true first.

**A released record cannot be rewound.** Money has moved; a release sent in error is completed and
then corrected by a new record, never un-moved by a status change. A `failed` or `deferred` release
returns to `ready`, because the family is still owed what was approved for them.

**Every outcome that is not the happy path must say why.** A failed release with no reason is
indistinguishable from one nobody attempted, and the family is owed an answer either way.

Transitions are their own append-only table. Money is the one place where "what happened to this
record" must be reconstructable without inference, so movements are rows rather than a status
column and a hope.

---

## 5. Acknowledgement records who actually took it — and no biometric

`acknowledged_by_name` and `acknowledged_relationship` exist because the collector is frequently
not the beneficiary: an elderly person sends a daughter, a bedridden patient sends a neighbour.
Recording only "released" loses the one fact a dispute turns on.

**`acknowledgement_method` records that a signature or thumbmark was taken; the mark itself stays
on the paper manifest.** A thumbprint image in this database would be biometric data held for a
purpose that does not need it (RA 10173, Article 5.2) — and held in a table that a distribution
clerk can reach.

---

## 6. Batches are manifests, and the ordering is deliberate

A distribution run is a hall, a table, a queue of families and one manifest. It exists because
releases genuinely happen that way, and because "who was on the list that day" is the question
asked when somebody says they were missed.

**The manifest is ordered by reference number, not by name.** Two copies printed an hour apart then
match line for line, which is what makes a paper manifest checkable against a screen at a table
with a queue in front of it. Alphabetical order changes when a name is corrected.

---

## 7. TAB 14's placeholder is now filled

`AssistanceHistory` published `released_amount_centavos: null` deliberately, so this TAB could fill
it without a client change. It is now summed from releases that **actually happened** —
`released` or `completed`, cash only — and not from what was approved.

That distinction is the whole point: a case approved for ₱5,000 whose payout failed has received
nothing, and reporting the approved figure would tell a family they were given money they never
saw.

---

## Consequences

* A retry, a race and a conflict of interest are each blocked by a different mechanism.
* Every peso released traces to an approved case, a named approver who did not release it, and a
  named releaser.
* This system still cannot tell anybody what the municipality's books say, and must not learn to.
* `funding_source` is a label. If it ever needs to be a chart-of-accounts reference, that is a
  different system and a new ADR.
* A second `Idempotency-Key` caller now exists, which closes gap **G-15** — TAB 12 settled the
  intake half and left the money half open for exactly this.
