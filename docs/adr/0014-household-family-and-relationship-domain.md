# ADR 0014 — Households, families, effective-dated membership and kinship

* **Status:** accepted
* **Date:** 2026-08-16
* **Extends:** [ADR 0013](0013-canonical-resident-registry-and-account-linking.md)
* **Context:** TAB 09 — Household, Family & Relationship Domain

---

## Context

Assistance is rarely delivered to an individual. Relief goods go to a household; conditional
cash grants go to a family; a referral for a child is made through whoever is responsible for
them. So the registry needs to record who lives with whom, who belongs to whom, and who
answers for whom — three different questions that a naive model collapses into one.

Two properties make this harder than a join table:

* **These facts change constantly** — and the old value stays relevant. A distribution made in
  October was made to whoever lived there in October.
* **They are personal data about several people at once.** A household record is
  simultaneously a record about every member, which makes "who may read it" a different
  question from every other endpoint in this system.

---

## Decisions

### 1. A household is not a family, and both are first-class

`households` and `families` are separate tables, with `families.household_id` a real foreign
key inside the module.

**Why not one table with a type column.** Philippine households routinely contain several
families — a married couple, a widowed parent, a sibling's children. Relief is distributed
per household and 4Ps grants per family, so the two counts must both be correct and they are
not the same number. A model that cannot express "three families at this address" makes one
of those counts permanently wrong, and which one depends on the programme.

**Rejected:** a `family_number` column on the membership row. It expresses grouping but gives
a family no identity, so a family cannot have a head, a verification state or a code — and
every one of those is on the DSWD form.

### 2. Membership is effective-dated; there is no current-state column

`household_memberships` and `family_memberships` carry `effective_from` / `effective_to`. An
open row means "lives here now". A move closes one row and opens another; nothing is edited
or deleted.

**Rejected:** `residents.household_id`. It answers "where do they live" with today's answer,
which is the wrong answer for every audit, appeal and duplicate-claim investigation. The
question actually asked is "who was living there when this was released", and a mutable
column cannot answer it at all.

**There is deliberately no `member_count` column.** A stored count is a cache that drifts the
first time a membership is closed by a path that forgets to decrement it, and the drift is
invisible because nothing compares the two. Count is derived from open memberships at read
time (ADR 0008 §10).

Two invariants are enforced in `HouseholdMembershipService` rather than in the schema,
because neither is expressible as a portable constraint — the first needs a partial unique
index, the second spans tables:

1. **At most one open household membership per resident.** A person cannot live in two places
   at once, and allowing it double-counts them in every household distribution.
2. **A family membership requires a current membership of that family's household.** Left
   unenforced, somebody keeps drawing a family grant from an address they left.

Adding a member who already belongs elsewhere is **refused**, not silently transferred. A
move has a date and a reason; performing one as a side effect of "add member" loses both, and
removes the person from a household somebody else is still counting.

Transfer is therefore **one endpoint and one transaction**. Exposing only remove-and-add would
let a client perform half a move and leave a real person belonging to no household —
invisible to every household-based distribution until somebody noticed.

### 3. Headship is a reporting fact, not an authority

`head_resident_id` is nullable and must reference a current member.

Nullable because a household whose head has died still exists and still receives assistance;
forcing a replacement makes staff invent one, and an invented head is a person the LGU then
addresses letters to. Removing the head clears the field rather than leaving a dangling
reference to somebody who has moved out.

**A head has no read access to their household's records.** Headship is a named contact for
DSWD forms. A head who could open the other members' files would be a privacy hole shaped
like a family — the abuser in an abusive household is very often its recorded head.

### 4. One directed relationship row; the inverse is derived

`resident_relationships` stores "A is the *type* of B" exactly once.
`RelationshipType::inverse()` produces the other view on read.

**Why not store both directions.** Two rows for one fact disagree the moment either is
edited, and there is no principled rule for deciding which is then true. In practice staff
record the relationship from whichever screen they are on, so both halves get written with
different effective dates and neither can be trusted.

Having a defined inverse is also what makes duplicate prevention possible: before writing
`A parent-of B`, the service checks for `B child-of A`. Without it there is nothing to check
against.

The schema prevents self-relations and exact duplicates. It does **not** try to validate that
a family structure is possible — no check that a parent is older than their child, that
nobody has three spouses, that a guardian is an adult. Real households include informal
adoptions, absent parents recorded years later, and grandparents raising grandchildren under
arrangements no schema anticipates. A system that enforced a tidy model would start refusing
real families, and the reliable staff response to that is to record something false that the
system does accept.

Ending a relationship sets `effective_to`; it never deletes. A separation and "this never
happened" are different claims, and deleting the row would break every assistance decision
made on the strength of the relationship.

### 5. The citizen household view is minimised, and care responsibility is the only exception

`GET /api/v1/me/household` returns the address, who else lives there **by name**, and the
caller's own relationship to each.

Withheld from every co-member: verification tier, sector tags, income, contact details,
correction history, anything about assistance. Also withheld about the home itself:
verification status, profile completeness, dwelling type, tenure and utilities — those are
the LGU's field assessment, and a family that learns from an API payload that it has been
recorded as "makeshift" or "rejected" has been handed a judgement nobody can explain to them.

**Sharing a roof is not consent to be looked up.** A boarder must not be able to learn that
the landlady is a VAWC survivor, or that the man in the next room has a request pending.

The single exception: for members the caller is recorded as responsible for — child, ward, or
someone they provide for — birth date and verification tier are included, because a parent
legitimately needs to know whether their child's onboarding is finished. The gate is
`RelationshipType::impliesCareResponsibility()`, resolved server-side from recorded kinship.
It is **never inferred from co-residence**: living with a child is not the same as being their
parent, and inferring it would hand every adult in a house the details of every child in it.

Family membership is shown only for the caller's own family. That a household contains three
units is a fact about their home; which co-resident is in which is a fact about those people.

### 6. Reads use `resident.view`; writes use a new `household.manage`

Household reads are **not** given their own view permission. A household is a group of
residents and opening one reveals their data, so a separate "household viewer" permission
would be a way to enumerate residents without holding the permission that guards them.

Writing gets `household.manage`, held by `lgu_staff` and `lgu_admin`. Composition changes
constantly and is recorded at the counter and in the field; withholding it from front-line
staff would push the work to an admin who was not there.

---

## Consequences

**Good.**

* "Who lived here in October" is answerable in November, which is what makes a distribution
  auditable and a duplicate-claim investigation possible.
* Household and family counts are independently correct, so per-household and per-family
  programmes can both be run from this registry.
* A transfer cannot leave a resident belonging to nothing.
* The citizen view has an explicit, tested boundary rather than an implicit one.

**Costs, accepted.**

* Membership queries always carry a `WHERE effective_to IS NULL`. Indexed on
  `(resident_id, effective_to)` and `(household_id, effective_to)`, and the cost is a
  predicate rather than a join.
* Member count is computed per household row rendered. Fine at page sizes capped at 100; if a
  dashboard later needs municipality-wide counts, that is an aggregate query in TAB 21, not a
  cached column here.
* Two invariants live in a service rather than the schema and are therefore only as strong as
  the rule that all writes go through it — the same bargain ADR 0013 §1 made, and tested the
  same way.

**Open.**

* **Household-level vulnerability factors** (TAB 10) will need a household-scoped read that
  respects the same minimisation rule as §5. The care-responsibility gate is deliberately
  published on `RelationshipType` so that TAB 10 uses it rather than inventing a second one.
* **Household merge** is not built. Duplicate households are a real possibility once bulk
  import arrives (TAB 35); the resident-merge machinery in ADR 0013 §3 is the model to copy,
  and it should be its own decision rather than an afterthought here.
* `families.verification_status` exists but nothing moves it yet — field verification of a
  family unit belongs to the field-visit workflow in TAB 17.
