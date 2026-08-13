# ADR 0003 — Envelope-based API responses, path versioning, clamped pagination

* Status: **Accepted**
* Date: 2026-08-13
* Deciders: backend architecture (TAB 01)

## Context

Four independently released clients consume this API, one of them a mobile app whose
installed builds cannot be updated on demand. Response and error handling must therefore
be *predictable and additive*: a client written today must keep working when the server
adds fields, and must be able to branch on failures without parsing prose.

## Decision

1. **Envelope, not bare payloads.** Success is `{ "data": …, "meta": { … } }`; failure is
   `{ "error": { "code", "message", "details", "request_id" } }`.
2. **No `success: true` flag.** The HTTP status code is the success signal; a second,
   redundant signal eventually disagrees with the first.
3. **Stable `SCREAMING_SNAKE_CASE` error codes.** Clients branch on `code`. `message` is
   operator-facing prose and may change freely; it must never carry SQL, stack traces,
   class names, file paths or personal data.
4. **Path versioning (`/api/v1`).** Visible in logs, trivially routable, and unambiguous
   for an old mobile build pinned to v1.
5. **`request_id` on every response** (`X-Request-Id` header, echoed in error bodies) so a
   citizen can quote an id to a support desk and staff can find the exact request.
6. **Every collection is paginated**, `per_page` default 25, max 100, with out-of-range
   values **clamped rather than rejected**.
7. **`404` over `403` when existence is itself sensitive.**

## Rationale

* An envelope leaves room for `meta` to grow (pagination, request id, later: deprecation
  notices) without ever changing the shape of `data` — additive evolution for clients
  that cannot be patched.
* Clamping `per_page` prevents `?per_page=1000000` from becoming a denial-of-service
  vector while still returning useful data, which is friendlier to a mobile client on a
  poor connection than a 422.
* Returning `404` where a `403` would confirm that a record exists prevents enumeration
  of residents — a real privacy leak under RA 10173, not a theoretical one.

## Consequences

* Positive: one response builder (`Modules\Shared\Http\ApiResponse`) and one exception
  renderer produce every payload, so error shape cannot drift per module.
* Positive: clients can implement one generic parser and one generic error handler.
* Negative: responses are marginally more verbose than bare JSON.
* Negative: deviating from RFC 9457 means we do not get its off-the-shelf tooling.

## Alternatives rejected

* **RFC 9457 `application/problem+json`.** A reasonable standard, and its `type` URI
  discipline is genuinely good. Rejected because it covers errors only — we would still
  need to invent a success/pagination convention — and because `application/problem+json`
  handling is awkward in the HTTP clients used by the Flutter and browser clients here.
  Our error object is deliberately shaped as a near-subset (`code` ≈ `type`,
  `message` ≈ `title`, `details` ≈ extensions), so migrating later is mechanical.
* **Bare payloads with headers for pagination (GitHub `Link` style).** Rejected: header
  parsing is the first thing a hand-rolled mobile client gets wrong, and it leaves no
  place for `request_id`.
* **Header/media-type versioning.** Rejected: invisible in logs and easy to lose through
  proxies, for no gain at this scale.

## Sources

* RFC 9457, *Problem Details for HTTP APIs* — <https://www.rfc-editor.org/rfc/rfc9457>
* OWASP API Security Top 10 (2023), API4:2023 Unrestricted Resource Consumption
  (pagination limits) — <https://owasp.org/API-Security/editions/2023/en/0x11-t10/>
* Laravel error handling & responses — <https://laravel.com/docs/13.x/errors>
