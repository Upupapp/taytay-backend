# ADR 0022 — Field visits, case notes and safeguarding

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 17
* Extends: ADR 0015 (vulnerability), ADR 0016 (case engine), ADR 0021 (referrals)

---

## Context

Field work is where a welfare system meets a family, and it is where the records that follow them
for years get written. Three acceptance criteria: notes carry visibility and source
classification; safeguarding detail is not returned to generic list endpoints; no continuous
location tracking is implemented.

The admin console models this in detail — `VisitObservation` with its `ObservationKind`,
`CaseNote` with `discloseCaseNote`, `FieldVisit` with a build-time check that no location field
appears — and that model is authoritative.

---

## 1. There is no location field, and the absence is enforced

**Decision: no coordinate, check-in, route, arrival ping or "worker location" anywhere in field
work. `NoLocationTrackingTest` fails the build if one appears.**

The master command forbids continuous location tracking, geofencing and background surveillance.
Those are easy to refuse as *features* and easy to acquire as *columns*. A `visit_location` added
in good faith to help a supervisor plan routes is the first half of a system that records where
poor families live and who visited them when — and nobody sets out to build that. It arrives one
helpful field at a time, each individually reasonable.

So the rule is a test rather than a paragraph. The console enforces the same thing with
`tools/check-visits.mjs`; this is the server half, and it is the half that matters, because a
column that exists will eventually be filled by something.

**What is not forbidden: the address visited.** The household registry already holds it, the
worker is going there anyway, and a visit record that cannot say where it happened is useless. The
line is between *an address the office already has* and *a position captured from a device* — the
first is a fact about a household, the second is a fact about a person's movements. The test
carries that distinction explicitly so nobody later "fixes" it by adding `address` to the
forbidden list.

The address is **copied** at scheduling, not referenced: a household that moves must not silently
rewrite where a past visit was made.

---

## 2. An observation carries whose claim it is

**Decision: every observation records its kind — `observed`, `client-said`, `third-party-said` or
`worker-assessed`.**

Three sentences a worker might write in one paragraph:

> The roof is missing sheets over the sleeping area.
> She says her husband has not sent money since March.
> The household appears unable to meet its own food costs.

The first is checkable by another visit. The second is a report the office is repeating, and may
be wrong without anybody lying. The third is a professional judgement a later reader may disagree
with. **As one block of prose they are indistinguishable**, and six months on a different worker
reads all three as established fact about the family — and acts on it.

Nothing here prevents a worker recording a judgement. It prevents a judgement from being mistaken
for something the family said.

Two rules, and the second is the one people leave out:

* **`third-party-said` must name who.** "A neighbour said" with no neighbour named is a rumour the
  office cannot check and cannot answer for — and it is the form in which a grudge enters a
  family's file.
* **Nothing else may carry an attribution.** On the worker's own observation it would read as
  though somebody else vouched for it.

Observations are append-only and ordered by insertion, not merely by timestamp. Several are
written in the same second, and on an equal timestamp the database is free to return them in any
order — which for a record read as a narrative changes how the account reads.

**The checklist is a prompt, never a score.** Nothing totals it and nothing derives an eligibility
or a vulnerability rating from it. A checklist that totals is a checklist that decides.

---

## 3. Notes are classified, and withheld ones stay visible

**Decision: two sensitivities — `routine` and `protected` — and a withheld note still discloses
its existence, author, sensitivity and time.**

The tier is narrow on purpose. A protected tier catching half the record protects nothing: it just
makes the ordinary running record unreadable to the people doing the work, and they stop writing
it down. `protected` is safety planning for a VAWC survivor (RA 9262), anything identifying a
child in conflict with the law (RA 9344), a disclosure given in confidence, or clinical detail.

**The body is removed by the application**, not hidden by a client. A payload that never contained
the paragraph cannot leak it, and no future change to a template can undo that.

**But the note's existence is disclosed, and this is the part people get wrong.** A caseworker who
cannot see that three restricted entries exist reads the file as complete and acts as though
nothing happened. Knowing a record is there, and that it is not yours to read, is what makes it
possible to ask the right person. `withheld_count` is surfaced as a number so a client can say "3
entries are restricted" once, rather than rendering three placeholder cards that read as clutter
and get designed away.

**Writing into the protected tier needs the same clearance as reading it.** Otherwise anybody can
file a note nobody in their own team can see, which puts something beyond *review* rather than
beyond disclosure — the opposite of what the tier is for.

**Notes are withdrawn, never deleted, and only by their author.** A note is a contemporaneous
record; editing it changes what the file says the office knew at the time, which is the single most
useful property it has in a dispute. A mistake is corrected by a later note. A supervisor who
disagrees writes their own, which is a better record than a silently vanished one.

---

## 4. Safeguarding is a table, and there is no way to browse it

**Decision: `safeguarding_concerns` is its own table with its own permissions, and the API offers
no list endpoint at all.**

### Why not a flag on the case

Three reasons, each sufficient:

* a boolean on `welfare_cases` is selected by **every list query in the system**, so it reaches
  every queue, export and count — "minimal list-view exposure" is impossible once the column
  travels with the row;
* a concern has an author, a date, a category and a review; a flag has none of those, and "who
  decided this family is a risk, and when" is the first question asked;
* concerns are **closed**, with a reason. A cleared flag leaves nothing behind, so a family either
  carries a suspicion nobody can find the origin of, or loses one that mattered.

### Why no list endpoint

A queue of safeguarding concerns is a list of families under suspicion. Once it exists it will be
filtered, sorted, exported and eventually joined to something. Every read here is scoped to one
named resident somebody already had reason to open — which makes each read a decision rather than
a browse, and makes the acceptance criterion structural rather than a field somebody remembers to
strip.

### Three tiers of exposure

| Where | What is shown | Who |
| --- | --- | --- |
| Any list endpoint | **nothing** — not the detail, not the category, not a marker | everyone |
| Case detail | that an active concern **exists** | anyone who may open the case |
| Visit detail | a **one-sentence** worker-safety advisory | anyone who may make the visit |
| Resident safeguarding endpoint | the full record | `safeguarding.view` only |

The advisory and the detail answer genuinely different questions. A worker being sent to a house is
entitled to know there is a risk to *them* without being told a family's protection history;
withholding both would send somebody into a situation the office knew about, and disclosing both
would put a protection record in front of everybody who drives a van. The advisory says what to do;
the detail says why.

The advisory is deliberately **not** a count. "2 safeguarding concerns" is a judgement about a
family that travels further than a sentence does, and read off a screen in front of a household it
is a disclosure the office cannot take back.

**The audit trail names the act, never the category.** An entry reading "child-protection concern
raised" against a case id would be a second, less-guarded copy of exactly what this table
restricts — and the audit log is read by operators investigating something else entirely.

---

## 5. Permissions

| Permission | Holder | Why |
| --- | --- | --- |
| `visit.view` / `visit.manage` | staff, admin | Field work **is** front-line staff's job; they are the ones who go. |
| `case-note.view-protected` | admin only | Reading a survivor's safety plan. |
| `safeguarding.view` | admin only | The narrowest read in the system. |
| `safeguarding.manage` | admin only | Deciding a family no longer needs watching is as consequential as deciding they do. |

The last three sit with `lgu_admin` for the same reason as `vulnerability.view.protected` and
`document.view.sensitive`: they belong to a dedicated protection officer, and that role does not
exist yet (gap **G-30**). When it does, all six move there and the MSWDO head keeps none of them —
reading a survivor's safety plan is not an administrative convenience.

A staff member covering a colleague's caseload can read the running record and see that protected
entries exist without reading them. That is the intended shape: enough to work the file, enough to
know what they are missing, and no more.

---

## 6. Offline capture is not built here

The console models a `VisitCapture` with an explicit `held-locally` / `sending` / `sent` /
`send-failed` state and no background queue — because a worker who believes a visit was filed and
returns to the office to find it was not has been failed twice, once by the network and once by
the interface.

That is a **client** concern and stays there. This backend offers ordinary idempotent endpoints; it
does not implement a staff sync protocol, and the master command explicitly defers one. If field
sync is added later it needs its own controlled design and its own ADR (gap **G-31**) — an offline
protocol bolted onto endpoints designed for a browser is how duplicate visit records and lost
observations arrive.

---

## Consequences

* A file can be read six months later without mistaking a judgement for a finding.
* A restricted record is visible as *existing* to everyone who may open the case, and readable
  only by those cleared for it.
* Safeguarding cannot leak through a list, because there is no list.
* This system cannot answer "where was this worker on Tuesday", and cannot be made to without
  deleting a test that says why not.
* Field visits are a new consumer of `resident_id`. Repointing on merge is added to
  `ReassignWelfareRecordsOnResidentMerge` — `ResidentMergeCoverageTest` would not have caught a
  miss, for the reason recorded in ADR 0021.
