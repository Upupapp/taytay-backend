# ADR 0012 — Data scopes are enforced server-side, and provisioning cannot escalate

* Status: **Accepted**
* Date: 2026-08-15
* Deciders: backend architecture (TAB 07)
* Relates to: ADR 0002 (server-side authorization), ADR 0009 (accounts vs residents),
  ADR 0010 (resident canonicity), ADR 0008 (schema conventions)

## Context

TAB 05 shipped `role_assignments` with a `scope_type` column and a nullable `barangay_id`.
TAB 06 built the KYC review queue on top of it. Nothing ever read either column.

The effect was worse than having no scope at all. The database recorded that a clerk was
bound to one barangay, the admin console could display it, staff could be told it was true
— and any of them could open every KYC case in the municipality by listing the queue.
A control that is visible but not enforced is a control everyone stops checking.

Three things had to be decided together, because fixing any one alone leaves the hole open:

1. what a scope *is*, so it can be derived from persisted state rather than inferred;
2. where it is *enforced*, so a new endpoint cannot forget;
3. who may *change* it, so the fix cannot be undone by whoever provisions staff.

## Decision

### 1. A scope is a value object resolved per request

`Modules\Shared\Application\DataScope` carries a type and a set of barangay ids. Four
types, ordered from widest to narrowest:

| Type | Reaches |
| --- | --- |
| `all-barangays` | every record in the municipality |
| `own-barangay` | records in the actor's assigned barangays, plus any explicitly granted |
| `assigned-cases` | as above, **and** only records assigned to the actor |
| `none` | nothing |

`ScopeResolver` builds it from `role_assignments` and `staff_barangay_grants` on **every
request**, never from a token claim. This is the difference between "revoked" and "revoked
when their token expires": an assignment ended at 09:00 stops applying at 09:00, not at
21:00 when a twelve-hour token lapses.

Two resolution rules, both deliberate:

* **The widest live assignment wins.** Someone who is both a barangay clerk and a
  municipal auditor is a municipal auditor. The alternative — narrowest wins — means adding
  a role *removes* access, and the natural fix ("give them another role") ratchets
  permissions upward, which is how these systems end up with everyone holding everything.
* **Deny by default.** No assignment, an expired one, or a `scope_type` the catalog does
  not recognise resolves to `none`. A scope nobody can interpret must read as nothing.

`ActorContext` carries the resolved scope, so it travels with the actor into every
application service rather than being re-derived per call site.

### 2. Enforcement lives at the record loader and at the query

Two enforcement points, and no third:

* **Listings** go through `AuthorizationService::scopeToBarangays()`, which constrains the
  *query*. Filtering after fetching would still pull other barangays' rows out of the
  database, and the first person to add a count or pagination gets a total that counts
  records the caller may not see — which leaks how many exist elsewhere.
* **Single records** go through a loader — `KycController::caseOrFail()` is the model — that
  authorizes before returning. Every verb the resource exposes calls the same loader, so
  there is no method a caller can switch to in order to reach a record their scope
  excludes, and adding an endpoint inherits the check rather than needing to remember it.

Putting the decision in the loader rather than in each action is the whole point. Per-action
checks are correct on the day they are written and wrong the first time somebody adds a
route in a hurry.

### 3. Out of scope returns 404, never 403

A `403` on a record that exists tells the caller it exists. Repeated against guessed
identifiers, that difference is a directory of every applicant in the municipality — OWASP
API1, and under RA 10173 a disclosure in its own right, because "there is an applicant with
this id in Barangay Dolores" is personal data.

`ResourceNotFoundException` is therefore thrown for out-of-scope records, with the same code
and the same message as a genuinely absent one. Permission failures still return `403`:
"you may not review KYC cases" reveals nothing about any particular person.

### 4. Explicit grants are the only way to widen a barangay scope

`staff_barangay_grants` is a separate table, not a second nullable column on
`role_assignments`. Two reasons:

* `role_assignments` is uniquely keyed on `(subject_id, role)`. Widening it with a nullable
  barangay would break that key on PostgreSQL, where `NULL`s compare distinct and the
  "one row per role" guarantee silently disappears (ADR 0008 §4).
* A grant is a different kind of fact from a role. It has a reason, a granter and an end
  date, and it is meant to be reviewed and to expire. Roles are not.

A grant requires a stated reason and may carry `valid_until`. Expiry applies itself — an
expired grant stops widening the scope on the next request with nobody revoking it.

### 5. Provisioning cannot escalate

`staff.manage` is the permission that hands out the others, so it is constrained by three
rules that live in `StaffProvisioningService`, not in a controller:

1. **No self-service.** Nobody may assign, revoke, grant or deactivate on their own account.
   An administrator who can widen their own scope has no scope, and the audit trail cannot
   distinguish a legitimate change from an escalation.
2. **No administrative escalation.** A granter may not hand out an *administrative*
   permission (`staff.manage`, `staff.view`) they do not hold. This closes the two-step
   loop: grant a colleague the power to provision, have them grant you back what you were
   refused.
3. **No scope laundering.** A granter may not assign a barangay, or a municipality-wide
   scope, they cannot reach themselves.

Rule 2 is about *administrative* permissions only, and that is a considered choice.
Requiring a provisioner to personally hold every operational permission they grant would
mean only a super-admin could staff the office — the concentration of power this design
exists to prevent. A security officer appoints KYC reviewers and cannot approve a case; that
is separation of duties working.

**Residual risk, stated rather than hidden:** a provisioner can create an account, grant it
an operational role, and use it if they control its mailbox. That is collusion or
impersonation, not an authorization defect. It is addressed by the audit trail — every grant
records granter, grantee and the granter's own scope — and by staff MFA (ADR 0009), not by
refusing provisioners the ability to staff.

### 6. Authentication is not access

Three separate gates, and passing one grants nothing about the next:

* the token proves *who* you are;
* the permission catalog decides *what kind of thing* you may do;
* the scope decides *whose records* you may do it to.

A staff account with a valid token and no assignment reaches nothing. An account that is
suspended, locked or deleted resolves to a guest even holding a live token, and
deactivation deletes its tokens as well — the window closes from both ends rather than
waiting for expiry.

### 7. Everything is recorded, and nothing is deleted

Every assignment, revocation, grant and withdrawal writes an `audit_entries` row naming the
actor, the subject, the action and the **actor's own scope at the time** — because "an admin
granted this" is a much weaker fact than "an admin who could themselves reach that barangay
granted this", and the second is what shows the guards held.

Revocation ends a row's validity; it never deletes it. The row is the answer to "who was
allowed to approve this, in March", and a deleted row answers it wrongly. Summaries name
the authority, never the person: no names or email addresses in the trail, or it becomes a
second, less-guarded copy of the directory it exists to protect.

## Consequences

* Every staff-facing listing must be scoped at the query, and every staff-facing record
  fetch must go through a loader that authorizes. A new endpoint that does neither is a
  defect, and `AuthorizationMatrixTest` is where that is meant to be caught.
* Scope resolution costs two indexed reads per authenticated request. That is the price of
  authority reflecting current state, and it is the right trade — the alternative is stale
  authority in a token.
* A resident's own `/me/` routes are unaffected: they resolve records from the token, so
  there is no scope to apply and no identifier to tamper with.
* Duplicate-match candidates deliberately remain visible across barangays. Detecting that a
  new applicant is already registered elsewhere in the municipality is the entire purpose of
  matching (ADR 0010), and the candidate projection is minimal by design — name, birth date,
  barangay, tier — never the other resident's case history or welfare record.
* Adding a permission means adding a case to `Permission` and deciding, explicitly, whether
  it is administrative. Adding a scope type means changing `DataScope`, `ScopeResolver` and
  the `role_assignments` check constraint together; the schema refuses values the catalog
  does not know, so a half-finished migration fails loudly rather than leaving an actor
  holding a scope nothing evaluates.
