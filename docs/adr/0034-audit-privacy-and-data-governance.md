# ADR 0034 — Audit, privacy, consent and data governance

* **Status:** accepted
* **Date:** 2026-08-17
* **Built in:** TAB 29
* **Related:** ADR 0019 §4 (merge coverage), ADR 0023 §3 (a role created to make an unheld
  permission operable), ADR 0026 §3 (exports), ADR 0008 §13 (no application state in JSON)
* **Legal frame:** Republic Act No. 10173 (Data Privacy Act of 2012) and current National Privacy
  Commission issuances. **Nothing in this ADR is legal advice or an approved schedule.**

---

## Context

`audit_entries` has existed since TAB 04 and ten TABs wrote to it. What it did not have was:

* **a reader.** Every entry was written and none could be retrieved without a database console — an
  audit apparatus that is pure cost until somebody needs it and then cannot use it;
* **one writer.** Ten modules hand-rolled the same insert, and they had already begun to differ;
* **a risk classification.** The master command names eleven categories of high-risk act and
  nothing in the system knew which was which;
* **any privacy governance at all.** No notice, no acknowledgement, no consent, no retention
  configuration, no legal hold.

---

## 1. One writer, and it is an interface in `Shared`

Before this TAB there were ten `…Audit` classes, each building the row itself. Some set
`actor_label`, some did not; the 255-character truncation appeared ten times; nothing decided
risk.

That is the drift this project has been bitten by before. Ten writers means ten places a new column
must be filled in, and the tenth will be missed — and **a missing audit field is invisible, because
a trail with a gap looks exactly like a trail of a quiet week.**

The module classes survive as thin, well-named seams, so callers still write
`$this->audit->record(...)` in their own vocabulary. What changed is that all ten now end in
`AuditTrail`.

### The cycle, and why the interface lives in `Shared`

Consolidating immediately produced `AccessControl → Audit → AccessControl`, and
`ModuleBoundaryTest` failed the build. That was correct and useful: `Audit` has an HTTP surface, so
every protected endpoint must ask `AccessControl` who may read the trail — while `AccessControl`
must write to the trail like every other module.

The resolution is the inversion the boundary map already prescribes. `Modules\Shared\Contracts\AuditWriter`
is an interface in the module everyone may depend on and which depends on nothing; `Audit` binds
the one implementation and is then free to depend on `AccessControl` like any module with a surface
to protect.

`Shared` holds the **interface only** — no table, no query, no rule — so its charter is intact.

**There is no null implementation and no fallback.** If `Audit` is not loaded the binding fails and
the application does not boot. A system holding Philippine personal data with auditing silently off
is worse than one that refuses to start.

---

## 2. What the trail refuses to hold

The master command: *do not copy full case notes, passwords, raw ID numbers or entire resident
objects into generic audit payloads.*

**`AuditTrail` enforces this rather than trusting callers**, because the trail is read by operators
investigating something else entirely, retained longer than most of what it describes, and exported
for compliance review. A trail that duplicates the data it protects is a second, less-guarded copy
of it.

* `changed_fields` takes **column names**, never values. *"Somebody altered this resident's birth
  date on Tuesday"* is the finding an investigation needs; the birth date is already in the record
  being investigated.
* An **associative array is read for its keys**. A `$changes` array in an update method is already
  keyed by field name, so passing one whole looks right — and would write the values. The keys are
  taken and the values discarded.
* Anything that **does not look like a column name is dropped**, not stored. A date, an email, an
  identifier or a sentence all fail the pattern.
* `reason` takes a reason the actor typed **for this purpose**, never one lifted from a case note or
  a rejection justification — those are written for a colleague and belong to the record.

There is no parameter anywhere for an old value or a new one.

`AuditAndPrivacyTest` exercises the whole system, scans every persisted summary for PhilSys
numbers, emails, mobile numbers, birth dates and street addresses, **and tests the scanner against
planted identifiers first** — a detector that cannot detect is worse than none, because it is
believed.

---

## 3. Network identifiers: high-risk only, and off by default

An IP address is personal data under RA 10173. Capturing one on every routine read builds a
movement log of the office's own staff — thousands of rows a day recording where a clerk was
sitting — which is disproportionate to any use it would be put to. On a sensitive document download
it is proportionate evidence.

So capture is limited to `high` risk entries **and** requires `AUDIT_CAPTURE_NETWORK=true`. Whether
it is on at all is the DPO's decision, not a default this repository picks. The master command says
*"IP/user-agent where policy permits"*, and the honest reading of "where policy permits" is that the
policy does not exist yet.

---

## 4. Consent is the minority case, and saying otherwise is a promise

**This is the most consequential decision in the TAB.**

Almost everything a municipal social welfare office does is a legal obligation or a public-task
function. It does not need consent and — the part that matters — **it cannot honour a withdrawal of
one.**

Recording statutory processing as "consent" is the classic privacy-engineering error, and it is not
a labelling mistake. Consent implies a right to withdraw. An office that offers withdrawal for
processing it is legally obliged to perform must then either break the promise or break the law, and
the person the promise is broken to is a resident who asked for their data to stop being processed.

So:

* `config/privacy.php` declares a **legal basis per purpose**. Five are `public-task` or
  `legal-obligation`; four are genuinely `consent`.
* `consent_records` covers **only** the four. `GovernanceRegistry::grant()` refuses any purpose
  whose declared basis is not consent, and that refusal is the most useful thing in the class.
* The consent purposes are **derived from the bases**, never listed twice — two lists would
  eventually disagree, and the disagreement decides whether a withdrawal is honoured.
* The bases are **published to residents** on the public notice endpoint. A person is entitled to
  know that most of what this office does with their data is not something they were asked to agree
  to, and an interface implying otherwise would be the misrepresentation this section is about.

### Acknowledgement is not consent

`privacy_acknowledgements` records that a person was *shown* a version of the notice. Being told how
your data will be used is not agreeing to it. Two tables, because collapsing them would make "may
this person withdraw?" depend on reading a type column correctly at every call site.

Withdrawal is a **timestamp, never a deleted row**: *"was this photograph published with permission
at the time?"* is a question the office must still be able to answer.

**There is no endpoint that grants consent on somebody else's behalf.** A staff member recording
that a resident consented is asserting something only the resident can assert, and a consent record
created that way is evidence of nothing.

---

## 5. Retention: the machinery exists, the switch is off

The master command: *do not hardcode legal retention periods or legal bases without Taytay
DPO/legal approval.*

The tempting reading is "so leave retention unimplemented". But then the first person who needs it
writes a `deleteWhere('created_at', '<', ...)` in a job, and the schedule governing destruction of
residents' welfare records becomes a literal in a file nobody reviews.

So the machinery exists, the categories sit in one reviewable config file, and
`PRIVACY_RETENTION_APPROVED` is false. `RetentionPolicy::mayPurge()` **refuses everything** and says
why — tested against a record twenty years past any plausible schedule.

The asymmetry is the point: **deletion is the one operation this system cannot undo.** A record kept
too long can be destroyed tomorrow; a record destroyed on an unapproved timetable is gone, and the
family whose assistance history it held cannot get it back.

An unknown category returns `null` rather than a default, because a default means a category
somebody forgot to add silently inherits a number nobody chose — and the direction of that mistake
is deletion.

The schedule is served through the API, so *"is this approved?"* is answered by the running system
rather than by a document, and the payload itself states that nothing in it is law yet.

---

## 6. Legal holds outrank the schedule, in one direction only

A hold can **prevent** a deletion and can never cause one. Checked inside `RetentionPolicy` so every
purge path inherits it by construction rather than by each one remembering.

A hold on the **subject** covers every record about them, because an investigation into a
household's assistance does not know in advance which document will matter.

Placing one requires a reason; **lifting one requires a reason too**, because lifting is what allows
a record to be destroyed. Both are high-risk audited acts. Lifting is recorded, never deleted:
*"who lifted the hold, and when"* is the question after a record turns out to have been destroyed.

---

## 7. Reading the trail costs its own permission, held by a new role

An audit trail nobody can read is theatre. One anybody can read is worse: it is assembled across
every module and is more concentrated than any single record it describes — a search for
`safeguarding.opened` names which residents have protection cases without opening one.

**`audit.view` is deliberately NOT on `lgu_admin`.** The trail records the MSWDO head's own
approvals, their own document reads and their own exports. A head who can read it can see whether
anybody has noticed. The auditee must not be the auditor.

So `Role::DataProtectionOfficer` exists — the same shape as `DisbursingOfficer` in ADR 0023 §3: a
role created so a deliberately unheld permission becomes operable without collapsing the split it
protects. It holds `audit.view` and `privacy.manage` and **no operational permission at all** — it
cannot open a case, read a resident record or approve anything. A DPO who could also open a welfare
file would be auditing records they had themselves been reading, and the trail would stop
distinguishing oversight from access.

**Reading the trail is itself audited**, once per search rather than once per row — a row-level
trail of a hundred-row page would bury the act that matters in the noise of the act that revealed
it. The search records *what was asked for*, by parameter name, and never the answer.

---

## 8. Append-only, structurally

`AuditIsAppendOnlyTest` fails the build if any file but `AuditTrail` inserts into the table, or if
anything anywhere calls `update`, `delete` or `truncate` on it. `AuditEntry` is guarded against
everything and has no timestamps.

Article 5.4 is a sentence in the constitution; this is what makes it a property of the code. A
record that was wrong is corrected by a **new** entry saying so — a trail that can be tidied is a
trail nobody can rely on.

---

## 9. What this TAB did not build

* **A retention sweeper.** The policy answers "may this be purged"; nothing calls it on a schedule,
  because nothing may be purged until the schedule is approved. Wiring a sweeper before then would
  be building the thing whose safety depends on a decision nobody has made (**G-47**).
* **Correction and access request workflows.** The master command asks for *hooks*, and the hooks
  exist: resident correction requests were built in TAB 09, and a subject-access request is an
  export the machinery in ADR 0026 §3 already supports. A dedicated RA 10173 §16 request lifecycle
  is a real piece of work and is **G-48**.
* **An approved schedule or approved legal bases.** By design. See §5.

---

## Consequences

* Every module's audit call now goes through one class, so a new audit column is one edit. That is
  the point, and it cost a dependency inversion to get.
* Nothing in this system will ever delete a record on a schedule until somebody sets
  `PRIVACY_RETENTION_APPROVED=true`. Data accumulates until then, which is the safe direction.
* The LGU must appoint a Data Protection Officer before the audit trail can be read by anybody at
  all. That is intended.
