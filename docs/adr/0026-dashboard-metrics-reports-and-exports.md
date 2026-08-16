# ADR 0026 — Dashboard metrics, reports and exports

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 21
* Extends: ADR 0008 (database conventions), ADR 0020 (files), ADR 0023 (releases)

---

## Context

Three acceptance criteria: metrics reproduce the frontend filters; large exports do not hold an
HTTP request open; a person-level export requires explicit permission and is privately
downloadable.

And two instructions the master command gives in prose, both of which shape more of this TAB than
the criteria do: **aggregate-first**, and **do not use reports to create simplistic employee
performance rankings**.

---

## 1. Reporting owns no facts

Every number here is counted from another module's tables. Nothing in this module is the canonical
source of anything except the record that an export was asked for.

It therefore reads through query builders rather than importing other modules' services. A
dashboard is a read model, and a read model that could write would be a second authority — the
thing Article 6 exists to prevent.

**Materialised views are deliberately absent.** The master command says to use them only when
measurements prove they help, and there are no measurements yet: Taytay's caseload is thousands,
not millions, and an unmeasured read model is a second copy of the truth with its own refresh bug.
When the numbers justify one, it goes behind the same service and gets its own ADR.

---

## 2. Aggregate-first, and a small cell is a person

No endpoint returns a name except the one person-level report. The detail behind a count is
reached through the module that owns it, where authorization is checked per record.

**But an aggregate of one is an identification.** "3 households with a safeguarding concern in
Barangay Dolores" is a statistic; "1 household" plus the barangay names a family, and combined with
a sector breakdown it is a disclosure with no audit trail — precisely the disclosure the whole of
ADR 0022 §4 is built to prevent, arriving through a dashboard instead.

So counts below **five** are suppressed: the value becomes null and the cell is marked
`suppressed: true`. Five is the convention most statistical agencies use for exactly this; it is
not a law, and the LGU may want a different figure (gap **G-34**), which is why it is a named
constant rather than a literal in a query.

Two details that matter:

* **The row is kept, not dropped.** Dropping it would say the barangay has zero, which is a
  different and false statement — and an attentive reader comparing two filtered views could
  recover the number from the difference in totals.
* **Money follows the count.** A suppressed cell that still published its peso total would leak
  the same fact through a different column.

The threshold is published in the payload so a client can label a blank cell honestly. A dashboard
that silently shows nothing where a number was withheld reads as a bug, and somebody eventually
"fixes" it.

**Every metric is scoped.** An aggregate is exactly the shape that hides a scope leak: a number
does not look like a disclosure until you notice it was counted over the whole municipality.

**Released totals count money that moved**, not money approved. A dashboard counting approvals as
spend would tell the MSWDO head they had spent what they still hold (ADR 0023 §7).

---

## 3. Exports are copies that leave this system's control

Once a spreadsheet of a barangay's beneficiaries is on a laptop, none of this system's
authorization applies to it — no scope, no audit, no revocation. Everything below follows.

**Always queued, never inline.** Not a size threshold, which is a decision somebody eventually
tunes wrong on the day the data grows. There is no code path that could hold the request open.

**The request is recorded before the file exists**, with who asked, what they asked for, and
**what they were allowed to see at that moment**. "Why does this spreadsheet exist and who made
it" is the question asked after one turns up somewhere it should not — and by then the requester's
permissions may have changed, which is why the context is snapshotted rather than looked up.

The job **rebuilds the scope from that snapshot**. A scope that widened since must not
retroactively widen a file; one that narrowed must not silently produce fewer rows than the
request that was accepted.

**Re-authorized at download.** An export queued on Friday and fetched on Monday belongs to whoever
the requester is on Monday — somebody who lost a permission over the weekend lost it for this file
too. It is also scoped to the requester: an export is shaped by one person's scope at one moment,
and handing it to a colleague with a different scope would hand them rows they could not have
queried.

**Unknown, another person's, expired, unfinished and no-longer-permitted all answer 404.**
Distinguishing them would confirm that an export exists and what state it is in.

**The file expires; the row does not.** A download link that works for a month is a permanent copy
of a caseload behind a URL somebody bookmarked. Person-level files live 24 hours, aggregates a
week. Purging removes the copy and keeps the record, because the record is what an audit needs.

**Requesting and downloading are audited separately.** An export somebody queued and never fetched
is a different fact from one that left the building, and after an incident the second is the one
that matters.

**The report catalogue is closed.** An export endpoint accepting arbitrary columns and filters
would be a general-purpose extraction tool with one permission in front of it, and every report
anybody ever wanted would be reachable by whoever could reach the easiest one.

---

## 4. There is no per-caseworker report

**Decision: workload is reported by team. There is no grouping by caseworker anywhere, and no
report that returns one row per worker.**

The master command's instruction is explicit, and this is where such a thing would be added: one
entry in the catalogue, one `GROUP BY assigned_to`, and the office has a leaderboard.

The objection is not squeamishness. **A caseworker's open-case count measures the cases they were
given.** The worker handed the hardest families has the longest queue, the slowest closures and the
most reopened cases, and a ranking presents that as underperformance — so the rational response is
to avoid difficult cases, which is the opposite of what the office needs. Workload *by team*
answers the real question — where does the office need more people — without inviting the wrong
one.

**Filtering to one named worker is still allowed**, and is not the same thing. It is how a worker
sees their own queue and how a supervisor reviews a caseload they are responsible for. A filter
answers a question about one person; a grouping ranks everybody.

---

## 5. One person-level report, and it is the narrowest that works

`release-manifest` names people because a payout table needs a printed list of who is expected.
Refusing it entirely would mean somebody exports the whole case list instead and builds one by
hand — a worse copy, with more in it.

It carries the reference, the beneficiary identifier, the amount and the schedule, and
deliberately **not** the case narrative, the eligibility reasoning, or anything about why somebody
qualified. A manifest is a list of who is coming, not a summary of their circumstances.

It costs `report.export.person-level`, which `lgu_staff` does not hold.

---

## Consequences

* A dashboard cannot be used to enumerate a small barangay.
* An export is explicable a year later, including by somebody whose permissions have since
  changed.
* The office cannot build a staff leaderboard out of this API without adding a report to a closed
  catalogue and an ADR explaining why.
* No materialised view exists, and adding one is a measured decision rather than a default.
