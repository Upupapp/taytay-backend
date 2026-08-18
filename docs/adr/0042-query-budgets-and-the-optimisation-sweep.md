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

## 6. The second round, and the worst defect in the codebase

Round one measured `/admin/cases`, `/admin/residents` and a handful of others **once each, on
whatever rows happened to exist**. That proves nothing: an N+1 is a slope, and one point has no
slope. Round two re-measured six endpoints at two row counts each.

Five were genuinely flat. The sixth was this:

| Endpoint | before (1 row → 6 rows) | after |
| --- | --- | --- |
| `GET /admin/cases/{case}/requirements` | 17 → 77 | **7 → 7** |
| `GET /me/cases/{case}/requirements` | 12 → 27 | **7 → 7** |

**Twelve queries per requirement.** The projection resolved the same document four times over:

1. `currentVersion()` for the version itself;
2. `isSatisfied()`, which called `currentVersion()` again;
3. `isOutstanding()`, which called `isSatisfied()`, which called it a third time;
4. `outstandingFor()`, which re-ran the whole list query and resolved every document a fourth
   time — for a **count**.

Each resolution cost three queries (the document, its live versions, the file). A case with twenty
requirements cost roughly 240 round trips to render one page, on the screen a caseworker has open
while the applicant stands at the counter.

Fixed with `DocumentLibrary::currentVersionsFor()` — the batch form, three queries for the page —
and by splitting the *rule* from the *lookup*: `satisfiedBy()` and `outstandingGiven()` take a
version somebody already resolved, while `isSatisfied()`/`isOutstanding()` remain the same rule
with the lookup attached. One definition, two entry points, so the list path cannot drift from
the single-row path.

### The fixture that nearly hid it

**The first measurement of this endpoint said flat, and it was wrong.** The probe created
requirements with no document attached — and every lookup in the projection returns `null` without
querying when the document id is null. It measured the empty path, perfectly, and reported success.

It was caught only because the probe also printed **how many rows it had actually created**. The
same report caught a second dead fixture in the same run: a tasks measurement over an empty list,
because the creation payload was silently rejected.

Hence `assertHasDocuments()` in the permanent test: a query-budget test whose fixture produces
nothing measures nothing and passes. **A measurement must assert its own reach.**

The gate was mutation-tested — reverting the projection to the per-row lookups produced 13 → 43
and 12 → 27, both naming the endpoint.

---

## 7. A security test that ran zero assertions

Unrelated to performance, found by asking PHPUnit which tests report no assertions.

`the_origin_allow_list_is_never_a_wildcard_with_credentials` was written as bare conditionals over
the live config:

```php
if (config('cors.supports_credentials') === true) { ... }
foreach ($patterns as $pattern) { ... }
```

In the test environment credentials are off and the pattern list is empty, so **every branch was
false and the test ran zero assertions**. It passed regardless of what production shipped — a
wildcard-with-credentials CORS policy, the exact thing it names, would not have failed it.

Rewritten as a predicate (`corsDanger()`) pointed at both the shipped config *and* at three shapes
it must refuse.

**The negative fixtures immediately failed** — and the bug they found was in the rule itself. The
Netlify check was `str_contains($pattern, 'netlify.app')`, but these are regular expressions, so a
real one spells the host `netlify\.app`. The check would have missed every actual pattern.

So the original test was broken twice: it never ran, **and it would not have caught what it was
written to catch if it had**. Only the negative fixture could find the second fault, because the
first one hid it.

The neighbouring `the_origin_allow_list_denies_by_default` set `cors.allowed_origins` to `[]` and
then asserted it was `[]` — a test of Laravel's config repository. It now evaluates
`config/cors.php` with the environment variable absent, which is the question that matters.

A sweep of all 896 tests found exactly one other zero-assertion case, and it is correct:
`expectNotToPerformAssertions()` declaring that `authorize()` returns without throwing.

---

## 8. A privacy test that failed one run in three

`no_government_identifier_is_seeded_at_all` scanned `json_encode()` of every seeded resident row
for a PhilSys-shaped `\b\d{4}-\d{4}-\d{4}\b`. The serialised row includes the **UUID primary key**.

**3.4% of the UUID7s this system generates contain that substring by chance** — measured over
twenty thousand of them. `01a010cb-6340-7003-8211-317d1ee20e7f` contains `6340-7003-8211`: three
consecutive all-numeric hex groups, which is unremarkable when a quarter of hex characters are
digits.

With thirteen seeded residents that is a **36% failure rate**. It failed twice during this sweep
and was initially mistaken for a regression from the query work.

This is worse than an ordinary flake because of what the test guards. A privacy check that fails
for an unrelated reason one run in three is a check somebody eventually weakens or deletes — and
what they delete is the only thing standing between a demo seeder and a plausible-looking
government identifier in a database.

Now scanned field by field with `uuid` excluded, plus two guards, both mutation-tested:

* **the scan must reach something** — excluding one column is one edit away from excluding them
  all, and a scan over nothing passes silently (mutation: reach 0 → *"examined almost no seeded
  values"*);
* **the pattern must still catch a real identifier** — otherwise narrowing the scan is
  indistinguishable from switching it off (mutation: pattern neutered → *"no longer recognises a
  government identifier"*).

The first attempt at both mutations appeared to *pass*, which would have meant the guards were
useless. They had not applied — a shell heredoc had mangled the backslashes in the regex being
substituted. **A mutation that does not change the file is not a mutation test**, and confirming
the edit landed is part of running one.

Three consecutive full-suite runs are now clean.

---

## 9. Round three: the gate that was lying, and five more N+1s

54 GET endpoints render a collection. Six were gated; building fixtures for the other 48 was
disproportionate, so a **structural detector** narrowed the field first — used only for its
positives, because its negatives prove nothing.

### The detector had to earn its negatives, and initially could not

Held to a positive control — the three pre-fix defects from §1, read out of git history — the
first version **missed two of three**. It matched one shape, `->map(fn … => $this->proj(…))`,
while the codebase renders lists in four:

| shape | where |
| --- | --- |
| `->map(fn … => $this->proj($r))` | the one it matched |
| `array_map(fn … => $this->proj($r), $rows)` | promotion lists |
| `->map(fn … => [ … inline … ])` | media renditions |
| **`ApiResponse::page($page, fn … => $this->proj($r))`** | **most list endpoints** |

The fourth is the dominant one, because Article 4 mandates pagination — so the detector was blind
to nearly every list in the system while appearing to work.

It then failed the control for a second reason worth recording. The closure filter had been
written through a shell heredoc that turned `\b` into a literal **backspace byte** (`\x08fn\s*\(`),
so the regex silently never matched. The file *looked* correct when read back — the control byte is
invisible. Only the positive control caught it. **A detector without a positive control is a
detector you are guessing about**, and this one was wrong twice for two unrelated reasons.

### What it found once it passed

| Endpoint | before (1 → 6 rows) | after | per row |
| --- | --- | --- | --- |
| `GET /events` **with cover images** | 7 → **22** | 6 → 6 | 3 |
| `GET /newsfeed/{post}/comments` **with replies** | 6 → **11** | 6 → 6 | 1 |
| `GET /admin/kyc-cases` | 5 → **10** | 4 → 4 | 1 |
| `GET /admin/cases/{case}/document-requests` | 5 → **10** | 5 → 5 | 1 |

**`/events` was already in the permanent gate, passing 4 → 4, for two rounds.** Its fixture
published events with no cover, and `publicMediaUrls(null)` returns without querying. The gate was
measuring the empty path and reporting the endpoint safe — the same trap as §6, on an endpoint
already believed to be covered. **A green gate is only as honest as its fixture.**

The comments thread failed the same way: a query per row that has a *parent*, and the fixture
created only top-level comments. Its fix also repairs `GET /admin/newsfeed-comments`, whose
moderation projection delegates to the same reader projection.

Three more were fixed on the same unconditional pattern — a relation read per row, which no shape
of data avoids. **These were fixed by inspection and covered by the suite, not measured at two row
counts**, which is a weaker standard than the four above and is recorded as such:

* `GET /admin/cases/{case}/eligibility-checks` — `$check->results()->get()` per row;
* `GET /me/profile/corrections` — `$request->fields()->get()` per row;
* `GET /admin/resident-corrections` — **two** per row, the fields and the resident.

The detector still flags seventeen methods. Most are now the deliberate
`$map === null ? lookup : $map[$key] ?? default` shape, which it cannot distinguish from a real
per-row lookup — the correct trade for a tool used only to choose what to measure next.

---

## 10. Round four: auditing the gates themselves

Round three showed a gate can pass while its endpoint is broken. So the remaining gates were
audited the way `/events` had to be: **does this fixture produce the data the endpoint is charged
for?**

`me/cases` is genuinely safe — its projection reads only columns already on the row, so there is no
condition to miss. The newsfeed-with-images fixture was honest too, though nothing proved it until
a reach assertion was added and mutation-tested by replacing the JPEG with a byte-signature stub
(which derives no renditions): the assertion fires.

**The registrant list was not.** Its projection still read:

```php
private function staffProjection(EventRegistration $registration, array $names = []): array
{
    $summary = $names[$residentId] ?? $this->residents->summaryFor($residentId);
```

`array $names = []` cannot distinguish *"no map was supplied"* from *"a page was supplied and this
row is not on it"* — so every registrant whose resident does not resolve cost a query, **on top of
the batch**:

| registrant list | 1 row | 6 rows |
| --- | --- | --- |
| residents resolve | 11 | 11 |
| residents do not resolve | 12 | **17** |

This is precisely the fallback §1 records as *measuring worse than the N+1 it replaced* — living in
the very endpoint §1 fixed, and surviving three rounds of measurement because every fixture created
residents that resolve. §1 diagnosed the pattern and every later fix used `?array = null`; the
original was never brought back into line.

It is not a theoretical data shape either: residents become unresolvable through an operation this
system performs deliberately — **duplicate merging**.

Fixed with the same `null`-versus-absent-key distinction, and gated by a test that deletes the
residents before measuring. Mutation-tested: restoring the `??` fallback fails the new test at
12 → 17 while **the original registrant test still passes**, which is the clearest possible
statement of what the old gate could not see.

`promote()` was fixed alongside it — it rendered a list with no map at all.

### The reach assertion is now one helper

Five gates asserted their own fixture by hand. They now share `assertFixtureProduced()`, which
carries the reasoning once: every per-row lookup here is conditional on something — a requirement
with a document, an event with a cover, a comment with a parent, an image that decoded — and when
that condition is absent the endpoint measures flat whether or not it has an N+1.

---

## Consequences

* Ten endpoints now cost a fixed number of queries at any page size, each with a gate the build
  fails on, and every gate mutation-tested by reverting its fix.
* Every gate asserts what its fixture produced, through one shared helper.
* Three batch lookups exist alongside their single-row forms. A caller rendering a list must use
  the batch; the test is what enforces it.
* `CaseRequirementService` separates the satisfaction *rule* from the *lookup*, so a list path and
  a single-row path can share one definition rather than two that drift.
* No index changed. The next person to wonder about indexes can read §3 instead of repeating the
  scan.
* Two conclusions worth more than any single fix, both from §6 and §7:
  * **a measurement that does not report what it measured is not evidence.** Two of this sweep's
    own probes were measuring empty data and reporting success;
  * **a check with no negative fixture may be inert, wrong, or both**, and the inert half hides
    the wrong half.
* And from §9: **a passing gate is not evidence its endpoint is safe.** `/events` passed for two
  rounds while costing three queries per row, because nothing asserted its fixture created the
  data the endpoint charges for. Every gate here now asserts what its fixture produced.
