# ADR 0035 — API security, rate limiting and abuse protection

* **Status:** accepted
* **Date:** 2026-08-17
* **Built in:** TAB 30
* **Frame:** OWASP API Security Top 10
* **Related:** ADR 0002 (channel is telemetry), ADR 0012 (scope), ADR 0016 §6 (permission from the
  target state), ADR 0023 §3 (separation of duties), ADR 0034 §7 (the auditee is not the auditor)

---

## Context

This is a review TAB, not a feature TAB. Twenty-nine TABs built authorization, scoping, validation
and audit; the question here is whether they hold up when somebody attacks them, and what is
missing that nobody has needed yet.

The survey found the controls broadly in place and **three real gaps**, all of the same shape:
a control applied where somebody thought about it and absent everywhere else, with no way to tell
the difference from outside.

---

## 1. What the survey found

| Control | State before this TAB |
| --- | --- |
| Object-level authorization | **in place** — every citizen read scoped at the query; staff reads scoped by barangay |
| Function-level authorization | **in place** — `authorize($actor, Permission::X)` on every privileged endpoint |
| Property allow-lists / mass assignment | **in place** — `$request->validate()` returns only validated keys; corrections refuse unknown fields *explicitly* |
| No secrets in errors | **in place** — one renderer, ADR 0003 |
| CORS exact allow-list | **in place** — deny by default, enforced since TAB 27 |
| Body and file size limits | **in place** — per classification since TAB 28 |
| Token revocation | **in place** — `me/sessions`, `auth/tokens/current` |
| **Rate limits** | **partial** — four limiters in three files; KYC submission, search and exports had **none** |
| **Security headers** | **almost absent** — two endpoints of 261 set them by hand |
| **SSRF** | **absent by luck** — nothing fetches, but nothing said so |

Each gap is now closed, and each is closed in the way that survives the next endpoint rather than
by adding a line to the ones that exist today.

---

## 2. Rate limits: one table, keyed by account

Before this, limiters were defined next to whichever module needed one first. That is why nobody
reviewing *"are we rate limited?"* could answer it without reading every provider — and why the
three surfaces the master command names most explicitly had no limit at all.

`config/security.php` holds every number; `Modules\Shared\Http\RateLimits` registers every limiter.

**Keyed by account where there is one.** A household behind a single connection is several
legitimate residents and a barangay hall's wifi is dozens; keying by IP alone throttles a queue of
people at a counter as though they were one abuser. IP is used only where there is no account
yet — and there it is **paired with a hash of the identifier being tried**, so neither dimension
alone lets an attacker through: rotating addresses still hits the per-identifier limit, hammering
one account from one address hits both.

The identifier is hashed because a limiter key ends up in a cache store and in whatever an operator
dumps while debugging, and the plaintext is an email or mobile number belonging to somebody who has
not even signed in (Article 5.5).

Two limits deserve their reasoning stated:

* **KYC submission** is low because each one puts a case in front of a **human reviewer**. An
  unthrottled endpoint there is a denial-of-service attack on the office's attention, not on the
  server.
* **Exports are limited per hour, not per minute** — the tightest authenticated limit in the
  system. An export is a copy of the database leaving this application's control (ADR 0026 §3); ten
  an hour is generous for somebody doing their job and useless to somebody exfiltrating a caseload.

`ApiSecurityTest::every_declared_rate_limiter_is_registered` closes a quiet failure mode: a
`throttle:` middleware naming an unregistered limiter does not fail at boot. Laravel treats the
name as a "N per minute" string and throws only at request time — so an unregistered limiter is an
endpoint that breaks in production and passes every test that never calls it.

---

## 3. Security headers, globally

Two endpoints set `nosniff` by hand — the document download and the export download, because those
are the two that return bytes. Everything else had none.

`SecurityHeaders` is **prepended**, so it wraps every other middleware and reaches every response
including an authentication failure and a rate-limit rejection — the responses most likely to be
missed, because the middleware that would have set them usually sits *inside* the auth stack.

This is a JSON API, which makes the policy unusually strict: `default-src 'none'` refuses every
fetch directive at once rather than tuning an allow-list, because a browser should never render,
frame, script or subresource anything here. `frame-ancestors 'none'` duplicates `X-Frame-Options`
because the two are read by different browsers.

`Referrer-Policy: no-referrer` is not boilerplate: a referrer from this API would carry the request
path — which contains resident and case identifiers — to whatever a browser navigated to next.

`Permissions-Policy: geolocation=()` is ADR 0022 §1's refusal restated to the browser.

### HSTS is off, and that is the safe direction

It is the one security header that is hard to take back: a browser that has seen it refuses plain
HTTP for the whole `max-age`, so a premature one on a host whose certificate is not yet right locks
people out of the API with **no server-side fix** — and `includeSubDomains` from a staging box on a
shared parent domain poisons production with it.

Guarded twice: the request must actually be secure, and `HSTS_ENABLED` must be on. The master
command says "HSTS only after domain readiness", and that is a deployment decision.

---

## 4. Negative authorization tests: attempt the attack

`ApiSecurityTest` tests by **attempting the attack**, not by asserting a check exists. A check that
exists and is wrong passes the second kind of test and fails the first.

### API1 — object-level

A resident creates records; a second resident tries every identifier. **`404`, not `403`** —
answering `403` confirms the id names a real record, which is most of what an enumeration attempt
wants.

A list and a detail fail differently, and the test says so: a scoped list correctly answers `200`
with nothing in it, because there is no "somebody else's list" to refuse. Only a detail lookup can
be substituted. The list check searches the whole body for the owner's identifiers rather than
asserting an empty `data` array — envelopes differ across these endpoints, and a shape-specific
assertion passes on an endpoint whose rows it never looked at.

**Cross-barangay** is tested separately, and the fixture matters: scope comes from the role
*assignment's* `scope_type`, not from the role (ADR 0012), so a fixture that merely assigned
`lgu_staff` would get municipality-wide reach and prove nothing. A genuinely barangay-scoped clerk
holds `resident.view` — the function-level check passes — and is refused anyway.

### API5 — function-level

Front-line staff attempt six endpoints reserved above them; each must answer `403`.

Separation of duties is tested as an attack rather than as a config assertion, because the way it
fails in practice is somebody quietly adding one permission to a role. The transition uses a
**legal** target state on purpose: an illegal one is refused by the state machine with `409` before
authorization is consulted, so a test using one would pass whether or not the split existed.

### API6 — mass assignment

Two shapes, and they are protected differently:

* **an unknown *resident* field** (`verification_tier`, `philsys_number`) is **refused explicitly**
  with `422`. That is stronger than dropping it: a contract that silently ignored
  `verification_tier` would answer `201` and teach a client that self-promotion to a verified
  identity had worked;
* **a field of the *request itself*** (`status`, `reviewed_by`) is silently absent, because
  `$request->validate()` returns only validated keys — an unlisted field does not exist as far as
  the service is concerned. It is not ignored downstream; it never arrives.

Note that every model is `$guarded = ['id']`, which is permissive. The real control is the
validation allow-list at the controller, and these tests are what make that a tested property
rather than a convention (**G-49**).

---

## 5. SSRF: there is no server-side fetch

The master command asks for strict URL validation before any server-side fetch and for no generic
fetch endpoint. This system satisfies both the easy way — **there is no server-side fetch at all**,
so there is no URL to validate.

That is worth enforcing rather than observing, because the feature that introduces one always
sounds reasonable: *"import a resident photo from a URL"*, *"check whether this provider's website
is up"*, *"preview the link in this event's `map_url`"*. Each is one HTTP client call, and each
turns a backend sitting on a private network with a database, a cache and an object store into a
proxy that will fetch `http://169.254.169.254/` on request.

`map_url` is the live temptation and is named in the test: events **store** a link to a public map
and never fetch it. The difference between those is the whole of API7.

FCM is exempt — an outbound call to a **fixed, configured** endpoint is a different thing from a
caller-supplied one.

The signature list is deliberately narrow. `fopen(` was one on the first run and produced a false
positive on `php://temp`, which `BuildReportExport` uses to assemble a CSV in memory. A signature
that flags local stream work gets silenced by whoever hits it — probably by allow-listing the file,
which would then permit a real fetch from it too. Remote wrappers are matched as literals instead.

---

## 6. Enumeration

The login endpoint answers identically for a known account with a wrong password and an account
that does not exist — same status, same error code. A difference turns login into a directory of
who is registered with this LGU, and **for a welfare system, being registered is itself a fact
about somebody**.

---

## 7. CSRF and App Check

**CSRF does not apply.** Authentication is first-party bearer tokens (ADR 0005), CORS is a
deny-by-default allow-list, and `supports_credentials` is off — so there is no ambient credential a
cross-site request could ride. If the citizen web portal ever adopts Sanctum's cookie mode, CSRF
protection arrives with it and needs a new ADR (**G-43**).

**Firebase App Check is not adopted.** The master command permits it as defence in depth and is
explicit that it never substitutes for authenticated actor and object authorization. It is recorded
as **G-50** rather than half-built: an attestation check that fails open is decoration, and one
that fails closed needs a staged rollout plan the LGU has not made.

---

## Consequences

* Adding an endpoint that needs a limit now means adding one line to `config/security.php` and one
  to `RateLimits` — and forgetting is visible, because the limiter list is one file.
* Every response is slightly larger. That is the cost of headers on 261 endpoints instead of two.
* A future integration that genuinely needs an outbound call must add itself to
  `OUTBOUND_ALLOWED` with an ADR. That friction is the point.
