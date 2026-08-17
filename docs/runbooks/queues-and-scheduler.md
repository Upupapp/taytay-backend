# Queues and scheduler — operations

Status: **what must run for this backend to be correct**, not just fast.

Reasoning: [ADR 0036](../adr/0036-queues-scheduling-caching-and-consistency.md).

---

## The one thing that must not be got wrong

**A queue a job dispatches to but no worker consumes is a job that never runs — and it fails
silently**, because the row sits in the queue looking pending. Nothing errors, nothing alerts, and
the symptom is a resident who never got a text message.

So the worker configuration below must cover **all six** queue names. `QueueConventionsTest` asserts
the application's enum matches this list; nothing can assert that your supervisor config does.

---

## The queues

| Queue | Carries | Timeout | If it is late |
| --- | --- | --- | --- |
| `notifications` | every outbound message | 30s | **a family did not hear they were approved** |
| `scheduled-content` | publish sweep, referral sweep, export purge | 120s | an advisory goes out after the event it warned about |
| `default` | anything with no better home | 120s | — |
| `integrations` | outbound calls to other systems | 60s | — |
| `media` | image derivation, upload processing | 300s | a post publishes without its picture |
| `exports` | report and registrant CSV builds | 900s | somebody refreshes a page |

The order above is the **priority order** for a single worker. `notifications` first because it is
the only queue whose lateness is felt by a resident; `exports` last because it is slow by nature and
nobody is waiting.

---

## Workers

### A single small deployment

One worker, priority-ordered. Documented as a conscious availability trade-off, which the master
command permits for an initial deployment:

```
php artisan queue:work redis \
  --queue=notifications,scheduled-content,default,integrations,media,exports \
  --tries=1 \
  --max-time=3600
```

`--tries=1` on the **command** is deliberate: each job declares its own `$tries`, and a command-line
value would silently override the per-job policy that ADR 0036 §2 chose.

`--max-time=3600` restarts the worker hourly. A long-lived PHP process accumulates memory and, more
importantly, keeps running the code it booted with — a worker that has not restarted since before a
deploy is running the old application.

### When exports start delaying notifications

Split the slow queues onto their own worker:

```
# worker A — everything a person is waiting on
php artisan queue:work redis --queue=notifications,scheduled-content,default,integrations --max-time=3600

# worker B — the slow ones
php artisan queue:work redis --queue=media,exports --max-time=3600
```

### Supervisor

Workers must be restarted on deploy, or they keep running the previous release:

```
php artisan queue:restart
```

That signals workers to exit after their current job; supervisor starts them again. **Run it after
the new code is in place**, never before — a worker that restarts into the old code has achieved
nothing.

---

## The scheduler

One cron entry, on **one** host:

```
* * * * * cd /var/www/lguids && php artisan schedule:run >> /dev/null 2>&1
```

Every scheduled entry also carries `onOneServer()`, so running the cron on two nodes is survivable —
but the cache lock that makes it survivable needs a **shared** store. With a per-node cache, both
nodes hold their own lock and both run everything.

### What is scheduled

| Entry | Cadence | Deletes anything? |
| --- | --- | --- |
| `newsfeed:publish-scheduled` | every minute | no |
| `welfare:sweep-overdue-referrals` | hourly | no |
| `reporting:purge-expired-exports` | hourly | **the export FILE only** — the row survives |
| `identity:prune-expired-tokens` | 23:20 | expired tokens |
| `queue:prune-failed` | 23:40 | failed-job records older than a week |

Verify with `php artisan schedule:list`.

**Nothing here deletes a resident record, a case or a document.** Record retention is unapproved and
`RetentionPolicy` refuses everything until the DPO signs it off (ADR 0034 §5).

---

## Redis

* **Never publicly reachable.** Private network path only; port 6379 is not exposed (Article 8.6).
* Queue, cache and locks may share one instance on a small deployment. Separate them when metrics
  justify it, not before.
* Staging and production **never** share an instance. A shared one means a staging worker can pick
  up a production job.

---

## Failure handling

* Failed jobs land in `failed_jobs` and are pruned after **a week**, not a day — the question "why
  did this notification never arrive" is usually asked several days later.
* `php artisan queue:failed` lists them; `queue:retry <id>` re-runs one.
* **Retrying is safe.** Every job in this system is idempotent or explicitly single-attempt
  (ADR 0036 §2), and `ConsistencyTest` proves the two riskiest — the export purge and the publish
  sweep — do nothing harmful on a second run.

### What to watch

| Signal | Means |
| --- | --- |
| `notifications` depth rising | a provider is failing, or the worker is not running |
| `scheduled-content` depth > 1 | a sweep is overlapping; check `withoutOverlapping()` is effective, i.e. the cache lock is shared |
| any queue with depth and **no worker** | the silent failure at the top of this page |
| `failed_jobs` growing | look at the exception before pruning; a week is not long |

---

## Deploy order

1. Put the new code in place.
2. Run migrations.
3. `php artisan queue:restart` — **after** step 1, so workers boot the new code.
4. Confirm `php artisan schedule:list` renders. If it throws, the scheduler is not running at all
   and the failure is silent.
