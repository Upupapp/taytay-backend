# Citizen API route map

Status: **generated from the router, and kept true by a test.**

Every route below is declared in `Modules\Shared\Support\CitizenSurface::citizenRouteNames()`.
`CitizenSurfaceTest` fails the build if a registered route belongs to neither that list nor the
staff one, so this map cannot silently fall behind the API. Full reasoning: ADR 0032.

---

## What "citizen route" means here

It does **not** mean "unauthenticated", and it does not mean "outside the `admin/` prefix". A path
prefix grants nothing (Article 3), and `staff/*` and `tasks/*` are staff endpoints with no prefix
at all — which is exactly why the surface is declared by hand rather than derived from a rule.

It means: **a resident may legitimately reach this, so its response is scanned.**
`CitizenLeakScanTest` calls every readable route here as a real resident, against rows deliberately
poisoned with the internal values that must not come back, and fails on any forbidden field name at
any depth.

---

## The three conventions every client follows

| Header | Direction | Meaning |
| --- | --- | --- |
| `X-Client-Channel` | request | Telemetry and presentation defaults only. It is recorded for audit and picks a default page size; it **never** grants, widens or implies permission (ADR 0002). |
| `Idempotency-Key` | request | Opt-in replay protection on a retryable write. Same key + same body replays the stored response verbatim; same key + different body is a `409` (ADR 0008 §7). |
| `X-Request-Id` | both | Correlation. Echoed inside every error payload so a citizen can quote it to a support desk. |

All three are published by `GET /api/v1/app/bootstrap`, so a client author does not have to find
this file.

### Which writes accept an idempotency key

The four where a lost response causes real harm — a duplicate that a person then has to unpick:

* `POST /api/v1/me/assistance/drafts/{draft}/submit` — two case files for one household
* `POST /api/v1/events/{event}/registration` — two seats held by one person
* `POST /api/v1/admin/cases/{case}/assessment` (staff)
* `POST /api/v1/admin/releases/{release}/confirmation` (staff) — money

Registration is additionally safe **without** a key: the service returns the place already held,
and a unique index makes a second live row impossible (ADR 0031 §3).

---

## Caching

| Response | Directive |
| --- | --- |
| Anything authenticated, and anything that declares nothing | `no-store, no-cache, private, must-revalidate` |
| `events`, `events/{event}`, `services`, `programs`, `programs/{program}` — **anonymous callers only** | `public, max-age=<PUBLIC_CACHE_SECONDS>` |

Private is the default, so forgetting to think about caching is safe. A public route is downgraded
to private the moment there is an authenticated caller, because the same URL then answers *about*
somebody (ADR 0032 §4).

---

## The map

### Platform

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/health` | anonymous |
| `GET` | `/api/v1/app/bootstrap` | anonymous |

### Account, session, device

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/auth/otp` | anonymous |
| `POST` | `/api/v1/auth/otp/verify` | anonymous |
| `POST` | `/api/v1/auth/password/forgot` | anonymous |
| `POST` | `/api/v1/auth/password/reset` | anonymous |
| `POST` | `/api/v1/auth/tokens` | anonymous |
| `POST` | `/api/v1/auth/tokens/mfa` | anonymous |
| `DELETE` | `/api/v1/auth/tokens/current` | bearer |
| `GET` | `/api/v1/me` | bearer |
| `GET` | `/api/v1/me/sessions` | bearer |
| `DELETE` | `/api/v1/me/sessions/{session}` | bearer |
| `POST` | `/api/v1/me/sessions/revoke-all` | bearer |
| `GET` | `/api/v1/me/devices` | bearer |
| `POST` | `/api/v1/me/devices` | bearer |
| `DELETE` | `/api/v1/me/devices/{device}` | bearer |
| `POST` | `/api/v1/me/mfa` | bearer |
| `POST` | `/api/v1/me/mfa/confirm` | bearer |
| `DELETE` | `/api/v1/me/mfa` | bearer |
| `POST` | `/api/v1/me/mfa/recovery-codes` | bearer |
| `POST` | `/api/v1/me/contact/verify` | bearer |
| `POST` | `/api/v1/me/contact/verify/confirm` | bearer |

### Profile and corrections

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/profile` | bearer |
| `GET` | `/api/v1/me/profile/corrections` | bearer |
| `POST` | `/api/v1/me/profile/corrections` | bearer |
| `DELETE` | `/api/v1/me/profile/corrections/{correction}` | bearer |
| `GET` | `/api/v1/me/household` | bearer |

### Verification / KYC

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/kyc` | bearer |
| `POST` | `/api/v1/me/kyc` | bearer |
| `POST` | `/api/v1/me/kyc/submit` | bearer |

### Digital ID

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/credential` | bearer |
| `POST` | `/api/v1/me/credential/qr` | bearer |
| `POST` | `/api/v1/credential-verifications` | bearer |

### Catalogue

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/services` | anonymous |
| `GET` | `/api/v1/programs` | anonymous |
| `GET` | `/api/v1/programs/{program}` | anonymous |

### Assistance

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/assistance/drafts` | bearer |
| `POST` | `/api/v1/me/assistance/drafts` | bearer |
| `PATCH` | `/api/v1/me/assistance/drafts/{draft}` | bearer |
| `DELETE` | `/api/v1/me/assistance/drafts/{draft}` | bearer |
| `POST` | `/api/v1/me/assistance/drafts/{draft}/submit` | bearer |
| `GET` | `/api/v1/me/cases` | bearer |
| `GET` | `/api/v1/me/cases/{case}` | bearer |
| `POST` | `/api/v1/me/cases/{case}/cancel` | bearer |
| `GET` | `/api/v1/me/assistance-history` | bearer |
| `GET` | `/api/v1/me/referrals` | bearer |

### Requirements and documents

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/cases/{case}/requirements` | bearer |
| `POST` | `/api/v1/me/cases/{case}/requirements/{requirement}/documents` | bearer |
| `POST` | `/api/v1/me/cases/{case}/requirements/{requirement}/documents/{version}/access` | bearer |
| `GET` | `/api/v1/documents/{handle}` | bearer |

### Newsfeed

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/newsfeed` | anonymous |
| `GET` | `/api/v1/newsfeed/{post}` | anonymous |
| `GET` | `/api/v1/newsfeed/{post}/comments` | bearer |
| `POST` | `/api/v1/newsfeed/{post}/comments` | bearer |
| `PATCH` | `/api/v1/newsfeed-comments/{comment}` | bearer |
| `DELETE` | `/api/v1/newsfeed-comments/{comment}` | bearer |
| `POST` | `/api/v1/newsfeed/{post}/reaction` | bearer |
| `DELETE` | `/api/v1/newsfeed/{post}/reaction` | bearer |
| `POST` | `/api/v1/newsfeed/{post}/share` | bearer |

### Events

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/events` | anonymous |
| `GET` | `/api/v1/events/{event}` | anonymous |
| `POST` | `/api/v1/events/{event}/registration` | bearer |
| `DELETE` | `/api/v1/events/{event}/registration` | bearer |
| `GET` | `/api/v1/me/event-registrations` | bearer |
| `GET` | `/api/v1/me/event-registrations/{registration}` | bearer |

### Notifications

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/me/notifications` | bearer |
| `POST` | `/api/v1/me/notifications/{notification}/read` | bearer |
| `POST` | `/api/v1/me/notifications/read-all` | bearer |
| `GET` | `/api/v1/me/notification-preferences` | bearer |
| `PUT` | `/api/v1/me/notification-preferences` | bearer |

---

## Not on this map

* **`/api/v1/admin/*`** — the staff surface. Authorised per endpoint by server-resolved permission;
  the prefix itself grants nothing.
* **`/api/v1/staff/*` and `/api/v1/tasks/*`** — also staff, despite the missing prefix. Declared in
  `CitizenSurface::staffRouteNamesOutsideAdminPrefix()` so the classification is complete.
* **Anything unclassified** — cannot exist. A registered route in neither list fails the build.
