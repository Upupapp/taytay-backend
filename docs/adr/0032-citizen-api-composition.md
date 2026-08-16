# ADR 0032 — Citizen API composition

* **Status:** accepted
* **Date:** 2026-08-16
* **Built in:** TAB 27
* **Related:** ADR 0002 (actor context, channel is telemetry), ADR 0003 (envelope), ADR 0005
  (cross-origin auth), ADR 0028 §1 (two projections), ADR 0008 §7 (idempotency)

---

## Context

The master command for this TAB opens with an instruction that is really a warning:

> Do **NOT** duplicate business rules in a separate Citizen service layer.

That is the whole risk. "Compose the citizen API" reads like an invitation to build a
`Citizen/` module with its own services, and the result would be two implementations of every
rule — drifting quietly, with the citizen one always slightly behind.

**So this TAB adds no business logic and no domain module.** Every citizen endpoint the master
command lists already exists, built in the module that owns the fact behind it, across TABs 05–26.
What was missing was not code. It was three things:

1. a way to know **what the citizen surface even is**, and to be told when it changes;
2. an enforced guarantee that **nothing internal reaches it**;
3. the platform concerns a client needs that no domain module owns — **bootstrap and caching**.

---

## 1. The citizen surface is declared, and every route is classified

`Modules\Shared\Support\CitizenSurface` holds two lists: the route names a resident may reach, and
the staff route names that do not sit behind the `admin/` prefix. `CitizenSurfaceTest` fails the
build for any registered route in neither.

### Why a declared list rather than a rule

The obvious rule is "everything not under `admin/` is citizen-facing". **That rule is already wrong
in this codebase**: `staff/*` and `tasks/*` are staff endpoints with no prefix, and a prefix test
would classify all fourteen of them as citizen-facing. A rule that is wrong on day one is a rule
that will be wrong silently on day four hundred.

More importantly, a list is the *mechanism*, not the documentation. A new endpoint cannot join the
API without somebody stating which audience it serves — and stating "citizen" enrols it in the leak
scan automatically. There is no third option and no way to abstain.

This is the same shape as `ResidentMergeCoverageTest` (ADR 0019 §4): the rule is stated where
forgetting it is loud.

---

## 2. Internal fields are absent by construction, and the detector is tested first

`CitizenLeakScanTest` calls every readable citizen endpoint with a real resident behind a real
token and scans the entire response tree — at any depth, inside paginated `data` arrays — for a
list of field names that must never reach a resident.

Three things make it worth trusting:

**The fixture is the test.** A scan against clean data proves nothing; every projection looks safe
when there is nothing to leak. So the resident's own rows are deliberately poisoned first — a staff
note on their event registration, a moderation reason and moderating officer on their comment, an
assigned caseworker on their welfare case — and *then* read back. The test asserts the poison
landed, because an `UPDATE` matching no rows returns zero and throws nothing, and a fixture that
silently wrote to an empty table would leave the whole scan green for exactly the reason it exists
to rule out.

**The scanner is tested positively and negatively** before it is trusted: a planted leak it must
find, a leak nested inside a list it must find, and a clean payload it must pass. A detector that
cannot detect is worse than none, because it is believed.

**The scan proves its own coverage.** `the_scan_actually_covers_the_declared_citizen_reads` fails if
a declared citizen `GET` route is never called. Without it, an endpoint could be declared, never
exercised, and the scan would be green about it forever — the failure this project has already hit
twice.

That test caught a real bug in itself on first run: the URL-matching regex used `preg_quote` before
substituting `{param}` placeholders, which left a stray backslash and matched nothing. It reported
full coverage while calling eight endpoints not at all.

### The one exemption, and why it is not a hole

The scan flagged `GET /api/v1/me` returning `permissions` and `roles`. That flag was worth thinking
about rather than suppressing.

**It is correct, and the list entry was too broad.** Article 3.4 forbids the server *trusting* an
authority list that arrives from a client; that is a different thing from telling a client what it
holds. The admin console cannot render a menu without knowing its own authority, and a resident's
own list tells them nothing they did not already know by being themselves.

So the exemption is keyed to **that URL and those two fields**, stated with its reason in
`CitizenSurface::fieldExemptions()`. There is no global off-switch, because one would defeat the
list. What the entry still catches — and what it is for — is an authority list turning up inside a
*record*: a case, a comment, a registration. There it would be a disclosure about the office rather
than a statement about the caller.

---

## 3. Web and mobile see the same business outcome

Asserted across the **whole readable surface**, not on one chosen endpoint — because the endpoint
that drifts will be the one nobody chose. Each URL is called twice with different
`X-Client-Channel` values and the `data` payloads must be equal.

`meta` is deliberately not compared: the channel legitimately picks a default page size (ADR 0002),
which is presentation. The business outcome may not differ, and now cannot without failing a test.

**One endpoint is exempt: `app/bootstrap`**, whose entire job is to describe the client. It echoes
the channel back so a client can see how its header was parsed. Exempted explicitly rather than
made channel-blind.

---

## 4. Cache directives: private by default, public by opt-in, downgraded when signed in

`Shared\Http\Middleware\ApplyCacheDirectives`, prepended so it wraps everything and sets a directive
on whatever comes back — including an authentication failure, which is also a response that must
not sit in a shared cache.

**The default is `no-store`, and the direction is the decision.** A response holding one resident's
welfare case, sitting in a shared cache and served to the next caller who asks for the same URL, is
the disclosure this whole system exists to prevent — and it needs no authorization bug to happen. A
CDN, a corporate proxy or a browser's back button is enough.

So a route that forgets to declare itself is private. The failure mode of forgetting is "we cached
less than we could have", which costs a little bandwidth, rather than "we served somebody else's
file", which cannot be undone.

`no-store`, not `no-cache`. The two are routinely confused and only one means what is needed:
`no-cache` permits storage and requires revalidation, so the file is still written to a proxy's
disk.

**Public is opt-in per route (`->defaults('cache', 'public')`) and downgraded the moment there is an
authenticated caller.** The events list is genuinely public — until a signed-in resident asks, at
which point the response is *about* somebody. Four routes are marked: events list and detail,
service catalogue, programme catalogue and detail. The last three narrow on the caller's
permissions, so only the anonymous published-only projection is ever cacheable.

**No `ETag`.** Laravel will hash a body for one, and an `ETag` on a private response is a small
fingerprint of that response sitting in a proxy's memory. `max-age` is the part that saves a phone
on a poor connection real time; a hash saves the last few bytes and is not worth the surface.

---

## 5. App bootstrap: `GET /api/v1/app/bootstrap`

**Unauthenticated, and it has to be.** An app that cannot start cannot sign in to be told that it
should update — a minimum-version gate behind authentication opens only for the clients that did
not need it.

So it holds nothing worth protecting, and a test asserts as much against a list of secret-shaped
words. `config/client.php` says the same thing at the other end.

It carries:

* **the minimum supported version, per channel.** The *server* decides whether a build is too old,
  because a client that decides for itself is exactly the client that will not — a build with a
  broken update check cannot fix its own update check. Empty means no minimum, so a missing
  configuration never becomes an accidental hard block.
* **server time and timezone.** No critical operation depends on the client clock and none reads a
  client-supplied time; this is published so a phone with a wrong clock can *notice*, rather than
  telling somebody an event starting in an hour started yesterday.
* **feature flags**, each read indirectly from the config the owning module already reads. One
  source per flag, so this endpoint cannot claim a feature is on while the module refuses it. They
  are rendering hints and never authorization: a client that ignored every one would gain nothing.
* **header conventions**, so a client author does not have to find the markdown.
* **a support contact**, because a citizen holding a request id and no way to quote it has been
  given a correlation id and no correlation.

---

## 6. Netlify origins and cross-origin posture

`config/cors.php` was already correct — a deny-by-default allow-list from
`CORS_ALLOWED_ORIGINS`, credentials off, no origin patterns. TAB 27 makes those invariants
**enforced** rather than merely current:

* a wildcard origin is refused while credentials are enabled — the classic CORS mistake, always one
  config line away, and it lets any page on the internet make authenticated requests on a
  signed-in resident's behalf;
* an origin *pattern* with credentials enabled is refused — a wildcard wearing a hat;
* **`*.netlify.app` is refused in any pattern.** Anybody can create a site on that domain, so
  allowing the suffix allows every Netlify user. Deploy previews point at staging and must never be
  able to speak to production (master command, and ADR 0005 §3).

Nothing about the deployment topology changed. What changed is that weakening it now fails a test.

---

## 7. Retryable writes

Already conventional (ADR 0008 §7) and already implemented on the four writes where a lost response
causes real harm: assistance submission, case assessment, assistance release, and event
registration. The header is now published by `app/bootstrap` so a client does not have to be told
out of band.

The audit for this TAB found no citizen write that needs it and lacks it. Draft create/update are
naturally idempotent (`openOrResume`), reactions upsert, and the remaining writes are either
already keyed or have no duplicate-creation failure mode.

---

## 8. What this TAB deliberately did not build

* **A `Citizen` module.** See the context: it would be a second implementation of every rule.
* **Sanctum cookie/session mode for the citizen web portal.** ADR 0005 chose first-party bearer
  tokens precisely to avoid widening cookie scope, enabling credentialed CORS and adding a CSRF
  surface. The master command mentions stateful cookies as an option; Article 8.7 says change the
  approach, not the control. Recorded as **G-43** so the choice is visible rather than assumed.
* **OpenAPI 3.1 generation.** Named in the stack baseline and not yet produced; the contract matrix
  is the current specification and it is machine-checked against the router in both directions
  (**G-44**).

---

## Consequences

* Adding an endpoint now costs one line in `CitizenSurface`. That is the intended friction: it is
  the moment somebody decides who the endpoint is for.
* The leak scan runs the whole readable citizen surface on every test run, which costs a few
  seconds and buys the acceptance criterion.
* Marking a new route public requires a deliberate `->defaults('cache', 'public')`. Forgetting is
  safe.
