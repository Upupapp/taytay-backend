# ADR 0049 — A role was not in force at the instant it was granted

* **Status:** accepted
* **Date:** 2026-08-30
* **Related:** ADR 0012 (permissions and scopes), Article 3.5 (deny by default), Article 5.3
  (least privilege), ADR 0047 (the PostgreSQL gap)

---

## Context

`DatabaseRoleAssignmentRepository` selects live assignments with `valid_from <= now()`. Laravel
binds a timestamp using the connection's date format, `Y-m-d H:i:s`, which **drops the
microseconds**. The filter therefore asked for assignments at or before the START of the current
second, and skipped any role granted during it.

A staff member provisioned and then authorized within the same second was refused a permission they
demonstrably held. It fails closed, which is the safe direction and still wrong.

Underneath it sat a second fault. `valid_from` defaults to `CURRENT_TIMESTAMP` into a column of
precision 0, and **PostgreSQL rounds a timestamp to fit that precision rather than truncating it**.
A row written at `14:16:45.548` was stored as `14:16:46` — half a second in the future.

## Decision

Fix both halves. `valid_from` and `valid_until` on `role_assignments` and `staff_barangay_grants`
get sub-second precision, and the repository binds the clock with its microseconds intact.

Compare at the precision the column stores; never loosen the comparison. A filter that tolerated a
future `valid_from` would also admit a grant that has not started yet — the opposite defect, and a
worse one.

## Consequences

* **The precision change ALONE makes it worse, and that is worth stating plainly.** Rounding to
  whole seconds failed roughly half the time, decided by the sub-second fraction; an exact
  microsecond `valid_from` compared against a truncated bound fails every time. The first version
  of this fix shipped only the migration and was measured, not assumed, to be a regression.
* **SQLite could never show it.** It stores the timestamp as text and truncates the same default,
  so the comparison held and the whole suite was green on the test driver while the production
  driver failed closed. This is the sixth defect found by running on PostgreSQL — see ADR 0047.
* **The migration runs on PostgreSQL only.** Not lock-in under Article 1: column precision is a
  concept SQLite lacks, and running it there was actively harmful. `->change()` on SQLite rebuilds
  the table from the Blueprint, and the rebuild dropped the check constraint behind
  `role_assignments.scope_type` — a timestamp migration silently removing a guard on an
  authorization column. `AuthorizationMatrixTest` caught it.
* **Two behavioural tests were written, discarded, and are described in the test file**, because
  both were green against the reverted fix. One set `valid_from` explicitly and lost its
  microseconds to the same serialisation being tested. The other wrote twelve rows expecting twelve
  independent sub-second fractions; PostgreSQL's `CURRENT_TIMESTAMP` is the TRANSACTION's start
  time, identical for every row, and `RefreshDatabase` puts the test in one transaction. Twelve
  rows were one coin flip.
* The guard that ships writes `valid_from` as a raw literal carrying microseconds and freezes the
  clock inside the same second. It fails on both drivers when the fix is reverted.
* **How it surfaced:** `ApiSecurityTest` failed IN ISOLATION on PostgreSQL while passing in the
  full suite. A test that passes in the suite and fails alone is a finding, not a flake.
