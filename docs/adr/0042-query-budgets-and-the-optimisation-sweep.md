# ADR 0042 — Query budgets and the optimisation sweep

* **Status:** accepted
* **Date:** 2026-08-18
* **Related:** Article 4 (collections are always paginated), ADR 0033 §3 (an absent rendition is a
  real answer), ADR 0039 (test strategy)

---

## Context

A sweep of the finished backend for performance and dead weight, after all thirty-six TABs.

**Everything below was found by measuring, not by reading.** That distinction produced the whole
result: a static scan of the code flagged ten possible N+1 queries and twenty-one possibly-missing
indexes, and **almost all of those were wrong in both directions** — most flagged candidates were
fine, and the worst real problem was not flagged at all, because it was hidden two calls deep
inside a method whose name promised a lookup rather than a query.

---

## 1. The measurement

Query count for one request, at a small page and a larger one. An N+1 is not a number — it is a
**slope**.

| Endpoint | before (1 row → n rows) | after |
| --- | --- | --- |
| `GET /admin/events/{event}/registrations` | 11 → 18 (8 rows) | **11 → 11** |
| `GET /newsfeed` | 7 → 14 (8 posts) | **7 → 7** |
| `GET /newsfeed` *with images* | 10 → 25 (6 posts) | **9 → 9** |
| `GET /events` | 4 → 4 | unchanged |
| `GET /me/cases` | 5 → 5 | unchanged |

Three real defects, three endpoints already correct.

### What each one was

**The registrant list resolved a resident's name per row.** At a feeding programme with two hundred
registrants, two hundred round trips to render one page — degrading exactly when the office is
busiest. Fixed with `ResidentDirectory::summariesFor()`, one query for the page.

**The newsfeed loaded each post's media relation separately.** Fixed by eager-loading.

**A post's public image URLs cost three queries per post** — one for the stored file, one per
variant. A feed page is twenty-five posts: seventy-five avoidable round trips on **the endpoint
every resident opens first**, over the connection least able to afford them. Fixed with
`MediaPublisher::publicUrlsFor()`, two queries whatever the page size.

### The fallback that made it worse

The first attempt at the third fix kept a per-file fallback for rows the batch had no entry for.
It measured **27 queries against the 25 it replaced** — it paid for the batch and then did the work
anyway.

The error was treating an absent entry as a cache miss. It is not: **it is a real answer** meaning
the image has no published renditions, which is the normal state for a draft or a failed derivation
(ADR 0033 §3). The signature now distinguishes "no map was supplied" (`null`, the detail endpoint
rendering one post) from "asked, and there are none" (an absent key).

---

## 2. The regression test asserts a shape, not a number

`QueryBudgetTest` compares a small page against a larger one and allows **zero** growth.

Asserting "the feed costs 7 queries" would fail every time somebody legitimately adds a lookup, and
would be relaxed by whoever hit it until it asserted nothing. Whether a page costs 7 or 9 is a
budget to argue about; whether that number **changes when the page gets longer** is a defect.

The tolerance is zero rather than "a few", because a per-row query either exists or it does not and
a tolerance is a place for one to hide.

**The gate was mutation-tested**: reverting one fix made it fail (8 → 15) with the message naming
the endpoint. A green new gate proves nothing until it has been watched to fail.

---

## 3. The indexes were already right

The static scan flagged twenty-one columns as filtered-but-unindexed. **Every one was a false
positive** — each was either a compound filter led by an indexed column, or the non-leading column
of an existing composite index:

* `event_registrations.status` — always filtered with `event_id`, covered by
  `idx_event_registrations_queue`;
* `newsfeed_posts.publish_at` and `is_pinned` — the public feed filters `status` and ranges on
  `publish_at`, then sorts by `is_pinned`, which is exactly `idx_newsfeed_public`;
* `consent_records.active_key` — always with `subject_id`, covered by `uniq_consent_active`;
* `account_resident_links.status` — always with a foreign key.

**No index was added, and that is the finding.** Adding twenty-one indexes on a scan's say-so would
have cost write performance on every insert for no read benefit — and would have looked like
diligence.

A note on honesty: this could not be settled by `EXPLAIN`, because the development database holds a
few dozen rows and Postgres sequentially scans everything at that size. It was settled by reading
each query against each index, which is the right tool for the question.

---

## 4. Dead code: two findings out of thirty-two candidates

A scan for public methods with no call site returned thirty-two. Most were false positives —
Eloquent relations invoked as properties, module service providers registered dynamically from
`config/modules.php`, and enum helpers that are a module's published vocabulary.

Several genuinely uncalled ones are **known gaps, correctly**: `FileStore::purge()` has no caller
because no retention sweeper may exist until the DPO approves a schedule (G-47);
`DeviceService::activePushTokensFor()` has none because the FCM transport is unwired (G-33).
Deleting either would delete the foundation the gap describes.

Two were worth acting on.

### `Notifier::forRecipient()` — removed

Unreachable, duplicated the query its controller already builds, and returned an **unbounded
`->get()`**. Article 4: collections are always paginated, never an unbounded list.

Not a live bug — nothing called it. A loaded gun: the next caller would have got an unbounded query
over a table that grows with every notification the LGU ever sends.

### `VerificationTier::mayHoldCredential()` — wired in

The named rule existed with the ADR 0011 reasoning in its docblock, and nothing called it. The
credential path asked `isVerified()` instead — the same comparison, expressed generically.

Now the credential path asks the named question. Both compare the same tier today, and that is
precisely the problem with the generic one: it says what the tier **is**, not what the tier
**permits**. If the LGU ever decides a partially-verified resident may hold a limited credential,
the rule changes in one named place rather than in whichever tier comparisons somebody manages to
find.

---

## 5. One unbounded list, bounded

`GET /admin/exports` returned every export the caller had ever requested — the last unbounded
collection in the API.

**Bounded with a limit rather than paginated.** Moving to the `page` envelope would change the
response shape, which `CHANGELOG_API.md` classes as breaking and requiring `/api/v2` — a
disproportionate answer for a list whose rows expire in 24 hours to a week (ADR 0026 §3). A hundred
covers every export that still has a file behind it, several times over.

Recorded in the changelog as behavioural rather than breaking, which is the distinction that file
exists to make.

---

## Consequences

* Three endpoints now cost a fixed number of queries at any page size, and the build fails if that
  regresses.
* Two batch lookups exist alongside their single-row forms. A caller rendering a list must use the
  batch; the test is what enforces it.
* No index changed. The next person to wonder about indexes can read §3 instead of repeating the
  scan.
