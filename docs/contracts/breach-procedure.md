# Personal data breach — procedure

TAB 14 step 13: *"Write the breach procedure: detection, assessment, the 72-hour notification
obligation, who decides, who tells the National Privacy Commission, and who tells the residents.
Rehearse it once on paper."*

**Status: written, not rehearsed.** The rehearsal needs the people in the same room and is a manual
item. This document is engineering's half — what the system can tell you, and how fast.

---

## 0. The clock

RA 10173 and its IRR require notification to the **National Privacy Commission** and to the
**affected data subjects** within **72 hours** of knowing that a breach subject to notification has
occurred.

**The clock starts at knowledge, not at certainty.** Waiting to be sure before starting the clock
is the most common way an office misses it. Start the timer at the first credible report and stop
it if assessment shows notification is not required.

## 1. Detection

| Source | What it looks like |
| --- | --- |
| The audit trail | An actor reading records they have no business reading; a run of `identity.sign-in-failed` against one account; an export nobody can account for |
| A caseworker | *"This shows me somebody else's household"* |
| A resident | *"Somebody at the office knew something I never told them"* |
| The API | 4xx/5xx spikes, or a rate limit hit repeatedly by one key |

**Anyone may report. Nobody needs permission to raise one**, and a report that turns out to be
nothing costs an hour. A report that was never made costs the 72 hours.

## 2. Contain — before assessing

1. **Suspend the account**, if one is implicated. Deactivation ends a live session immediately
   (`DL-116`): an account switched off at 10am cannot keep its grants until the person signs out.
2. **Do not delete anything.** The trail is append-only and must stay that way; deleting the
   evidence is how a containable incident becomes an unexplainable one.
3. Preserve the `request_id`s involved — they correlate the trail to the API logs.

## 3. Assess

The DPO decides, using the trail:

| Question | Where the answer is |
| --- | --- |
| What was accessed? | `audit_entries` filtered by actor and date |
| Whose data? | `entity_type` + `entity_id` on those rows |
| How sensitive? | `docs/contracts/audit-coverage.md`, and the classification register at `GET admin/privacy/classifications` |
| Did it leave the office? | `report.person-level-export-downloaded`, `document.read` with `for_sharing`, referral disclosures |

**Notification is required** when the breach involves sensitive personal information or information
that could enable identity fraud, **and** there is a real risk of serious harm. Two of this
system's categories are `sensitive-personal` by default: **safeguarding** (RA 9262, RA 9344) and
health information. A breach touching those starts from *presume notification is required*.

## 4. Who decides, and who tells whom

| Role | Responsibility |
| --- | --- |
| **Data Protection Officer** | Decides whether the breach is notifiable. Signs the notification. |
| **MSWDO head** | Informed immediately; decides on service continuity and staffing. Does **not** decide notifiability — the trail records their own office's actions. |
| **DPO → National Privacy Commission** | Within 72 hours of knowledge. |
| **DPO → affected residents** | Within 72 hours, unless the NPC directs otherwise. In the language they use, through a channel they already trust — the barangay, not an email. |

**The DPO post is unfilled.** Until it is, this procedure has no decision-maker and no signatory:
its first three steps can be performed and step 4 cannot. That is TAB 14's blocker 1.

## 5. What engineering supplies, and how fast

| Artefact | How | Time |
| --- | --- | --- |
| Everything one actor did in a window | `GET admin/audit-entries?actor_subject_id=…&from=…&to=…` | Minutes |
| Everything done to one record | `GET admin/audit-entries/for-entity?entity_type=…&entity_id=…` | Minutes |
| Which exports left, and to whom | filter on the export actions | Minutes |
| **The values a record used to hold** | **Not available. Ever.** | — |

The last row is a deliberate limit, not a gap. The trail records that a record changed and which
columns moved, never what they became — so a breach assessment can say *what was accessed* and
cannot reconstruct *what was read*, beyond the record's current state. Any assessment that depends
on the latter must say so rather than inferring it.

## 6. After

1. Record the incident, the assessment and the decision — including a decision **not** to notify,
   with its reasoning. An unrecorded decision not to notify is indistinguishable afterwards from
   not having noticed.
2. Fix the cause, and add the check that would have caught it. Every ratchet in these two
   repositories exists because something got through once.
3. Re-run this procedure on paper afterwards, against what actually happened.

---

## Not yet done

* **The paper rehearsal.** Needs the MSWDO head, the DPO and whoever holds the deployment account,
  for about an hour, against an invented scenario. Recommended scenario: a barangay-scoped account
  is found to have read forty records outside its barangay over two weeks — it exercises detection
  from the trail, containment, assessment, and the notifiability judgement, and it is the failure
  this system's scoping is most designed to prevent.
* **The NPC contact route** — who to ring, and the current notification form. An office detail,
  needed before the rehearsal is meaningful.
