# ADR 0036 — Queues, scheduling, caching and consistency

* **Status:** accepted
* **Date:** 2026-08-17
* **Built in:** TAB 31
* **Related:** ADR 0025 (notification is transport), ADR 0026 §3 (exports), ADR 0028 §4 (a
  scheduled post publishes at most once), ADR 0031 §2 (the event row lock), ADR 0034 §5 (retention
  is unapproved)

---

## Context

Five jobs existed. All five ran on `default`, three of them had no retry policy, none had a
timeout — and **two of them had never run at all**, because `routes/console.php` still held
Laravel's `inspire` stub.

That last one is worth sitting with. `PublishScheduledPosts` was written in TAB 23 with a careful
conditional `UPDATE` so a post publishes at most once. `SweepOverdueReferrals` was written in
TAB 16 to raise a task when a referral goes unanswered. Both were tested. Both worked when
invoked. Neither was ever invoked.

Every test was green throughout, and that is exactly the problem: **a feature test dispatches the
job directly and asserts the right thing happens**, which proves the job is correct and says
nothing about whether anything calls it. A job with no caller is indistinguishable from a job that
works — the same shape as `feedback: foundations without callers`, and the third time this project
has met it.

---

## 1. Named queues, by consequence rather than by throughput

`WorkloadQueue` — `default`, `notifications`, `exports`, `media`, `scheduled-content`,
`integrations`. The names are the master command's verbatim, so the worker configuration in the
runbook and the code cannot drift into different vocabularies.

**The argument is not throughput. It is that these workloads have different consequences when they
are late:**

* a **notification** that is late is a family who did not hear they were approved;
* an **export** that is late is somebody refreshing a page;
* **media** that is late is a post published without its picture;
* **scheduled content** that is late is an advisory that went out after the event it warned about.

A single queue makes the slowest of those the latency of all of them. One large export — a CSV of
a whole barangay — and every approval notification sits behind it for four minutes.

Each job routes itself in its constructor rather than at the dispatch site, because a job that must
run somewhere specific should not depend on every caller remembering where.

### Timeouts are per class

Thirty seconds for a notification, fifteen minutes for an export. A single global timeout is either
too short for the export or useless for the notification — a push that has not finished in thirty
seconds is stuck, while an export of ten thousand rows legitimately takes minutes.

`$timeout` cannot call a method in a property initialiser (the first version tried and would not
parse), so each job repeats its class's number. That duplication is exactly the kind that drifts,
so `QueueConventionsTest` checks the two agree rather than trusting them to.

---

## 2. Retries are chosen, not inherited

Three attempts for a notification with backoff `[10, 60, 300]`; two for an export; **one** for a
sweep.

Widening gaps rather than a fixed delay: whatever made the first attempt fail is usually still true
a second later, and a tight retry loop turns one struggling dependency into a self-inflicted denial
of service against it.

**One attempt for a sweep, deliberately.** The sweeps are idempotent and re-run on a schedule, so
**the next run is the retry** — and a retried sweep racing the next scheduled one is two sweeps
where the design assumes one.

---

## 3. The scheduler

| Entry | Cadence | Why |
| --- | --- | --- |
| `PublishScheduledPosts` | every minute | A post scheduled for 09:00 that publishes at 09:04 has missed the point of being scheduled |
| `SweepOverdueReferrals` | hourly | Overdue is measured in days; the task lands in a queue a human looks at |
| `PurgeExpiredExports` | hourly | An `expires_at` nothing acts on is a comment |
| `sanctum:prune-expired` | 23:20 | Not midnight — that is when every scheduled job anybody ever wrote runs at once |
| `queue:prune-failed --hours=168` | 23:40 | **A week, not a day**: the failed-jobs table is how somebody finds out why a notification never arrived, asked days later by a resident who waited, then asked at the counter |

Every entry carries `withoutOverlapping()` and `onOneServer()`. The second matters most later:
production will eventually have two API nodes behind a NodeBalancer (ADR 0004), and both would run
the scheduler — without it, the day a second node is added is the day every sweep starts running
twice.

`runInBackground()` is deliberately absent. `Schedule::job()` only *dispatches*; the work happens on
a worker, so there is nothing to background — and Laravel refuses the call outright, which is how
this was found: the first version of the file had it and `schedule:list` would not render.

### `PurgeExpiredExports`: the file goes, the row stays

A person-level export lives 24 hours and an aggregate one a week (ADR 0026 §3), because once a
spreadsheet of a barangay's beneficiaries is on a laptop none of this system's authorization
applies to it.

But the **record** of the export is evidence: who asked, what they asked for, and what they were
allowed to see at that moment. Deleting the row with the file would destroy the answer to *"why
does this spreadsheet exist"* at exactly the moment the spreadsheet outlives the system — which is
the case where somebody is asking.

**This does not contradict ADR 0034 §5.** That stops scheduled deletion of *records* until the DPO
approves a retention period. This deletes a derived file whose lifetime was chosen by the export
design itself and is already the shortest in the system; waiting for approval would mean holding
person-level caseload copies indefinitely, which is the opposite of what the pending approval
protects.

---

## 4. Cache keys: two shapes, and a third for tokens

`CacheKey` is the only place a cache key is built. The failure it exists to prevent is never a
decision somebody makes — it is a key like `programs.list`, written by somebody caching what is
genuinely a public catalogue, and then six months later the query behind it gains a
permission-dependent branch so an admin sees drafts. The key does not change, because nothing about
adding a branch to a query suggests revisiting a cache key, and from then on **whoever warms the
cache decides what everybody sees**.

* `public()` — identical for every caller, including anonymous ones. **Only** when an anonymous
  caller would get the same answer; if the query branches on a permission at all, it is not public.
* `forActor()` — keyed by subject **and by a fingerprint of their effective authority**.
* `forOpaqueToken()` — for Identity's MFA challenge, which is neither: at the moment it is read
  **nobody is authenticated yet**. Hashed, so the store never holds the value a caller presents.

### Why the authority fingerprint

Keying by subject alone is not enough. A caseworker's barangay grant can be widened or withdrawn
mid-shift (ADR 0012), and a scope that narrowed at 10am must not still be serving 9am's rows at
10:05. The fingerprint covers permissions, roles and scope, so a change to any of them produces a
different key and the old entry is never read again — **invalidation by construction** rather than
by finding and forgetting every key an actor might hold.

Key parts that could contain a separator are hashed, so a caller-supplied filter value cannot forge
a different key by splitting one.

**No cache tags.** Tags require a tag-aware store, and this application must run on `array` in
tests and `database` on a small deployment. A convention that only works on Redis breaks the day
somebody runs the suite without it.

---

## 5. After commit

Every queued job dispatched from a write path uses `->afterCommit()`, and
`ConsistencyTest::every_job_dispatched_from_a_write_path_is_queued_after_commit` fails the build
otherwise.

**Domain events are exempt, and the exemption is the interesting part.** `Event::dispatch()` for
`ResidentMerged`, `CaseStatusChanged` and the rest runs **synchronously inside the caller's
transaction on purpose** — `ResidentMerged` repoints six modules' rows and must roll back with the
merge (ADR 0019 §4). Requiring `afterCommit()` there would require the opposite of what they are
for, and the first version of this scan did exactly that.

### What the suite cannot prove

The row-level half of the criterion is tested: a rolled-back decision leaves no notification behind.
The **dispatch timing** cannot be observed in-process — `RefreshDatabase` wraps every test in its
own transaction, so an inner `DB::transaction` is a savepoint and the outermost commit never
arrives. Asserting on `Queue::fake()` there would be asserting on the harness.

So the mechanism is asserted structurally instead, and said to be so. Same trade as the row lock in
ADR 0031 §2.

---

## 6. Locks: only where contention is real

| Where | Mechanism | Why |
| --- | --- | --- |
| Event capacity, waitlist promotion | `SELECT … FOR UPDATE` on the event row | Two people racing for the last seat (ADR 0031 §2) |
| Release confirmation | row lock + idempotency key | Money moves once |
| Resident merge | transaction + synchronous listeners | Six modules repoint together or not at all |
| Scheduled publish | conditional `UPDATE … WHERE status = 'scheduled'` | Atomic without a lock; two workers produce one update and one no-op |
| Scheduler entries | `withoutOverlapping()` + `onOneServer()` | A sweep must not race itself, or itself on another node |

**Nowhere else.** A lock taken where there is no contention is a serialisation point that only
shows up under load, and the conditional-update case is worth noting as the one that needs no lock
at all: an atomic `WHERE` clause is cheaper and cannot deadlock.

---

## Consequences

* Workers must now be configured per queue. The runbook lists the six names, and
  `QueueConventionsTest` asserts the enum matches — **a queue a job dispatches to but no worker
  consumes is a job that never runs, and it fails silently** because the row sits looking pending.
* Adding a sweep means two edits: the job, and `routes/console.php`. Forgetting the second now
  fails the build rather than going quietly unnoticed for eight TABs.
* Nothing in this system caches an authenticated response yet. `CacheKey` exists so that the first
  thing to do so cannot get the key wrong.
