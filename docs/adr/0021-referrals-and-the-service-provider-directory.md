# ADR 0021 — Referrals and the service provider directory

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 16
* Extends: ADR 0016 (case engine), ADR 0020 (files and documents)

---

## Context

A referral is **the one record in this system that leaves the building**.

Every other table describes something the MSWDO holds and can tighten later. This one describes
something handed to another organisation, after which the office no longer controls who reads it
and nothing can be taken back. That single fact shapes every decision below.

Three acceptance criteria: a referral always links to a source case/client; sensitive attachments
are not included automatically; overdue referrals can feed Tasks and Notifications.

The admin console models this domain in detail — `Referral`, `DisclosurePlan`,
`composeReferralSummary`, `ServiceProvider` — and that model is authoritative for the vocabulary.

---

## 1. The directory is a table, not a text field

`service_providers` exists because of something that shows up at a counter: "PhilHealth Rizal",
"Philhealth - Rizal" and "PHIC Rizal" are three spellings of one office. Once they exist, an
applicant cannot be told whether anybody has heard back, and a report on referral outcomes counts
one destination three ways.

It carries **what each provider actually accepts**, so a referral is not sent to an office that
does not do this work — which costs the family a trip they cannot afford and loses days nobody
gets back.

An entry cannot be set `active` while it has no channel, no service, or no way to reach it.
"Accepting referrals" with none of those is the worst state in the table: it invites a worker to
route a family somewhere and produces a referral nobody can follow up. Entries are suspended or
retired, never deleted — referrals already sent name them.

**Channels and services are child tables, not JSON columns**, and the first draft got that wrong.
ADR 0008 §13 permits JSON only for an opaque external payload, a replayed HTTP response, or
annotation nobody queries, and neither of these is any of those: the channel vocabulary is closed
and validated on the way in — exactly the kind of relationship a JSON array cannot constrain — and
"who does bill reduction" is the question the directory exists to answer, which inside a blob is a
table scan and a `LIKE` against punctuation. `DatabaseConventionsTest` caught it.

`verified_at` records that somebody re-checked the entry against reality. A directory nobody
re-checks is a list of disconnected numbers within two years, and the failure is silent: the
referral goes out, nobody answers, and the family finds out last.

**It lives in `ServiceCatalog`**, not Welfare. It is a catalogue of who provides what — the same
kind of fact as a programme — and it outlives any particular referral. Welfare asks for it by
identifier and never joins.

**Staff-only**, and not because the information is secret; most of it is on a signboard. A public
directory of "offices the MSWDO refers welfare clients to" is a map of where vulnerable people
are sent, and publishing one invites impersonation of exactly the offices families are told to
trust.

---

## 2. The destination is snapshotted, not read through

`referrals.destination_name` and `destination_type` are copied from the provider at draft time and
**never refreshed**.

A referral is a record of what was sent, to whom, on a date. If the directory entry is later
renamed or retired, the referral must still say where it actually went. This is the one place in
this codebase where a denormalised copy is *not* a cache — refreshing it would be the bug.

---

## 3. The summary is composed, not laid out

**Decision: the referral sheet is built by a function that decides what goes on it, defaulting to
the minimum.**

The minimum is the client's name, the reference number and the reason — enough for the receiving
office to know who is coming and why. Everything else is opt-in, **one field at a time, each with
a stated need**, recorded as its own row.

Why per-field rather than a "share profile" toggle: a single switch is ticked once and forgotten.
"Include everything, they can ignore what they don't need" is how a survivor's address reaches a
desk that had no reason to hold it.

Why rows rather than a JSON column: *"which referrals released a home address"* is the first
question asked after a protection incident, and a blob cannot answer it.

Two rules about absence, both load-bearing:

* **A withheld field is omitted, not blanked.** A line reading "Address: withheld" tells the
  reader there is an address worth hiding, which for a protection case is itself the disclosure.
* **A field chosen but not held is skipped, not printed empty.** An empty line invites the
  receiving office to ask for it.

The sheet is a **flat list of lines**. A structure with optional nested sections is a structure
somebody eventually renders whole.

**Producing a sheet is audited separately from sending one.** Somebody who prints a sheet and
never sends it has still produced a document holding a person's information, and that piece of
paper exists.

---

## 4. The lawful basis is a precondition, not a note

A referral cannot be **sent** without `disclosure_basis` and a note. RA 10173 requires a lawful
basis for disclosure; a referral sent without one is a disclosure nobody can justify afterwards,
and the justification cannot be reconstructed later.

Three bases, because a system offering only consent teaches staff to record consent that was never
given:

| Basis | When |
| --- | --- |
| `client-consent` | The ordinary case. |
| `statutory-mandate` | A statute or issuance requires the referral. |
| `vital-interest` | Consent could not be obtained and delay would risk serious harm. |

Each carries a **different** required fact — see `DisclosureBasis::notePrompt()`. A single
"reason" prompt gets the same sentence for all three, and a vital-interest referral whose note
reads "client agreed" is a record that contradicts its own basis.

The basis is **printed on the sheet**, because it changes what the receiving office may lawfully
do with the information: material held on a vital-interest basis is not material the client agreed
to have passed on again.

The check is re-run **inside the send transaction**, not only in a request validator. A check that
lives only at one controller is a check the next write path will not have.

---

## 5. Four permissions, split at the irreversible step

| Permission | Holder | Why separate |
| --- | --- | --- |
| `referral.view` | staff, admin | Read the queue and the directory. |
| `referral.manage` | staff, admin | Draft, record what the receiving office reports, close out. Routing a family to a hospital's medical social worker is ordinary casework and often urgent. |
| `referral.send` | **admin only** | The one irreversible act. Once the sheet is out, this office no longer controls who reads it. |
| `referral.disclose.protected` | **admin only** | Release a home address, sector membership or assistance history. |
| `provider.manage` | admin only | Maintain the directory. |

The protected-field split exists because a home address is the field an abuser needs, and sector
membership can disclose that somebody is a VAWC survivor or a child in conflict with the law.
Requiring a separate permission means releasing one is not just another checkbox on a form a
worker is moving through quickly.

### Attaching a document reuses `document.share`

Not a sixth permission — **the same one ADR 0020 §7 defined**, because it is the same act.
Attaching a document to a referral is an outward disclosure of a file. Treating it as different
because it happens on a different screen is how a control that was decided once gets undone by a
feature.

**Consequence, accepted and deliberate: referral attachments are refused today**, because nobody
holds `document.share` (gap G-26). The referral itself sends fine. This makes G-26 concrete rather
than theoretical — it now blocks a real workflow, which is the kind of thing that gets a decision
made.

Three further gates on an attachment, none redundant:

1. a mandatory reason, like every field;
2. a `sensitive` classification may not leave by any route — checked here as well as at the grant,
   because the sheet lists attachment *labels*, so a sensitive document would be **named** to the
   receiving office even if the bytes never followed;
3. an unscanned file may not be handed to another organisation.

---

## 6. What the applicant is told

The narrowest citizen projection in this system. Three things are withheld and each for its own
reason:

* **the reason** — written for a receiving office in clinical terms a family should not meet as a
  JSON field ("suspected neglect", "unable to manage own affairs");
* **the internal notes** — this office talking to itself about them;
* **the destination contact** — a named officer at another agency is a person with no relationship
  to this applicant, and handing over their number invites a call that agency never agreed to take.

What remains is a status and a fixed sentence. **The vocabulary promises nothing this office
controls**: the MSWDO cannot make another agency act, so `acknowledged` and `in-progress` both
project as "referred" — telling somebody which desk their file sits on would identify the handling
worker there.

The destination **name** does go, because an applicant genuinely needs to know they were referred
to the district hospital rather than to PESO — otherwise they cannot go.

`urgency` is never projected. It is advisory to the receiving office and operational to this one;
telling a family their referral is "urgent" as though that binds a hospital is a promise this
office cannot keep.

---

## 7. Overdue is derived, never stored

`ReferralService::overdueQuery()` is the single definition, used by both the staff filter and the
nightly sweep. Two definitions would eventually disagree, and the discrepancy would be read as the
job being broken long before anybody suspected there were two.

**The sweep changes nothing.** It writes no column and moves no status, because a referral is not
"overdue" as a *state* — it is overdue as a fact about today, and storing that would make it wrong
the following morning unless something recomputed it. Deriving it means the answer is always
current, and a missed run is a missed notification rather than a corrupted record.

It raises `ReferralBecameOverdue`, which TAB 19's tasks and TAB 20's notifications will listen
for. Published now and listened to later — the same inversion `ResidentMerged` uses, and the
reason follow-up work will appear the moment those modules land without editing the sweep.

The event carries **identifiers and a day count, not the referral**. A payload holding the reason a
family was referred would put that reason into every queue, log and failed-job record a listener
touches (Article 8.4).

A **draft never appears** in the overdue queue: `follow_up_on` is set when the referral is sent,
not when it is drafted. A follow-up date on something that never left the building would put a
draft into a queue of things somebody must chase another office about.

**Hearing anything at all** discharges the commitment — `responded_at` is stamped on any recorded
status, which is what takes a referral out of the queue.

---

## 8. A sent referral is frozen

Its disclosure record and its own fields both. The other office already has what it has; editing
afterwards would make the record describe a disclosure that never happened, and the version that
did would be the one nobody could reconstruct. Corrections after sending are **notes**, which is
what notes are for.

Notes are split by audience in the schema — `internal` versus `receiving-office` — rather than
sharing a column with a flag. A flag is what gets forgotten on the day somebody exports the lot.

---

## Consequences

* Every disclosure to an external organisation is itemised, reasoned and attributable.
* "What did DSWD receive about my client, and why" is answerable from one query.
* Referral attachments are blocked until the LGU grants `document.share` to somebody. This is the
  intended behaviour, not an oversight.
* A merge repoints referrals. `ResidentMergeCoverageTest` would **not** have caught a miss here —
  Welfare already had a listener, so the coverage test passes whether or not the new table is in
  it. That is the honest limit of that test, and the reason adding a `resident_id` column still
  means opening `ReassignWelfareRecordsOnResidentMerge`.
* Assistance history is a shareable field with no value wired yet (gap G-29): it is assembled
  across cases and enrolments, and returns nothing rather than an invented value.
* `ProgramCatalog` still does not audit its own writes, which publishing a programme arguably
  warrants at least as much as editing a directory entry (gap G-28).
