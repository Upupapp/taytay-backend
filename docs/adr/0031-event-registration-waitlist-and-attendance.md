# ADR 0031 — Event registration, waitlist and attendance

* **Status:** accepted
* **Date:** 2026-08-16
* **Built in:** TAB 26
* **Related:** ADR 0030 (events), ADR 0025 (notification is transport), ADR 0026 §3 (exports),
  ADR 0019 §4 (merge coverage), ADR 0008 §7 (idempotency)

---

## Context

TAB 25 built events and a **derived** answer to "may somebody register right now". It deliberately
left `registered_count` as a present placeholder at zero, because the count needs registrations to
exist.

This TAB makes it real, and the whole of it turns on one sentence from the master command:
*"concurrent registrations cannot exceed capacity **according to committed backend state**"*. Every
other requirement — waitlist, retry safety, attendance — is either a consequence of that or sits
beside it.

---

## 1. There is no seat counter, and that is the design

The obvious implementation is a `registered_count` column on `events`, incremented on registration
and decremented on cancellation. It is fast, it is simple, and it is the bug.

A counter and the rows it counts are **two sources of one fact**. They drift: a failed insert that
already incremented, a cancellation path that forgot to decrement, a restore that decremented
twice, a merge that moved a row without touching either. And when they disagree, **the counter
wins** — because the counter is what the capacity check reads. The office oversells a covered court
by four seats and there is nothing in the log, because nothing failed.

So seats are counted from committed rows, inside the same lock that decides the outcome. That is
what "according to committed backend state" asks for, read literally.

`EventRegistrationTest::there_is_no_registered_count_column_to_drift` is an **enforced absence**
under three plausible names, and its failure message says why.

The cost is a `COUNT(*)` per registration, on an indexed `(event_id, status, id)`. If an event ever
has enough registrants for that to matter, the fix is a materialised count **derived and rebuilt**,
not a counter mutated in two places.

---

## 2. Every seat decision happens behind a row lock on the event

`SELECT ... FOR UPDATE` on the event row, taken by `EventRegistrationService::lock()` and entered
by `register()`, `restore()`, `cancel()` and `promoteFromWaitlist()`.

It serialises registration **for one event and nothing else**: two people registering for different
events never wait on each other, and two people registering for the same one are decided one after
the other against rows that are actually committed.

### Why not an advisory lock, or Redis

Both work. Neither is portable to the SQLite the tests run on, and **a concurrency control that is
only ever exercised in production is one nobody has seen fail.** Article 1 requires portable
migrations for the same reason, and this is the same argument one layer up.

### An honest note about what the test suite proves

The suite is single-process and SQLite compiles `lockForUpdate()` to an empty string, so
`capacity_is_never_exceeded_however_many_people_try` proves the **arithmetic**, not the race.

What holds the race is the lock, and what would break it is somebody deleting that line while every
test stayed green — the failure appearing only under production load. So
`every_seat_decision_is_taken_behind_a_row_lock` asserts the mechanism directly: the lock exists,
and each of the four methods that decides something about a seat takes it. That is a weaker test
than a genuinely concurrent one, and it is stated as such here and in the test. It is also the
difference between a guarantee that is written down and one that is merely believed.

**Verifying this under real concurrency against PostgreSQL is gap G-40.**

---

## 3. Retry safety has two layers, and only one of them is optional

The criterion is *"retry does not duplicate registration"*. It holds for a client that sends an
`Idempotency-Key` and for one that does not:

1. **`Shared\Application\IdempotencyService`** replays the stored response verbatim, 201 and all,
   for a client that opts in.
2. **Inside the lock**, an existing live registration is *returned* rather than duplicated — 200
   instead of 201, so a client can tell whether its retry was the attempt that landed.
3. **`uniq_event_registrations_active`** makes a second live row impossible at the database.

The third is the one that survives a code path nobody thought of, and it is tested directly by
writing a duplicate straight to the table and expecting the constraint to refuse it.

### The `active_key` trick

A nullable column holding the resident id while the registration is live and `NULL` once it is
cancelled, unique with `event_id`.

Postgres and SQLite both treat NULLs as **distinct** in a unique index, so this gives "at most one
live registration per resident per event" *and* unlimited cancelled history — portably, with no
partial index and no `DB::statement`. The same trick TAB 14 used for open enrolments, for the same
reason: the obvious constraint `(event_id, resident_id)` would forbid somebody changing their mind
twice.

### Registration is keyed on the RESIDENT, not the account

A household may share one phone, and one account may act for several residents. Keying on the
account would let a mother and daughter registering from the same handset collapse into one seat,
or let one person hold two by signing in twice.

An account with **no linked resident cannot register at all**. A place is held by a person and
checked against a list at a door; a registration with no resident behind it is a name nobody can
verify.

---

## 4. A citizen has no unscoped read

*"A citizen cannot access another resident's registration by changing the ID"* is held by there
being **no citizen endpoint that takes a registration id and looks it up unscoped.**
`registrationsForResident()` narrows to the resident resolved from the token, and every citizen
read is a `where` on top of it.

So somebody else's registration is **absent**, and the answer is a `404` produced by there being no
row — not a `403` produced by a check that could be omitted from the next endpoint. `403` would
also confirm that the id names a real registration, which is most of what an enumeration attempt
wants (OWASP API1).

Withdrawal takes **no id at all**: it resolves the caller's own registration for the event in the
path. There is nothing to tamper with.

### Two projections

The staff projection carries the registrant's **name** and the **staff note**; the citizen one
carries neither. A separate method, not the staff one with fields removed — so a column added to
the office's view does not silently arrive in the citizen's.

The staff note matters most here. *"Turned up drunk last time"* is operationally useful and written
in the office's voice about a person; it is not something they read about themselves in an app.

---

## 5. Waitlist promotion is deterministic and idempotent

**Order is `id` ascending.** Not a stored `waitlist_position`, which needs maintenance and drifts
from the order people actually joined the first time somebody in the middle cancels. `id` is
monotonic, free, and incapable of disagreeing with itself.

**Each promotion is a conditional `UPDATE ... WHERE status = 'waitlisted'`** inside the event lock.
Running promotion twice promotes nobody twice; two callers racing produce one update and one
no-op. Being told twice that you got a seat is a smaller harm than two people promoted into one,
but it is still a message that cannot be unsent — and the conditional update is what makes the
duplicate impossible rather than unlikely.

Promotion runs after **every cancellation**, and after **an event update**, because raising the
capacity is the other way room appears and the one that is easy to forget: an office that moves to
a bigger hall and adds thirty seats would otherwise leave thirty people waiting for a seat that
already exists.

Nobody is promoted into a cancelled, completed or archived event. Telling somebody they got a place
at something that is not happening is worse than telling them nothing.

### The notification is announced, never delivered from here

`Events\Contracts\EventRegistrationPromoted` is dispatched and
`Notification\Application\NotifyRegistrantOnWaitlistPromotion` listens — the same inversion as
`CaseStatusChanged` (ADR 0025 §1). Events decides a seat opened and knows nothing about whether
anybody is told; a push provider outage cannot roll back a promotion.

The event carries no name, no address and no contact detail — an event payload travels into every
queue record and failed-job row a listener touches (Article 8.4). The event *title* is there
because it is printed on a poster. Being on the list for it is not public, which is why the
resident is a UUID.

### A no-show does not free a seat

Deliberate. The event is already running; promoting somebody who is at home is a message telling
them to travel to something that started an hour ago. The door admits walk-ins by its own
judgement, which is not a registration this system needs to invent.

---

## 6. Attendance

`not-checked-in` / `attended` / `no-show`, and **the default is `not-checked-in`, not `no-show`.**
Before the door opens, and for anybody it never got to, "we have not marked this person" is the
truth. Defaulting to `no-show` would record every registrant at an event nobody bothered to check
in as having failed to attend — and a no-show record quietly shapes who gets a seat next time.

`event.mark-attendance` is its own permission, held by `lgu_staff` as well as `lgu_admin`. The
person at the door is often a volunteer or a front-line clerk, and requiring `event.publish` to
check somebody in would mean a publishing credential gets shared at a covered court — which is how
one gets shared for good.

**A waitlisted person cannot be marked present.** It reads as rigidity and it is not: recording
attendance for somebody who never held a seat puts the attendance list above capacity, and every
later count — how many came, how much food was needed — silently disagrees with how many were let
in. If the door admits somebody from the queue, promote them; that is one call and it leaves a
record of the seat opening.

**A marked registration can no longer be cancelled.** Somebody came. Erasing the record of it would
leave an attendance list that does not match the room.

The history the master command asks for lives in the **append-only audit trail**, not a second
table. Both the previous and the new value are recorded, because the question afterwards is *"who
changed this from attended to no-show, and when"* — and a trail holding only the new value cannot
answer it.

---

## 7. The registrant export reuses the export lifecycle, with its own permission

`ReportCatalog::EventRegistrants`, so it inherits everything ADR 0026 §3 already decided: queued and
never inline, the request recorded before the file exists, the permission context snapshotted,
re-authorization at download, and — because it is **person-level** — 24-hour retention and its own
audit action. A second export lifecycle would be a second place where retention, re-authorization
and audit could drift.

It is person-level because an event is public but **being on the list for a supplementary feeding
programme is a fact about a household's circumstances**.

It costs `event.export-registrants`, **not** `report.export.person-level`. Both are person-level and
both get the same handling; the permission is the authority to take *this particular copy* out of
the system, and a door list and a payout manifest are two different authorities held by two
different offices. Folding them together would mean whoever could print one could print the other.

**Minimal fields, and the omissions are the design:** a reference, a name, a status, whether they
turned up. No address, no contact number, no barangay, no household, no vulnerability factor, and
no staff note. An export with no `event_id` returns nothing rather than every registrant of every
event the LGU has ever run.

Granted to `lgu_admin` only. Front-line staff mark attendance through the API, which records who
marked what — a better outcome than a spreadsheet, and the reason a printed list exists at all is
the covered court where the network does not reach.

---

## 8. A merge keeps the earlier place

`event_registrations.resident_id` makes Events a merge consumer, and
`ResidentMergeCoverageTest` fails the build without a mechanism (ADR 0019 §4). Events **listens**
for `ResidentMerged`, because it depends on ResidentProfile and the reverse call would close a
cycle.

The hard part is not the update, it is the **collision**. Both records registered for the same
event is precisely what a duplicate resident looks like — the same person signing up twice under
two files — and a blind repoint breaches the live-registration index and takes the whole merge
down.

So collisions are resolved deliberately: **the earlier registration survives.** Not the survivor
record's — the *earlier* one. A queue position a person earned belongs to the person, not to
whichever of their two files the office happened to keep, and demoting somebody to the back of a
waitlist because an administrator merged their record is a real harm from an invisible cause.

The losing row is **cancelled with a reason**, not deleted, so somebody looking later can see that
two places existed and why one stopped.

---

## 9. Cancellation policy

A registrant may withdraw **until the event starts**, and not after. Withdrawing afterwards would
turn a no-show into "never registered" — erasing exactly the record the office needs in order to
size the next one, at the request of the person it reflects on.

A second withdrawal is **not an error**: at the service level it is idempotent, and at the endpoint
the caller simply holds no place (`404`). Answering a double-tap with a conflict teaches people to
ignore errors.

Staff cancellation **must state a reason**, and the registrant sees it. A place taken away with no
recorded reason is indistinguishable from a mistake to the colleague who finds it and from
arbitrariness to the person it happened to.

**Restore does not displace whoever took the seat.** If the event filled while the registration was
cancelled, restoring goes to the waitlist. The person who registered in the meantime did nothing
wrong.

---

## 10. What this TAB does not build

* **Reminders** — the day-before message, and the "this was cancelled" message to everybody
  registered. Now buildable, since there is finally a recipient list (gap G-39).
* **Assisted registration at a counter.** Staff cannot currently register somebody on their behalf,
  and an assisted registration is a real LGU workflow (gap G-41).
* **QR check-in.** Attendance is marked by reference; scanning a credential at the door is
  `Verification`'s territory and needs that module.
* **Per-event eligibility rules** ("seniors only", "one per household"). The master command says
  *"according to event rules"* and there are no event rules yet beyond capacity and the window
  (gap G-42).

---

## Consequences

* Capacity is correct by construction, at the cost of a counted query per registration and a
  serialisation point per event. Both are the right trade at municipal scale.
* The concurrency guarantee is asserted structurally rather than exercised. G-40 records the
  verification that has not been done.
* Staff cannot register somebody at a counter, which will be noticed (G-41).
