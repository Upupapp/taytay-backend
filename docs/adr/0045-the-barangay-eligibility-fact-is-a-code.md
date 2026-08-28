# ADR 0045 — The barangay eligibility fact is a code, not an auto-increment key

* **Status:** accepted
* **Date:** 2026-08-28
* **Related:** ADR 0018 (programmes and eligibility guidance), Article 4 (identifiers exposed to
  clients are UUIDs; never an auto-increment key), Article 6 (expand → migrate → contract),
  evidence ledger L-15

---

## Context

`EligibilityFact::Barangay` was compared against the value `ResidentDirectory::eligibilityFactsFor()`
supplied for a resident, which was `(string) $resident->barangay_id` — the surrogate primary key of
the `barangays` row. A criterion restricting a programme to one barangay therefore had to be
authored as:

```
barangay is 2
```

Nothing validated `2`. `POST admin/programs/{program}/eligibility-criteria` accepted `value` as
`nullable|string|max:191` and stored whatever arrived.

Found during sweep round eight, by tracing what the eligibility engine actually compares rather
than by reading the criterion table.

---

## 1. Why the surrogate key is the wrong identifier here

**It is unexplainable, and this enum is the one place that rule is absolute.**
`EligibilityFact`'s own docblock requires every fact be "something a clerk can look up, point at,
and explain to the person in front of them", on the grounds that a criterion that cannot be
explained at a counter should not be deciding who gets help. Nobody at the MSWDO knows which
barangay is 2.

**It cannot be offered by any client.** `GET /api/v1/barangays` publishes `uuid` and `code` and
deliberately refuses to publish the integer — that controller's docblock says so directly, citing
L-15 and refusing to "entrench that defect in a brand-new endpoint". So the one value the engine
would accept is the one value no console can render a picker for.

**It is not stable across environments, and that is the one that matters.** Auto-increment keys
are assigned by insertion order. The same criterion authored against staging and promoted to
production targets **a different barangay**, silently, with no error at any layer. This system
also imports legacy records (TAB 35) and merges duplicate residents, both of which reorder
insertions. The failure is invisible and it decides who is offered welfare assistance.

---

## 2. Decision

**The `barangay` fact carries `barangays.code`** — `brgy-san-juan` — and criterion authoring
validates every code named against the directory.

`code` rather than `uuid`, and the explainability rule decides it. A UUID is equally stable and
equally publishable, but it is not legible on the criterion, in the audit trail, or at a counter.
`code` is also already what `POST me/kyc` accepts and what the public directory publishes, so this
adds no new vocabulary.

**An unresolvable barangay yields an absent fact, therefore `unknown`, therefore a human.** If the
`barangays` row has gone, nobody can currently say where the applicant lives — which is not the
same as saying they live nowhere. This is the treatment ADR 0018 already gives absent income, and
for the same reason: absence must never read as refusal.

---

## 3. What was rejected

**Accepting either an id or a code.** Tolerating both would keep the unstable form legal, and the
migration below would have nothing to converge on. The ambiguity would also have to be resolved
somewhere, and "is `2` an id or a code" is a question with a wrong answer available.

**Validating with `exists:barangays,code` as a validation rule.** `is-one-of` packs several codes
into one field with a `|` separator, which no single `exists` rule can express. The check is a
method, and it splits on the same separator `EligibilityGuidance::splitList()` uses — two readings
of one stored string must not diverge.

**Leaving existing rows alone.** A criterion still reading `2` would silently mean "no barangay"
once the fact changed, widening or narrowing a live programme with no record. Hence the migration.

---

## 4. Migration

`2026_08_18_220000_move_barangay_criteria_to_codes` rewrites the stored `value` of every `barangay`
criterion from ids to codes, preserving the `|` separator. Forward-only and safe against a
populated table, per Article 6.

**A segment that is not a known id is left exactly as it is.** Two cases reach that branch: a value
that is already a code, and an id whose barangay no longer exists. Dropping the second would widen
the programme to everybody the criterion used to exclude — a silent grant of eligibility.
Replacing it would invent a barangay. Left in place it matches nobody and reads `not-met`, which is
what it already did, and is visible to anybody reading the rule.

`down()` restores the ids **this** database holds. An earlier draft left it inert, arguing that
surrogate ids are the defect being removed; `MigrationSafetyTest` refused it and was right. That
argument is about what the values mean *across* environments and does not make the rewrite
irreversible *within* one, where code → id is an ordinary lookup. An irreversible migration makes
rollback a code change decided during an incident.

---

## 5. What proves it

Both halves were proven red by restoring the defect verbatim:

| Restored defect | Failing test | Observed |
| --- | --- | --- |
| fact carries `barangay_id` | `the_barangay_fact_is_the_published_code_not_the_auto_increment_key` | `'1'` against `'brgy-san-juan'` |
| authoring check removed | `a_criterion_naming_a_barangay_that_does_not_exist_is_refused` | `brgy-san-jaun` stored, 201 |

The first asserts the **observed value**, not the outcome. `met` alone would also be produced by a
surrogate key matched against a surrogate key — the exact arrangement being removed — so the
outcome cannot distinguish the fix from the defect and the value can.

`BarangayCriteriaMigrationTest` covers the rewrite itself, including the two branches most likely
to be wrong in production: a criterion on another fact whose value happens to be a valid barangay
id must not be rewritten, and running the migration twice must be a no-op.

---

## 6. What this does not fix

**L-15's read side was closed immediately after this, in the same sweep.** Every response that
names a barangay now carries `barangay_code` beside `barangay_id`, resolved through
`Modules\Shared\Application\BarangayCodes`. That is the expand step only: the integer is still
emitted, and dropping it is a breaking change behind a client cutover. See the evidence ledger's
L-15 entry.

**`barangays` still has no owning module.** The resolver sits in `Shared` because five modules
already read that table directly and the dependency graph forbids most of them asking each other —
`AccessControl` may not call `ResidentProfile`. It imports no module, which is the constraint
Article 2.3 states and `ModuleBoundaryTest` enforces, and it follows `IdempotencyService`, which is
also cross-cutting infrastructure in `Shared` that touches persistence. **The cleaner answer is a
`Reference` module owning the table, with the directory controller moved into it** — a registry
entry, a boundary-map row and an ADR of its own. Recorded here rather than left as a silent
decision.
