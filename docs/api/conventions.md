# API Conventions — `/api/v1`

Normative for every endpoint in this backend. Implemented once in `Modules\Shared\Http`;
endpoints must use those helpers rather than hand-rolling payloads.

---

## 1. Transport and versioning

* REST/JSON over HTTPS. `Content-Type: application/json`.
* All routes are under `/api/v1`. The version is in the path, never in a header.
* Breaking changes ship as `/api/v2`. A breaking change is: removing/renaming a field,
  narrowing a type, changing an error `code`, or tightening validation. Adding an
  optional field or a new error `code` for a new condition is **not** breaking.
* Clients must ignore unknown fields.
* Requests are answered as JSON regardless of the `Accept` header
  (`ForceJsonResponse` middleware) — an LGU mobile client must never receive an HTML
  error page.

## 2. Request context headers

| Header | Required | Meaning |
| --- | --- | --- |
| `Authorization: Bearer <token>` | for protected routes | Server-side authentication. |
| `X-Client-Channel` | recommended | One of `citizen-web`, `citizen-mobile`, `admin-console`, `verifier-device`. **Telemetry and presentation defaults only — never authority.** Unknown/absent ⇒ `unknown`. |
| `X-Request-Id` | optional | Client-supplied correlation id (max 128 chars, `A-Za-z0-9._:-`). Generated server-side when absent or malformed. Always echoed back in `X-Request-Id`. |

Anything else a client sends about *itself* — role, permissions, `is_admin`, tenant, office
— is ignored. See constitution Article 3.

## 3. Success envelope

Single resource:

```json
{
  "data": { "id": "9b1f…", "code": "BRGY_CLEARANCE", "name": "Barangay Clearance" },
  "meta": { "request_id": "01JB…" }
}
```

Collection (always paginated):

```json
{
  "data": [ { "…": "…" } ],
  "meta": {
    "request_id": "01JB…",
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 138,
      "total_pages": 6,
      "has_more": true
    }
  }
}
```

* `data` is the payload and nothing else — no status flags, no `success: true`. The HTTP
  status code carries success/failure.
* `meta` is additive; clients must tolerate new keys.
* `201 Created` returns the created resource in `data` plus a `Location` header.
* `204 No Content` has no body.

## 4. Error envelope

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": { "email": ["The email field is required."] },
    "request_id": "01JB…"
  }
}
```

* `code` — stable, machine-readable, `SCREAMING_SNAKE_CASE`. Clients branch on this, not
  on `message`.
* `message` — short, safe, operator-facing. Never contains SQL, stack traces, file paths,
  class names or personal data.
* `details` — optional, structured. For validation it is `field → [messages]`.
* `request_id` — always present, matches the `X-Request-Id` response header.

### Canonical error codes

| HTTP | `code` | When |
| --- | --- | --- |
| 400 | `BAD_REQUEST` | Malformed request the schema can't express. |
| 401 | `UNAUTHENTICATED` | Missing/invalid/expired credentials. |
| 403 | `FORBIDDEN` | Authenticated but not permitted. Default for every denied authorization decision. |
| 404 | `NOT_FOUND` | Resource does not exist **or** the actor may not know it exists. |
| 405 | `METHOD_NOT_ALLOWED` | Wrong verb. |
| 409 | `CONFLICT` | State conflict (duplicate, concurrent update). |
| 409 | `INVALID_STATE_TRANSITION` | Lifecycle transition not permitted from the current state. |
| 422 | `VALIDATION_FAILED` | Input failed validation. |
| 429 | `RATE_LIMITED` | Throttled. Includes `Retry-After`. |
| 500 | `SERVER_ERROR` | Unhandled fault. Body is generic; detail goes to logs only. |
| 503 | `SERVICE_UNAVAILABLE` | Dependency down / maintenance. |

**Existence is a privilege.** When revealing that a record exists would itself leak
personal data, return `404 NOT_FOUND` rather than `403 FORBIDDEN`.

## 5. Pagination

* Page-based: `?page=1&per_page=25`.
* `per_page` default **25**, maximum **100**. Out-of-range values are clamped, not
  rejected — an over-large page size must never become a denial-of-service vector.
* `page` is 1-based; a page beyond the end returns `200` with an empty `data` array.
* Unbounded collection responses are forbidden.
* Sorting: `?sort=field` / `?sort=-field` (leading `-` = descending), restricted to an
  endpoint-declared allow-list. Filters are explicit named query parameters — never a
  pass-through to the query builder.

## 6. Types

* Timestamps: ISO-8601 UTC with `Z` (`2026-08-13T04:15:00Z`). Never local time.
* Dates without time: `YYYY-MM-DD`.
* Identifiers exposed to clients: UUID strings. Auto-increment primary keys are internal
  and must never appear in a payload.
* Enumerations: lowercase `snake_case` strings, never integers.
* Money: integer minor units (centavos) plus an ISO-4217 `currency`.
* Booleans are real booleans; nullable fields are `null`, not `""` or `0`.
* Field names are `snake_case`.

## 7. Idempotency and safety

* `GET`/`HEAD` never mutate.
* State-changing endpoints that a mobile client may retry accept an
  `Idempotency-Key` header; replaying a key returns the original result.
* Writes are transactional — no partial domain state.

## 8. Cross-origin access

Browser clients only. `config/cors.php` replaces Laravel's default wildcard with an
allow-list driven by `CORS_ALLOWED_ORIGINS`, and **defaults to denying every cross-origin
request**. Set it per environment to the citizen portal and admin console origins.

`supports_credentials` is off — authentication is token-based, so no cookie ever crosses
an origin. `X-Request-Id` is exposed so a browser client can show a citizen the id to
quote to a support desk.

The Flutter app and verifier devices are not browsers; CORS does not apply to them.

## 9. Health

`GET /api/v1/health` — unauthenticated liveness probe. Returns service name, status and
API version only. It must never expose versions of dependencies, environment names,
configuration or credentials.
