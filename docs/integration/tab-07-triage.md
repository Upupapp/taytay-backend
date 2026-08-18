# TAB 07 — triage of the 36 no-counterpart rows

The command's first instruction: *"Triage every no counterpart row into: build it, map it to
something that exists, or withdraw the console feature with a recorded reason."* Its stated risk is
**building to a guess instead of a measurement**, so every row below cites the measurement it came
from — `docs/integration/port-mapping.md` in the console, built from `route:list` and
`openapi.json` rather than from the endpoint matrix.

| Decision | Rows |
| --- | --- |
| **Build here, in TAB 07** | 14 |
| Deferred to TAB 08 (money) | 3 |
| Blocked on a ratification nobody in engineering can give | 11 |
| Withdrawn — the port method is deleted | 1 |
| Decided below, and not all of them are "build" | 7 |
| **Total** | **36** |

---

## Build here — 14 rows

Each is named in the command's own KNOWN SCOPE table, which is what makes it a measurement rather
than a guess.

| Port method | Endpoint to build | Module | Constraint the command imposes |
| --- | --- | --- | --- |
| `FamilyRepository.list` | `GET admin/families` | ResidentProfile | Read side of the **same aggregate**. No second family model. |
| `FamilyRepository.getById` | `GET admin/families/{family}` | ResidentProfile | ″ |
| `FamilyRepository.familiesOf` | `GET admin/residents/{resident}/families` | ResidentProfile | ″ |
| `FamilyRepository.historyForResident` | `GET admin/residents/{resident}/kinship-history` | ResidentProfile | ″ |
| `BeneficiaryRepository.list` | `GET admin/beneficiaries` | ResidentProfile | **A projection, never an entity.** No stored standing. |
| `BeneficiaryRepository.getByResidentId` | `GET admin/beneficiaries/{resident}` | ResidentProfile | ″ |
| `BeneficiaryRepository.resolutionsFor` | `GET admin/residents/{resident}/duplicate-findings` | ResidentProfile | Findings history; supersede-not-merge (`DL-74`). |
| `WorkRepository.myQueue` | `GET admin/work/mine` | Tasks | Read-only, derived server-side. Overdue derived, never stored. |
| `WorkRepository.teamQueue` | `GET admin/work/team` | Tasks | ″ |
| `WorkRepository.alerts` | `GET admin/work/alerts` | Tasks | A condition of the data, not a task. |
| `ReportRepository.catalogue` | `GET admin/reports` | Reporting | Aggregate-first. |
| `ReportRepository.run` | `POST admin/reports/{report}/run` | Reporting | Suppress small cells; never round. No grouping by caseworker. |
| `GovernanceRepository.classifications` | `GET admin/privacy/classifications` | Shared/Audit | Governance read. |
| `FieldVisitRepository.mine` | `GET admin/visits?scope=mine` | Welfare | **Scope, not a new resource** — the command says so explicitly. |

---

## Deferred to TAB 08 — 3 rows

`DisbursementRepository.approverFor`, `.listBatches`, `.getBatch`.

TAB 08 is *Money*, rated **P0 — public funds**, and it opens by reconciling the nouns: the console
says `disbursement`, this API says `release`. Building a batch endpoint now would mean building it
under a name TAB 08 exists to decide, and then changing the URL, the payload and the words a
disbursing officer is trained on. The port-mapping already marked all three TAB 08; this confirms
it rather than rediscovering it.

---

## Blocked on ratification — 11 rows

The whole of `CaseRepository`.

ADR 0044 proposes what a case is — Option A, supersede-not-merge — and it is **accepted in
principle, pending a working session with the MSWDO head, a social worker and an intake officer**.
That session is on the master TODO and has not happened.

**This is not an engineering blocker and cannot be unblocked by engineering.** A case lifecycle is
the office's description of its own continuing involvement with a family; building seven states and
eleven endpoints to a model the office has not agreed would produce exactly the artefact this
integration keeps finding — a confident second description of something, discovered to be wrong
late. The command's own risk line is *building to a guess instead of a measurement*, and here the
measurement is a decision that does not yet exist.

Recorded as blocked, with the endpoints designed and unbuilt.

---

## Withdrawn — 1 row

`NotificationRepository.create`.

The console's port can raise a notification. **This API is read-only for the actor**: notifications
are raised by domain events, and no endpoint creates one. That is correct — a console that can
write to its own user's inbox can tell a caseworker something the system never concluded.

The decision is to **delete the port method** in the console, not to build the endpoint. Recorded
here because a withdrawal that lives only in a commit message is a withdrawal nobody can audit.

---

## Decided here — 7 rows

These are the rows the command's KNOWN SCOPE table does not name, so each needed a decision rather
than an implementation instruction.

### `ProgramRepository.listRequirementTemplates` — **build**

Only `POST` exists. A write with no read means the console can create a requirement template and
never show one, which is how a catalogue silently accumulates duplicates. The read side is the
smaller half of a pair that already exists; not building it would leave the write side unusable.

→ `GET admin/programs/{program}/requirement-templates`

### `ProgramRepository.utilizationFor` / `.utilizationSummary` — **build**

Utilisation is how the office answers "is this programme reaching anybody". Both are aggregates
over records this API already owns, so they are the *cheap* kind of endpoint — no new state, no new
vocabulary.

They inherit the reporting constraints in full: small cells suppressed rather than rounded, and
never grouped by caseworker.

→ `GET admin/programs/{program}/utilization`, `GET admin/programs/utilization`

### `AssistanceRequestRepository.advisoryFor` — **build, and this one matters most**

The intake advisory is computed **console-side** today. That is a defect of the same family as
`DL-42` and G-16: a rule that decides how an applicant is treated, living where the client can see
it, change it, or fail to run it.

`DL-60` is explicit that the advisory *advises and never decides* — no score, no `eligible`, no
recommendation, each signal stating the rule it applied and the records it read. Moving it
server-side does not weaken that; it makes it enforceable, because the console can no longer be the
only thing that knows the rule.

→ `GET admin/assistance-requests/{request}/advisory`, and the console's client-side computation is
deleted rather than left as a second implementation.

### `NewsfeedRepository.history` — **build**

`DL-124`: a post goes outward and nothing brings it back. The history is the record of what was
published and when it was archived — the only evidence of what residents actually saw. Without it,
"we took it down" is unverifiable.

→ `GET admin/newsfeed/{post}/history`

### `EventRepository.history` — **build**

Same reasoning, plus `DL-131`: cancelling is one-way, and an event that is back on is a *new* event
naming the old. The chain is only readable if the history is.

→ `GET admin/events/{event}/history`

### `EventRepository.metrics` — **build, deliberately narrow**

`DL-126` and `DL-130` between them settle the shape: **counts, and nothing that could answer
*which* residents**. Registrations, attendance, capacity, waitlist depth — no per-resident
breakdown, no demographic split thin enough to name somebody.

→ `GET admin/events/{event}/metrics`

---

## What this triage commits to

Twenty endpoints across six modules. Every one traces to a port method the console already calls,
which is the command's test for whether it should exist at all: *"Build only what a measured port
method needs. Speculative endpoints become permanent liabilities."*

Nothing here is built to a guess. The eleven case rows are the proof — the honest answer there is
that the measurement has not been taken yet, and no amount of engineering substitutes for it.
