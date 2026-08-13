# ADR 0007 — One canonical assistance lifecycle, projected per channel

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 02)
* Relates to: gaps G-05, G-06; CLAUDE.md Articles 3.1 and 6

## Context

Two clients already model the assistance lifecycle, differently:

| Client | States |
| --- | --- |
| Angular staff console | 13: `draft, submitted, intake-review, returned, assessment, endorsed, approved, rejected, scheduled, released, completed, cancelled, expired` |
| `lgu_ids_taytay` (citizen) | 17: `draft, submitted, received, underReview, forSocialWorker, forVerification, forHealthOfficeReview, forMswdoReview, needsMoreDocuments, waiting, forInterview, forApproval, approved, scheduledForRelease, released, rejected, cancelled` |

Neither is a superset. The citizen set encodes *routing* (`forHealthOfficeReview`,
`forSocialWorker`) as state, where the staff set treats routing as assignment and keeps
state for the stage of the work. The citizen client also decides `canCancel` and
`isTerminal` locally — business rules inside a shipped mobile build.

The backend owns none of this yet, which is the one moment where it is cheap to fix.

## Decision

**The Angular staff lifecycle is the canonical state machine. Every other status
vocabulary is a read-only projection the backend computes.**

1. The canonical enumeration, transition map and terminal set are the ones in
   `domain/assistance/assistance-request.ts` — 13 states, with `returned` re-entering at
   `intake-review`, and `rejected`, `completed`, `cancelled`, `expired` terminal.
2. Transitions happen only through one endpoint,
   `POST /api/v1/assistance-requests/{id}/transitions`, which validates against the
   transition map before checking the permission for the target state. Status is never
   assigned directly.
3. Every citizen response carries a **projection**: a `status` from a smaller
   citizen-facing vocabulary, a plain-language `status_message`, and
   `available_actions[]` computed server-side.
4. `available_actions` replaces client-side `canCancel`. The client renders what it is
   told.
5. Routing (`forSocialWorker`, `forHealthOfficeReview`) is modelled as **assignment**, not
   state: a request in `assessment` assigned to the health office. Encoding the routing
   target in the state name multiplies states by offices.

### Projection

| Canonical | Citizen-facing | Cancellable by citizen |
| --- | --- | --- |
| `draft` | `draft` | yes |
| `submitted` | `submitted` | yes |
| `intake-review` | `under-review` | yes |
| `returned` | `needs-more-documents` | yes |
| `assessment` | `under-review` | no |
| `endorsed` | `under-review` | no |
| `approved` | `approved` | no |
| `scheduled` | `scheduled-for-release` | no |
| `released` | `released` | no |
| `completed` | `completed` | no |
| `rejected` | `rejected` | no |
| `cancelled` | `cancelled` | no |
| `expired` | `expired` | no |

`assessment` and `endorsed` both project to `under-review` deliberately: an applicant does
not need to know which desk holds their file, and publishing it would let them infer the
handling social worker (visibility matrix §1).

The cancellable column is the *default* rule, not the whole rule — the server also
requires ownership and re-checks it at execution time. It is documented here so the
projection and the rule cannot drift.

## Rationale

1. **The staff lifecycle is the one the office operates.** It has transition rules, a
   separation-of-duties test, and a decision log recording why each divergence from the
   reference systems was made (DL-04 through DL-08). The citizen list has none of that
   behind it.
2. **Terminality and cancellability are money-adjacent rules.** They belong where they can
   be changed once and revoked — not in an installed mobile build that cannot be patched
   on demand.
3. **Projection is cheaper than reconciliation.** A citizen vocabulary that is *derived*
   can be changed for clarity without touching casework; two independent state machines
   must be kept in sync forever, and will not be.
4. **It is Article 3.1 applied to a lifecycle.** One rule, many presentations — never a
   per-client fork.

## Consequences

* Positive: one state machine, one transition endpoint, one authorization table.
* Positive: citizen wording can be improved — or translated to Filipino, which
  `lgu_ids_taytay` already does — without a backend behaviour change.
* Positive: a client cannot invent a state the backend does not recognise.
* Negative: `lgu_ids_taytay` must replace `RequestStatus`, `canCancel` and `isTerminal`
  with the projected values from the API. That is real rework, and it is smaller now than
  after the endpoints exist.
* Negative: two vocabularies must both be documented, and the projection table above
  becomes a thing to maintain. Accepted: it is a table in one file, not logic in two apps.
* The citizen timeline renders `remarks` per entry; those must come from an
  applicant-facing `decision_reason_public`, never from `decision_remarks` or assessment
  findings (visibility matrix §3).

## Alternatives rejected

* **Adopt the 17-state citizen list as canonical.** Rejected: it conflates routing with
  state, has no transition rules, and would force the staff console — the system of record
  for casework — to rebuild around the less rigorous model.
* **Union of both sets.** Rejected: 20+ states, most unreachable, and every consumer would
  still need to know which subset applies to it.
* **Let each client keep its own and map at the edge.** Rejected outright: that is the
  duplicate business logic this TAB exists to prevent, and the mapping would live in two
  places that drift independently.

## Sources

* Angular reference: `src/app/domain/assistance/assistance-request.ts`,
  `docs/reference-audit/decision-log.md` DL-04 … DL-08.
* Citizen reference: `lgu_ids_taytay/lib/data/models/models.dart`.
* DSWD AICS practice — intake and document validation, social-worker case study, head
  approval, payout — as reflected in the staff lifecycle:
  <https://www.dswd.gov.ph/>
* Statutory basis for the confidentiality constraints on projection: RA 10173,
  RA 9262, RA 9344.
