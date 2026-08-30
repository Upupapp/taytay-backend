# ADR 0050 — The suite runs on the engine production uses

* **Status:** accepted
* **Date:** 2026-08-30
* **Related:** Article 1 (PostgreSQL in real environments, SQLite in tests), Article 7 (definition
  of done), ADR 0047 (the second optimisation sweep), ADR 0049 (validity windows)

---

## Context

The tests run on SQLite and production runs on PostgreSQL. Article 1 chose that deliberately and
the choice is still right: an in-memory SQLite database makes a 1136-test suite finish in fifteen
seconds, and it keeps migrations portable.

What was missing is the other run. **Six defects have now been found by executing the same suite
against PostgreSQL, every one of them behind tests that were green on SQLite:**

| # | Defect | Effect |
| --- | --- | --- |
| 1 | `report_exports.stored_file_id` typed `uuid` while holding a storage path | the entire export feature 500ed in production |
| 2 | QR nonce replay | the verifier got a 500 instead of "already used" |
| 3 | Idempotency lost race | a 500 on the money-write path |
| 4 | Unvalidated `batch_id` filter | a 500 from malformed client input |
| 5 | Import callback contract | a trap for the first real caller |
| 6 | Role validity windows (ADR 0049) | a staff member held no permissions for up to a second after being granted them |

Two engine differences account for most of them. SQLite's dynamic typing stores any string in a
`uuid` column; and a failed statement does not abort the surrounding transaction on SQLite, while
on PostgreSQL it does (`25P02`), so a caught unique violation that behaved locally poisons the
whole transaction in production.

**All six came from running PostgreSQL once.** Nothing made it happen again, which makes the
finding rate a property of somebody remembering rather than of the code.

## Decision

`composer check:pg` runs the full suite against PostgreSQL, and Article 7 requires it to pass
before a push — the moment at which, under Article 9, the work becomes a publication.

`scripts/postgres-suite.sh` provisions a throwaway cluster, runs the suite, and removes it again,
so the gate needs no standing server and leaves nothing behind. About thirty seconds end to end.

**It fails when no PostgreSQL is installed. It does not skip.** A green gate is a claim that the
code was exercised against the engine production uses, and a skip would make that claim falsely.
`--existing` points it at a server somebody already has.

`composer check` is unchanged and still runs SQLite, because the fast loop is worth keeping.

## Consequences

* **The gate is proven to catch what SQLite cannot.** With ADR 0049's migration reverted,
  `composer test` passes and `composer test:pg` fails. That is the whole case for it, and it was
  measured rather than argued.
* **"Not on PATH" is not "not installed", and the script encodes that.** ADR 0047 recorded this
  gap as blocked on a missing Docker runtime while Postgres.app sat installed on the same machine,
  never started, its binaries simply not on `PATH`. The script looks in the places a real install
  puts them before concluding anything, and ADR 0047's section has been rewritten.
* **The port is chosen by probing, never fixed.** A fixed port once connected this suite to another
  agent's PGlite instance, which answered `select version()` convincingly enough that the mistake
  took a while to notice. A port that is already open belongs to somebody.
* **The socket directory lives directly under `/tmp`** whatever the data directory does. A Unix
  socket path has a hard limit near 103 bytes and the server fails to start with a message that
  does not mention it.
* `fsync` is off in the throwaway cluster: it is destroyed at the end of the run, so durability
  buys nothing and costs a great deal on a suite that migrates repeatedly.
* **This does not make SQLite results worthless**, and the reverse framing would be the wrong
  lesson. The fast suite catches almost everything and catches it in fifteen seconds. What it
  cannot do is speak for the engine it does not run on.
