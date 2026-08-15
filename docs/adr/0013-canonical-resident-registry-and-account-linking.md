# ADR 0013 — Canonical resident registry, correction, merge and account linking

* **Status:** accepted
* **Date:** 2026-08-16
* **Supersedes:** nothing. Extends [ADR 0010](0010-kyc-matching-and-resident-canonicity.md).
* **Context:** TAB 08 — Canonical Resident Registry & Account Linking

---

## Context

ADR 0010 established that a resident record is created only by a reviewer approving a KYC
case, and that matching suggests while humans decide. That covered how a resident record is
*born*. It said nothing about the rest of its life:

* how a field gets corrected, and by whom;
* what happens when two canonical rows turn out to be the same person anyway;
* which account is allowed to act for a resident, and who decided that.

Those three questions share one property that shapes every decision below: **the failure
modes are silent.** A registry with no change history looks identical to one with a
complete history until somebody disputes a benefit. A merge that strands a credential looks
successful until a card is scanned. An account quietly repointed at the wrong resident looks
like nothing at all until a citizen opens somebody else's welfare file.

An audit of the code as TAB 08 opened also found a concrete instance of exactly that class:
`AccountDirectory::linkResident()` existed with **no caller**. KYC approval recorded
`kyc_cases.resolved_resident_id` and left `accounts.resident_id` null, so a citizen who had
just been verified would have been told no record was linked to them. The foundation was
there; nothing used it.

---

## Decisions

### 1. Every write to `residents` outside KYC approval goes through one service

`ResidentRegistry` is the only path. It writes a `resident_status_events` row for each
field that moves, carrying the previous and new value.

**Why not just use the audit log.** `audit_entries` records *that* something happened, in
one sentence, deliberately without the data (Article 5.5). That is correct for
accountability and useless for operations: a clerk repairing a mis-typed birth date needs
the previous value, not a note that a value changed. The two coexist — the audit trail says
who and when for investigators, the status events say what for operators.

**Rejected:** letting controllers call `$resident->update()`. A field changed that way leaves
no history, no alias and a stale matching fingerprint, and the damage surfaces months later
as a duplicate the registry could no longer detect.

### 2. Changing an identity field re-keys the fingerprint and preserves the old name

`identity_fingerprint` is derived from first name, last name and birth date. Correcting any
of those rebuilds it, and the superseded name is written to `resident_aliases`.

Both halves matter, for opposite reasons. Without the rebuild, duplicate detection goes
blind to the corrected record — **silently**, because a stale hash still matches itself.
Without the alias, search forgets the old name, so the clerk holding a three-year-old paper
form finds nothing, concludes the resident is not enrolled, and creates the duplicate this
whole design exists to prevent.

### 3. A merge is gated, transactional, and never destructive

Merging is the most dangerous operation in this system. When two rows really are one person
it repairs a registry that was quietly paying or refusing benefits twice. When they are not,
it makes one resident disappear and hands their assistance history to a stranger — and it
does so by destroying the evidence that they were ever two people.

That asymmetry gives the rules:

* **Detection proposes; a reviewer decides.** `resident_duplicate_pairs` holds the question.
  Deciding `same-person` does not merge — it only unlocks the merge call. Choosing which row
  survives is a second, separate judgement.
* **A pair is stored once.** Primary keys are normalised (smaller id first) under a unique
  key, so (A,B) and (B,A) cannot become two rows that two reviewers answer differently.
* **The merge call refuses any pair not confirmed as the same person**, and refuses a
  survivor that is not one of the pair's two members. Without the second check the review is
  decorative: confirm a harmless pair, then merge two unrelated people.
* **One transaction.** Accounts, credentials, KYC cases, sectors, corrections and match
  candidates all move or none do. A half-merge leaves credentials verifying against a
  soft-deleted record.
* **The absorbed row is soft-deleted, never destroyed**, and is deactivated first so a
  `withTrashed()` reader sees an unmistakably dead record.
* **Rows are locked in primary-key order.** Two reviewers merging the same pair from
  opposite directions would otherwise deadlock.

`resident.merge` is its own permission, held by `lgu_admin` and no other role.

### 4. Correction has two doors, and the field decides which

`CorrectableField` classifies every changeable field once, and both the citizen and staff
endpoints derive their rules from it.

* **Self-service** — street address, purok, mobile, email. Applied immediately. A resident
  who moves must be able to say so at 11pm without an appointment; if that is hard, the
  LGU's contact details rot and it loses the ability to reach the people it is trying to
  help.
* **Reviewed** — name, birth date, sex, civil status, barangay. Proposed only. These are the
  fields a reviewer checked against documents and precisely what a fraudulent claim would
  rewrite.

`barangay_id` is reviewed rather than self-service even though it is part of an address:
barangay drives staff scope, so a resident who could move themselves between barangays could
choose which office is able to see their file.

Absent from both tiers: `verification_tier`, `is_active`, `identity_fingerprint`,
`philsys_last_four`, `monthly_income_centavos`. Tier and active state are outcomes of a
review, not inputs to it. Income is means-testing evidence and belongs to the assistance
workflow under its own permission.

**Unknown fields are refused, not dropped.** Laravel's validator silently discards keys it
was not told about, which would have let `{"changes":{"verification_tier":"verified"}}` pass
validation, apply nothing, and answer `201` — teaching a client that self-promotion had
worked. An explicit closure rejects anything outside the catalog.

This is how RA 10173 §16(d) — the right to have inaccurate personal data corrected — is
exercised without also becoming a way to rewrite a verified identity.

### 5. An account-to-resident link is a reviewable record, not a column

`accounts.resident_id` remains Identity's fast current answer. `account_resident_links` is
the history behind it: origin, who linked, when, and — after revocation — that the link ever
existed. Both are written together, in one transaction, by `AccountLinkService` and nothing
else.

**Why the column alone is not enough.** A column is mutable and remembers nothing. Repoint
it and every trace that the account was attached to somebody else is gone. "Who gave this
account access to that person's file" is the first question asked after a privacy complaint,
and a mutable column cannot answer it.

Rules:

* An account may act for exactly one resident. Linking one that is already linked returns
  `409` rather than silently repointing it — reassignment must be a decision somebody made.
* A staff account can never be linked. An employee sign-in doubling as a resident identity
  destroys the audit trail's ability to distinguish "the resident updated their address"
  from "an employee updated it for them".
* Revocation marks the row and detaches the account. The account itself survives: it is a
  way to authenticate, not a person, and deleting it over a clerical error would destroy a
  real human being's sign-in history.
* **A link grants nothing.** Authorization remains a separate decision (ADR 0002).

### 6. Cross-module reassignment goes through published seams

A merge must repoint accounts and credentials, which ResidentProfile does not own.
`AccountDirectory::reassignResident()` and `CredentialDirectory::reassignResident()` are
narrow published methods for exactly that.

The alternative — a cross-module `UPDATE` — would have been three lines and would have
welded the modules together at the schema level, making the boundary in ADR 0001
unenforceable and every later refactor unsafe.

Credential status is deliberately untouched by a merge. A revoked card stays revoked. A
merge is a statement about *who the holder is*, not about whether their ID is still good;
silently reactivating a revoked credential because two rows turned out to be one person
would hand back an ID somebody had deliberately taken away.

---

## Consequences

**Good.**

* The registry's history is operational, not just evidential — a mis-merge or a bad
  correction can be reconstructed and repaired.
* Duplicate detection survives name corrections, which is when duplicates are most likely.
* Onboarding now actually links the account it verified, closing a gap that would have
  presented as "the app says I have no record" for every newly verified citizen.
* Scope is enforced on both sides of a merge, so a barangay-scoped clerk cannot move a
  resident beyond their own reach.

**Costs, accepted.**

* Seven new tables. The alternative was JSON blobs, which cannot be indexed, constrained or
  migrated (ADR 0008 §13) — "how many address corrections are pending in Muzon" has to stay
  an indexed query.
* A merge is four API calls, not one button. Deliberate: each step is a place a reviewer can
  stop before the irreversible one.
* The duplicate queue filters scope in PHP rather than SQL, because a pair spans two
  residents and cannot be expressed as one indexed comparison. Bounded by pagination to at
  most 100 rows per page, and the response says so.

**Open.**

* Merge has no *unmerge*. The data to reverse one is retained (soft-deleted row, recorded
  counts, aliases), but the operation is not built. If field experience shows mis-merges
  happen, it becomes its own TAB rather than an afterthought here.
* Guardianship — one account acting for several residents — is anticipated by the schema
  (`account_resident_links` is a many-row table) but not implemented. The one-resident rule
  is enforced in the service, so relaxing it later is a service change, not a migration.
