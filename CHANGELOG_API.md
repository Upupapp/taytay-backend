# API changelog

Every change a client can observe, newest first.

**This file is not optional.** `docs/api/openapi.json` is committed and a test fails the build when
it is stale, so a change to a response shape produces a spec diff in the same commit — and that
diff is the prompt to add an entry here. Reasoning: [ADR 0038](docs/adr/0038-api-contract-and-versioning.md).

---

## Versioning and deprecation policy

### What is a breaking change

A change is **breaking** if a correct client, written against the previous document, could stop
working:

* removing a field, an endpoint, or an enum value;
* renaming any of them;
* narrowing a type, or making an optional request field required;
* changing a status code, or changing which `error.code` a condition produces;
* changing the *meaning* of a field while keeping its name — the worst kind, because nothing about
  the payload looks different.

A change is **additive** if it is none of those: a new optional field, a new endpoint, a new enum
value on a field the client already treats as open, a reworded `message`.

### The rules

1. **`/api/v1` is the only version.** There is no `/api/v2` and there must not be one until this
   policy has been applied to a change that genuinely needs it.
2. **Additive changes ship into v1.** A client that ignores unknown fields is unaffected, and every
   client here is first-party and can be updated.
3. **A breaking change requires `/api/v2`.** Never an in-place mutation of v1 (Article 4).
4. **`v1` does not disappear when `v2` arrives.** It is announced deprecated with a date, both
   versions run together, and v1 is removed only once every client has moved. The mobile app is on
   somebody's phone and may not be updated for months — a version switched off because the
   *server* moved on is a resident who cannot use their ID.
5. **A new enum value is additive but not free.** A client with an exhaustive `switch` will fall
   through. New values are announced here, and clients are expected to handle an unknown value
   rather than assume the list is closed.

### Deprecating something

1. Announce it here, with the date and the replacement.
2. It keeps working unchanged for the whole deprecation window.
3. Remove it only in a new version.

---

## Unreleased

### Changed — behavioural, not breaking

* **`GET /api/v1/admin/exports` now returns at most 100 exports**, most recent first. It previously
  returned every export the caller had ever requested, which grows for as long as somebody works
  here — the only unbounded list left in the API (Article 4).

  **The response shape is unchanged**: still `{ "data": { "exports": [...] } }`. Pagination would
  have been the tidier fix and would have changed the envelope, which this changelog classes as
  breaking — a disproportionate answer for a list whose rows expire in 24 hours to a week anyway.

  A client showing "your recent exports" is unaffected. One that assumed it received the complete
  history was already relying on rows that expire.

### Added

* **`is_mine` on every comment** in `GET /api/v1/newsfeed/{post}/comments`,
  `GET /api/v1/admin/newsfeed-comments`, and the comment write responses. True when the comment
  belongs to the caller.

  Use it to decide whether to offer edit and delete on a row. **Prefer it to comparing
  `author_subject_id`**, which is the field it replaces.

### Known disclosures, pending a breaking change

Recorded here so they are scheduled rather than rediscovered. Neither can be fixed in v1, because
removing a field is a breaking change under the policy above.

* **`author_subject_id` on public comment threads** hands every reader the stable account
  identifier of every author, which allows correlating one person's comments across the whole
  feed — a profile assembled from a public endpoint, on a service where people write about needing
  help (Article 5.2). `is_mine` above is the replacement and ships now; the field itself should be
  removed in the first `/api/v2`.

* **`authority.permissions` on `GET /api/v1/staff`** is 79% of a 25-row page (25,625 bytes of
  32,282), in twenty-five byte-identical copies, and is a static function of the `roles` each row
  already carries. A client can derive it. Candidate for removal in the first `/api/v2`.

### Fixed — performance, no contract change

* Three list endpoints ran one or more extra queries **per row**. All now cost a fixed number of
  queries whatever the page size, and `QueryBudgetTest` fails the build if that changes:
  `GET /api/v1/admin/events/{event}/registrations`, and `GET /api/v1/newsfeed` (twice — once for
  each post's media, once more for each post's public image URLs).

  No response changed. A feed page of twenty-five posts with pictures previously cost roughly
  seventy-five avoidable database round trips.

* **The requirements pages cost twelve queries per requirement** — the worst of the set.
  `GET /api/v1/admin/cases/{case}/requirements` measured **17 queries for one requirement and 77
  for six**; `GET /api/v1/me/cases/{case}/requirements` measured 12 and 27. Both are now flat at
  seven whatever the page holds.

  The projection resolved each requirement's document four separate times, and each resolution
  cost three queries. A case with twenty requirements cost roughly 240 round trips to render one
  page — on the staff screen used while an applicant waits at the counter, and on the phone screen
  where a resident checks whether their papers were accepted.

  No response changed: the same fields, the same values.

* **Seven more list endpoints ran a query per row.** All now cost a fixed number whatever the page
  holds. No response changed on any of them.

  Measured before and after:

  * `GET /api/v1/events` — **7 queries for one event, 22 for six**, three per event, for the cover
    image's public renditions. This is the list a resident opens to see what is happening in the
    barangay, and an event without a poster is the exception;
  * `GET /api/v1/newsfeed/{post}/comments` — one query per **reply**, 6 → 11. The same fix repairs
    `GET /api/v1/admin/newsfeed-comments`;
  * `GET /api/v1/admin/kyc-cases` — 5 → 10, a `COUNT` per case for its undecided candidates;
  * `GET /api/v1/admin/cases/{case}/document-requests` — 5 → 10, a lookup per request.

  Three more on the same pattern, since measured before and after:
  `GET /api/v1/admin/cases/{case}/eligibility-checks` (5 → 10 queries for one row versus six),
  `GET /api/v1/me/profile/corrections` (7 → 12), and `GET /api/v1/admin/resident-corrections`
  (7 → 17, **two** queries per row — the changed fields and the resident). All three are now flat.

* **`GET /api/v1/admin/events/{event}/registrations` still ran a query per row when a registrant's
  resident could not be resolved** — 12 queries for one such row and 17 for six, against 11 flat
  when every resident resolves. It paid for the batch lookup *and* then did the per-row work.

  Residents become unresolvable through duplicate merging, which this system does deliberately, so
  this degraded exactly on the lists most likely to be long. Now flat either way.

  The response is unchanged: an unresolvable registrant still renders with a null `resident_name`,
  as before.

* **`GET /api/v1/staff` described each person's authority with its own two queries** — the roles,
  then the scope — 8 queries for one staff member and 18 for six. Now two for the whole page.

  The `authority` object is unchanged: the same `roles`, `permissions` and `scope`, with the same
  values, including for a staff member who has no live role assignment.

---

## 2026-08-18 — first published contract (TAB 33)

The API had existed for thirty-two TABs and had no machine-readable specification. This is the
first, generated from the router, the PHP enums and the error-code catalogue.

**Not a change to any behaviour.** Every endpoint, status code and enum value below already worked
exactly this way; what changed is that they are now written down in a form a client can be built
against.

### Added

* `docs/api/openapi.json` — OpenAPI 3.1, generated by `php artisan lguids:openapi` and committed.
  221 paths, 53 schemas.
* `docs/api/types.ts` — TypeScript enums and the envelope types, generated from the same source.
* `GET /api/v1/admin/operations/readiness` and `/metrics` (TAB 32), `operations.view`.

### Changed

* **`civil_status` is now a documented enum.** The vocabulary
  (`single`, `married`, `widowed`, `separated`, `annulled`, `cohabiting`) was previously an inline
  validation string repeated in **three** places, and was invisible to clients — a frontend
  developer had to read backend code to learn it. **No value changed**; the same six were already
  accepted and returned.

  Found by `ApiContractTest`, which observed `single` in a real response and in no documented enum.

### Notes for client authors

* Branch on `error.code`, never on `error.message`.
* Treat enums as **open**. Handle an unknown value rather than assuming the list is closed — see
  rule 5.
* A `404` on a record you did not create is deliberate, not a bug to report: a `403` would confirm
  the identifier names something real.
* Money is **integer centavos** with an explicit `currency`. Never parse it as a decimal.
* `X-Client-Channel` is telemetry. Sending `admin-console` grants nothing.
