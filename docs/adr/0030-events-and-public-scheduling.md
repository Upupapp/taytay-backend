# ADR 0030 — Events and public scheduling

* **Status:** accepted
* **Date:** 2026-08-16
* **Built in:** TAB 25
* **Supersedes:** nothing
* **Related:** ADR 0028 (newsfeed publishing), ADR 0022 §1 (the location refusal), ADR 0016 §6
  (permission from the target state), ADR 0025 (notification transport)

---

## Context

The LGU runs events: feeding programmes, medical missions, senior-citizen assemblies, relief
distributions, barangay assemblies. Residents need to know when and where, and — for the ones with
a capacity — whether there is still room.

This is the second module whose records are meant to be read by people who are not their subject,
and it inherits the inverted risk ADR 0028 described: the danger is not disclosure, it is
**publishing something wrong, or announcing something that then does not happen**.

It adds a risk the newsfeed does not have. A post is a statement. **An event is an operational
commitment** — a venue, a time, a contact, and a set of people who will physically travel to a
covered court on a Tuesday morning because this system told them to.

---

## 1. Events is its own module, not part of Content

Both publish to the public, and the temptation to fold events into `Content` as "a post with a
date" is real. We did not.

A newsfeed post's hardest problem is scheduling its own publication. An event's hardest problems
are **capacity, registration windows and attendance** — real concurrent state with races
(TAB 26 builds them). Putting a capacity counter beside a table whose semantics are "text the
office wrote" means one module holding two unrelated kinds of correctness, and the one with a race
condition always loses to the one without.

They also fail differently. A wrong post is corrected by editing it. A wrong event has already sent
people somewhere.

`Events` depends on nothing but `Shared` and `AccessControl`, and publishes no cross-module
contracts yet. Nothing depends on `Events`.

---

## 2. Availability is DERIVED. There is no column.

**This is the central decision of the TAB, and it is an absence.**

The master command asks for registration availability to be *"derived from configured times and
capacity rather than stored as a contradictory copy"*. We took that literally: there is no
`registration_availability` column, no `is_registration_open` boolean, and no job that maintains
one. `EventService::availabilityFor()` computes the answer from the configured window, the event
status, the start time and a live count, on every single read.

The reason a stored one is wrong is not hypothetical. A column saying `open` at 17:00 for a
registration that closed at 16:59 needs *something* to notice and rewrite it — a scheduled job, a
model event, a queue worker. That something will one day not run: the worker is restarted, the
queue backs up, the schedule is misconfigured on a new host. And the failure is silent and
one-directional in the worst way — the stale value says **open** to a resident who then registers
for something that closed, and the office finds out at the venue.

Derived, the answer is always current, a missed job is impossible, and there is no second source to
disagree with the first. `EventTest::availability_closes_when_the_clock_passes_the_window` moves
only the clock — no job, no write — and watches the answer change three times.

`there_is_no_stored_availability_column` is an **enforced absence**: it fails the build if anyone
adds one under any of three plausible names, and the failure message says why.

### The five states are distinct on purpose

`not-required`, `not-open`, `open`, `closed`, `full`.

* `not-required` is not `open`. A walk-in event told a resident "registration closed" would turn
  them away from something they could just attend.
* `full` is not `closed`. TAB 26 needs to tell *"there is no room"* from *"the window has passed"*,
  because a waitlist accepts in the first case and not the second. Collapsing them would make a
  waitlist unimplementable without reintroducing the distinction somewhere worse.

The human wording travels with the state (`RegistrationAvailability::message()`), so every client
says the same thing and none can invent a friendlier phrasing for a closed window.

---

## 3. The public query is the boundary

Identical to ADR 0028 §2, for identical reasons. Every citizen-facing read runs through
`EventService::publicQuery()`, which narrows on status **at the query**. A `where slug = ?` against
that cannot return a draft, and **no status check follows it**.

The alternative — load, then `if ($event->isDraft()) abort(404)` — holds only while everybody
remembers the `if`. This holds because there is nothing to remember.

Both handles are covered. An event is reachable by slug as well as UUID, and a slug is *guessable*
in a way a UUID is not: `feeding-programme` is exactly what somebody would type. The test asserts
`404` for both.

### Cancelled and completed events stay public

Only `draft` and `archived` are invisible.

**A cancelled event that was published stays on the public list, with its reason showing.**
Somebody arranged their day around it. Removing it silently means they travel to a covered court to
find nobody there, and the system that told them to go has erased the evidence that it did.

A completed one stays too, because *"was there a medical mission last August?"* is a question
residents and auditors both ask.

`archived` is the state for taking something off the list. It is deliberately a separate,
deliberate act.

---

## 4. Publishing and cancelling are one permission; drafting is another

`event.manage` authors and edits. `event.publish` publishes **and cancels**.

Cancelling sits with publishing rather than with editing because it is the same kind of act in
reverse: both change what residents believe is happening on a given morning. An event called off by
mistake sends people home from a court they travelled to; a draft written badly costs nobody a
trip.

The permission is resolved **from the target state** (`EventStatus::requiredPermission()`), the
same shape as ADR 0016 §6 and ADR 0028. A new state added to the enum without a permission mapping
falls to the `default` — `event.manage` — which is the narrower of the two only for the states that
do not reach the public, so a careless addition fails closed for anything else.

`lgu_staff` holds `event.manage` and not `event.publish`. `lgu_admin` holds both.

### A cancelled event is not un-cancelled

`Cancelled → Archived` only. People were told it was off; telling them it is back on is a **new
announcement**, not a status change. A resurrected event would silently reappear on the calendar of
everybody who had already crossed it out, with no notification that it had.

### A cancellation must say why

Enforced in the service, and the reason is **shown to the public**. "Cancelled" alone tells a
resident nothing about whether to wait for a new date.

---

## 5. There is no citizen write path

The acceptance criterion is *"a resident cannot create or edit events"*. It is not held by a
permission check that a future endpoint might omit — **`EventService` has no method a citizen could
call**, and `Routes/api_v1.php` mounts no citizen write route.

The staff routes sit behind `auth:sanctum` and each authorizes explicitly. The test walks all seven
as a resident and asserts `403` on every one, then re-reads the row to confirm nothing landed.

### The reader routes are outside the auth group, and that is affirmative

Article 3.5 requires an unauthenticated route to be a recorded choice, so this is the record.

An event is a **public invitation**. A poster with a QR code is read by somebody with no account,
and requiring one to find out when the feeding programme starts would be a barrier invented by the
software rather than by the office.

This differs from the newsfeed, where anonymous access is off by default (gap G-36). A newsfeed
carries barangay-targeted advisories, and showing somebody a relief schedule for a barangay they do
not live in produces a queue at a hall they are not on the list for. **An event has no audience
targeting at all** — it is one municipality-wide list — so the same risk does not arise. If
per-barangay events are ever wanted, the audience model and the anonymous-access question come back
together, in a new ADR.

---

## 6. Times are UTC; the timezone is a stored fact

Article 4 requires UTC storage. An event also has a **local scheduling context**: "9am at the
covered court" means 9am in Manila. The Philippines has no daylight saving, which makes this cheap
to get right and easy to forget — so `timezone` is a column (default `Asia/Manila`) rather than an
assumption, and it travels in the projection.

### Registration must close before the event starts

Not a technicality. A window that stays open into the event lets somebody register while it is
already running, and then arrive to find the room counted without them.

### An event must end after it starts

The acceptance criterion, and it is checked in the **service against merged values**, not in the
form request against the body. Validating only what was sent is how a two-field rule gets bypassed
by sending one field — `a_partial_update_cannot_invert_the_schedule` is that case.

---

## 7. `map_url` is a URL, never coordinates

An optional link somebody typed, pointing at a public place.

A `latitude`/`longitude` pair would be the beginning of the location model TAB 17 refused
(ADR 0022 §1), arriving through a door marked "convenience". Venues are public buildings whose
addresses are already public; the refusal there was about **tracking people**, and a coordinate
column on a table the same office writes is one join away from being useful for that.

---

## 8. Capacity: null is not zero

`null` means uncapped. `0` means an event nobody may attend, and somebody will eventually mean it —
a placeholder while the venue is confirmed, or a cancelled-but-not-yet-cancelled state. Collapsing
them would silently turn "we have not decided" into "unlimited", which is the direction that fills
a room.

---

## 9. Duplication produces a draft with no schedule

The office runs the same feeding programme every month. Retyping a venue, a contact and a set of
instructions each time is how one of them ends up wrong.

The copy is **always a draft**, its `published_at`, `cancelled_at` and reason are cleared, and it
gets a **freshly minted slug** — two events cannot share the link printed on a poster. Dates are
carried over as values but the draft status means nothing is public until somebody looks at them,
which is the point: duplicating is precisely what somebody does when the dates are the thing
changing.

---

## 10. A cover image needs alt text

Same rule as ADR 0028 §7. An event poster a blind resident cannot read is an event they were not
invited to.

The events rule is slightly stricter than the newsfeed's: there is no `is_decorative` escape,
because an event's single cover image is never decorative — it is the poster.

---

## 11. What this TAB does not build

* **Registrations, waitlists and attendance** — TAB 26. `availabilityFor()` already takes the live
  count as a parameter so that TAB can pass a real number without this module learning how
  registrations work, and `registration-summary` returns `registered_count: 0` as a **present
  placeholder** rather than an absent field, so a console built against this shape does not change
  when the count becomes real (the discipline of ADR 0023 §3).
* **Event reminders.** Notifying registrants the day before is a `Notification` concern and needs
  registrations to exist first.
* **Per-barangay event audiences** — see §5.
* **A calendar/ICS feed.** Useful, not asked for, and it is a projection of data that now exists.

---

## Consequences

* Availability can never be stale, and there is no maintenance job to forget. The cost is that
  every read computes it — a handful of comparisons, and the capacity check needs a count that
  TAB 26 will supply.
* An office that wants to un-cancel an event must create a new one. That is intended, and it will
  occasionally be inconvenient.
* Anonymous reading is on for events and off for the newsfeed. That asymmetry is deliberate and
  documented here so the next person does not "fix" it in either direction.
