# ADR 0027 — Global search, filter grammar and saved views

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 22
* Extends: ADR 0012 (scopes), ADR 0016 (case engine), ADR 0022 (safeguarding)

---

## Context

Three acceptance criteria: search never returns an object the caller could not open directly; a
citizen cannot enumerate residents through search; saved filters cannot inject raw SQL.

---

## 1. There is no search index

**Decision: every searcher runs a scoped query against the owning module's table, applying the same
permission and the same barangay scope that module's detail endpoint applies.**

The obvious alternative is a dedicated index — a `search_documents` table, or an external engine.
It is faster and it is wrong here, because **an index maintained alongside the authorization rules
eventually disagrees with them.**

The disagreement is invisible. Nobody notices a search returning one extra row until somebody
clicks it and gets a 404 they should not have been able to provoke — and by then the index has been
answering that way for months. Every rule this system has added since TAB 11 (restricted case
types, barangay scope, protected notes, safeguarding) would need re-implementing in the indexer,
and each one would be a second place to get it right.

Deriving the results from the same queries makes the criterion true by construction rather than by
maintenance. It costs a `LIKE` scan over a few thousand rows, which at Taytay's size is nothing.

**No search index is created**, and the master command does ask for PostgreSQL full-text/trigram
capability. A driver-guarded migration creating trigram indexes on PostgreSQL and skipping them on
SQLite was written first and removed, for two reasons:

* `InfrastructureAlignmentTest::migrations_stay_portable_postgresql` forbids a raw `DB::statement`
  in a migration, and that rule has held since TAB 01 for a good reason — the moment one migration
  is allowed a raw statement, the next one is allowed a slightly less guarded one. Weakening a
  22-TAB-old rule for an optimisation is the wrong trade.
* It is **unmeasured optimisation**, which ADR 0026 §1 already declined for materialised views on
  the same grounds. Taytay's caseload is thousands of rows and the `LIKE` scan runs in single-digit
  milliseconds.

An index has no behavioural effect, so it never needs to be a migration at all: when there is a
measurement it goes in as an operational change, with the exact SQL recorded in gap **G-35**.

Full-text (`tsvector`) is recorded there as *not* the right tool regardless. It stems and tokenises
for natural language, and these are names, addresses and reference numbers: a clerk at a counter
typing "Dela Cru" needs a partial substring match, which a stemmer will not give them. Trigram is
the right index when one is measured.

---

## 2. What is never searched

Case note bodies. Safeguarding detail. Referral reasons. Visit observations.

Those are the four places this system keeps text that a person should not be able to ask questions
of. *"Show me cases whose notes mention 'shelter'"* is a disclosure performed by a search box — the
protected tier of ADR 0022 §3 defeated without ever reading a note.

The filter grammar enforces the same rule from the other direction: `body` and `detail` are not
filterable fields on any entity, so a saved view cannot ask either.

**Result snippets carry a safe title and nothing more** — a name or a reference, the barangay and a
status. No birth date, no address, no sector, no narrative. A snippet is a way to *find* a record,
not a way to read one without opening it.

**An entity the caller cannot read is absent, not refused.** "You may not see these 3 results" is
itself a count, and a count of matching restricted records is most of what somebody probing wants.

---

## 3. The filter grammar

**Decision: a filter is a `(field, operator, value)` triple where the field and operator are keys
in a closed table. Nothing is concatenated.**

The acceptance criterion is held by there being no path from a stored filter to a string that
reaches the database. A field name that is not a key cannot become a column reference — which is
why the defence is a data table rather than a regex or an escaping function.

**Validated on the way in, not only on the way out.** A saved view is executed later, by whoever
loads it. A filter checked only at execution time is a stored query waiting for a code path that
forgets — and the one that forgets will be added in a hurry two years from now, by which point the
row has been in the database long enough to look trustworthy.

**The field list is short and per entity.** It is not "every column": a filterable field is one a
list endpoint already exposes, so a filter cannot reach a column the projection would have
withheld.

`in` is bounded at 100 values. An `in` with ten thousand makes one request cost as much as ten
thousand, and no legitimate saved view needs it. A scalar operator with a structured value is
refused outright rather than cast — that is how an expression gets smuggled into a binding in
frameworks that allow it.

Sorting uses the same table. `sort=id;DROP TABLE` cannot become a column reference because it is
not a key.

**The grammar is published** so a client builds its filter UI from the server's own list rather
than a copy that drifts — the same reasoning as publishing upload limits in ADR 0020. A protected
column is absent from it, so a client cannot even offer the field.

---

## 4. A saved view shares a question, never an answer

Two people opening the same shared view see different rows, because each query is scoped to
whoever runs it. A view carrying its author's scope would be a way to hand somebody a caseload they
cannot otherwise reach — a permission escalation dressed as a convenience.

The payload says so in words, so nobody mistakes a shared view for shared data.

**Sharing costs `saved-view.share`.** A shared view appears in every colleague's list, which makes
it a small piece of the office's shared furniture rather than a personal preference — and one
badly-named shared view ("Suspicious households") is a judgement broadcast to everybody who opens
the list.

Deletion is scoped to the owner: a shared view is somebody else's to remove.

---

## 5. There is no citizen search endpoint

**Decision: none. Not a filtered one.**

A citizen's own records are reachable through `me/*`, which resolves the resident from the token
and has no identifier in the contract to tamper with.

A citizen search that "only returned their own records" would be a resident-enumeration endpoint
one authorization bug away — and the bug would be invisible, because the endpoint would still look
like it was working. **The absence is the control.**

Public content search (the newsfeed, the service catalogue, programmes) is a different thing
entirely and belongs with those modules, where the records are public by design.

---

## Consequences

* Search results and detail endpoints cannot disagree, because they are the same queries.
* Adding an authorization rule to a module automatically applies to search; forgetting to update
  an index is not a possible mistake.
* A saved filter is inert data: it can only name things the grammar names.
* If the dataset ever outgrows `LIKE` scans, a read model goes behind `GlobalSearch` and gets its
  own ADR — including how it stays in step with authorization, which is the hard part.
