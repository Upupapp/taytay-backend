# Audit coverage — the written list

TAB 14 step 9: *"Verify audit coverage against a written list: every read of sensitive data, every
mutation, every export, every document open, every permission change, every sign-in and failure.
A gap found after an incident is a gap that cannot be filled retrospectively."*

This is that list. It exists so the question *"is this act audited?"* has an answer somebody can
check, rather than being settled by grepping for a writer call and hoping it fires.

`AuditAssuranceTest::every_act_on_the_written_list_leaves_an_entry` **exercises acts and looks for
their entries** rather than asserting a writer was called — a writer invoked with the wrong action,
or inside a transaction that later rolls back, satisfies a mock and leaves the trail empty.

## What the trail records, and what it refuses to

Every entry carries: **who** (`actor_subject_id`, `actor_label`, `account_type`), **when**
(`occurred_at`), **what** (`action`, `entity_type`, `entity_id`), a **summary in words**, the
**request id** for correlation, the **client channel**, and — where a change occurred — the
**names of the columns that moved** and a **reason**.

It records **no values**. `AuditTrail` has no parameter for an old or new one, and that is the
whole design: *"a trail that duplicates the data it protects is a second, less-guarded copy of
it."* It is read by operators investigating something else, retained longer than most records, and
exported for compliance review.

**This is why there is no `audit.view-detail` tier here.** The console's `DL-114` describes two
tiers — a row list, and recorded values behind a second permission — and TAB 14 step 7 asks for the
same split. This API achieves it more strongly than a permission can: reading a row tells you a
record changed and which columns moved, and **nowhere in the system will tell you what it changed
to**. Recorded as G-33 so nobody wires a console tier against a field that does not exist.

## The list

| Act | Recorded | Notes |
| --- | --- | --- |
| **Sensitive reads** | | |
| Reading a resident record | `resident.viewed` | The read itself, not only changes to it |
| Opening a case | `case.viewed` | |
| Opening a document | `document.read` | Per version, with the grant that permitted it |
| Searching the registry | `search.performed` | **With the term.** Added TAB 11 — see below |
| Searching the trail | `audit.searched` | What was asked for, never the answer |
| **Mutations** | | |
| Every create, update and lifecycle move | per-module actions | Column names and a reason; never values |
| Release movements | `release_transitions` + trail | Every peso, with actor, amount and reason |
| **Exports** | | |
| Requesting an export | recorded with requester and scope | |
| Downloading one | `report.export-downloaded` | Re-authorised at download, not at request |
| Downloading a person-level one | `report.person-level-export-downloaded` | Distinct action: the higher-consequence act is separately findable |
| Running a report | `report.run` | Nothing written, no name returned, still recorded: *who asked which question of the welfare registry* is the audit interest |
| **Identity** | | |
| Failed sign-in | `identity.sign-in-failed` | The one that matters most — a run against one account is the only signal the office gets |
| Blocked sign-in | `identity.sign-in-blocked` | |
| Account locked | `identity.account-locked` | |
| Permission and role changes | AccessControl module actions | |

### Why the search term is in the trail

A search term on a welfare registry is frequently somebody's name, and `AuditTrail` exists to
refuse copying protected data into the trail. It is recorded anyway, because the question an audit
of this system must answer is **who has been looking up whom**, and a trail saying somebody
searched four hundred times is not accountability.

What makes it safe is who reads it: `audit.view` is held by the **Data Protection Officer alone**,
withheld from `lgu_admin` because the auditee must not be the auditor. And the doctrine is not
bent — it forbids copying a *record's contents*; a search term is the actor's own input, which is
the act being audited.

The log cannot be mined through the surface that writes it: `GlobalSearch` covers residents, cases,
households and referrals, and never `audit_entries`.

## Append-only, proven by attempting it

`AuditIsAppendOnlyTest` proves no application code edits or deletes an entry, by reading the
source — a claim about the code that exists. `AuditAssuranceTest` proves the attempt is **refused**
— a claim about the code that can exist. Mass assignment, direct assignment, `forceFill`, `delete`,
and every HTTP verb against the entry route.

The one legitimate deletion — disposal under an approved retention schedule — is deliberately not
exempted, because `RetentionPolicy::mayPurge()` refuses everything until the DPO approves one. The
escape hatch is added **in the same change as the approval**: a purge path that already exists is a
purge path somebody can reach without one.

## Known gaps

* **Nobody can read any of this.** `audit.view` sits only with `data_protection_officer`, and the
  role is unfilled. The trail is being written and cannot be read — TAB 14's blocker 1, and an
  appointment rather than an engineering task.
* Coverage above is verified for the acts the test exercises. Extending the test as endpoints are
  added is the maintenance this document asks for; a row here with no test is a claim.
