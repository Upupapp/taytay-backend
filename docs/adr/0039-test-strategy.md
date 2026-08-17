# ADR 0039 — Test strategy

* **Status:** accepted
* **Date:** 2026-08-18
* **Built in:** TAB 34
* **Related:** ADR 0031 §2 (what the suite cannot prove about concurrency), ADR 0036 §5 (the same
  about dispatch timing), ADR 0038 (contract tests)

---

## Context

TAB 34 asked for a test suite focused on domain invariants, authorization, concurrency and
multi-client behaviour. 865 tests already existed, built alongside the features across thirty-three
TABs.

So the work was not "write tests". It was to ask **what class of defect the existing suite
structurally cannot catch**, and to build only that.

---

## 1. The layers, and what each is for

| Layer | Where | Catches |
| --- | --- | --- |
| **Architecture** | `tests/Architecture/` | A rule being violated *anywhere*, including in code written next year. Boundaries, append-only audit, one writer per bucket, no server-side fetch, enforced absences |
| **Unit** | `tests/Unit/` | Value objects, state machines, eligibility guidance |
| **Feature** | `tests/Feature/Api/V1/` | Every endpoint, including its unauthorized path (Article 7.2) |
| **Authorization** | `AuthorizationMatrixTest`, `ApiSecurityTest` | Role, scope, object and property denials — written as *attacks*, not descriptions |
| **Contract** | `ApiContractTest` | The published document diverging from what the API returns |
| **Journey** | `tests/Feature/Journeys/` | **The seams between modules.** New in this TAB |

---

## 2. Why journeys, when 865 tests already passed

A feature test sets up its own fixture, exercises one module, and asserts on that module's output.
That is the right shape for a feature test and it is **structurally blind** to a whole class of
defect:

* a case that is approved but whose requirement was never verified;
* a registration that survives a merge pointing at the wrong resident;
* a citizen endpoint that leaks an internal field only once a case reaches a state no unit test
  ever puts it in;
* **a workflow no single person can complete**, because the permissions were split correctly and
  nobody ever tried to walk the whole thing.

A journey builds state the way the office builds it — through the API, in order, with the real
actors — and then switches back to the **citizen** and asks what they can see. That last step is
most of the value: the question is not "did the write succeed" but "is what the resident is shown
true, complete, and free of the office's internal reasoning".

The eight journeys are the master command's own list, and they use fictional Taytay data
throughout.

### What journey 2 found, immediately

The assistance journey drove every lifecycle step as `lgu_admin` and got a `403` at `endorsed`.

**That is the separation of duties working, not a bug.** The MSWDO head approves what the social
workers recommend and does not write the recommendation and then sign it, so `RequestEndorse` is
deliberately absent from their role (ADR 0016 §6).

But nothing had ever demonstrated it end to end. A per-module test picks whichever role makes its
own assertion pass and never discovers that **no single person can complete the workflow** — which
is the property the LGU actually cares about, and the one that would have been quietly broken by
adding one permission to one role.

The journey now uses two actors, and the split is asserted rather than assumed.

---

## 3. Tests attempt the attack, they do not describe the check

Every authorization test in this suite tries the thing a real caller could try with curl and a
guessed identifier: hold the permission but not the scope, change the verb, substitute an
identifier, keep using a token issued before the role was withdrawn.

**A check that exists and is wrong passes a descriptive test and fails an attacking one.**

Two conventions run through all of them, and the difference between them is deliberate:

* **object-level refusals answer `404`** — a `403` confirms the identifier names something real,
  which is most of what an enumeration attempt wants;
* **function-level refusals answer `403`** — the existence of an approval endpoint is not a secret,
  and a `404` would make a permissions problem look like a broken client.

---

## 4. Detectors assert their own reach

Every scanning test in this repository asserts that it scanned something: file counts, non-empty
fixtures, planted positives. The reason is that **a detector that reaches nothing is
indistinguishable from a codebase with nothing to find**, and it fails in the safe-looking
direction.

This has earned itself repeatedly. `ApiContractTest`'s coverage check caught its own URL matcher
matching nothing; the SSRF scanner's first signature list produced a false positive that would have
been silenced by an allow-list entry permitting a real fetch alongside it.

Every leak scanner also carries a **negative fixture** — a planted secret it must find — because a
redactor or a scanner that cannot detect is worse than none: it is believed.

---

## 5. What this suite cannot prove, stated rather than implied

Three guarantees are asserted **structurally** because the harness cannot exercise them. Each is
recorded here, in the ADR that made the trade, and in the test itself:

| Guarantee | Why not exercised | What is asserted instead |
| --- | --- | --- |
| Event capacity under real concurrency | Single-process suite; SQLite compiles `lockForUpdate()` to an empty string | Every seat decision is taken behind the event row lock (ADR 0031 §2, gap **G-40**) |
| Notification dispatch after commit | `RefreshDatabase` wraps each test in a transaction, so the outermost commit never arrives | `Notifier` queues with `afterCommit()`, and a rolled-back decision leaves no row (ADR 0036 §5) |
| Backup restorability | No production database here | The restore procedure and its cadence are documented (ADR 0037 §4, gap **G-52**) |

Stating these is the point. A suite that claimed to prove them would be worse than one that does
not, because somebody would rely on the claim.

---

## Consequences

* A new endpoint costs a feature test, its unauthorized path, and — if it changes a response shape —
  a regenerated contract document.
* A new module that stores `resident_id` fails `ResidentMergeCoverageTest` until a merge path
  exists. That test has now earned itself three times.
* The journeys are slower than unit tests and run on every suite execution. They are worth it: each
  is a question about the system rather than about a class.
