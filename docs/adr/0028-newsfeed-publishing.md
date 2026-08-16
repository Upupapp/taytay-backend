# ADR 0028 — Newsfeed publishing

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 23
* Extends: ADR 0016 (lifecycle shape), ADR 0020 (files)

---

## Context

The newsfeed is **the only table in this system whose contents are meant to be read by people who
are not their subject.** Everything else holds records *about* somebody; a post holds an
announcement *for* everybody.

That inverts the usual risk. The danger is not disclosure of the row — publication is the point —
it is publishing **before it was meant to go out**, or **to an audience it was not meant for**.

Three acceptance criteria: a resident cannot create, edit or publish; a scheduled post publishes at
most once; draft and scheduled content cannot leak via a guessed ID.

---

## 1. Two projections, two queries — never one of each

The staff projection carries the status, the author, the schedule and the audience. The public one
carries what an announcement *is*.

They are **separate methods reading separate queries**. The alternative — one projection with
fields removed for citizens — is the arrangement that leaks the first time somebody adds a field
and forgets the deny-list. The public projection fails closed: a new column is absent until
somebody deliberately puts it there. Same rule as ADR 0016 §5.

`published_at` is what a reader means by "when was this posted", and it is not `created_at`, which
is when somebody started drafting.

---

## 2. The public query is the security boundary

Every citizen-facing read goes through `publicQuery()`, which narrows on three conditions **at the
query**:

1. status is `published`;
2. `publish_at` has arrived;
3. the audience matches the reader.

That is what makes criterion 3 survive: a `where uuid = ?` against a query that already excludes
drafts returns nothing for a draft — and there is no status check after it, because there is
nothing left to check. The alternative, a lookup followed by `if ($post->isDraft()) abort(404)`,
holds only while everybody remembers the `if`.

**Both status and schedule, always together.** A post whose status says published but whose
`publish_at` has not arrived is still embargoed in every way that matters to a reader. Treating
status alone as the gate is how an announcement goes out early — and that is tested against a
hand-forced row, because that is the shape a partial migration leaves behind.

---

## 3. A scheduled post publishes at most once

**Decision: a conditional UPDATE, not a lock and not a check-then-write.**

`UPDATE ... WHERE status = 'scheduled' AND publish_at <= now` is atomic on every engine this runs
on. Two workers racing on the same row produce one update and one no-op, because the second one's
`WHERE` no longer matches. **There is no window between reading and writing** for a second worker
to fit into — which is the window a `SELECT` followed by a `save()` always has.

It is also idempotent under replay: five sweeps in a minute publish each post once.

`published_at` is stamped in the same statement, so a post can never be published with no record of
when — the state a separate follow-up write leaves behind when it fails.

**A sweep, not a per-post delayed job.** A delayed job lost in a queue restart is an announcement
that never goes out, with nothing to notice it. A missed sweep is harmless: the next one catches
up.

**Pulling a scheduled post back to draft clears `publish_at`**, or the sweep would silently
republish it.

---

## 4. Authoring and publishing are different permissions

`newsfeed.manage` drafts, edits and archives. `newsfeed.publish` puts something on the municipal
feed, schedules it, or pins it.

An announcement that has been seen cannot be unseen, so an office may want the second held by
fewer people — the same shape as endorse/approve on a case (ADR 0016 §6). Front-line staff hold
the first; `lgu_admin` holds both.

The permission is resolved from the **target state**, as in the case engine, so the state machine
and the authorization table stay in one place.

**A published post can still be corrected.** A wrong date on a relief schedule must be fixable
without pulling the announcement down and confusing everybody who already read it. The edit is
audited and `published_at` does not move.

**An archived post is never resurrected.** Republishing would put an old post back at the top of
the feed with its original date, which reads as the office announcing something old as if it were
new. A new post is a new post.

---

## 5. Audience targeting, and why it is not a secret

`municipality` reaches everybody; `barangay` reaches one.

A targeted post is **not confidential** — anybody could be shown it by a friend. The reason to
target is operational: sending a barangay-specific relief notice to the whole municipality produces
a queue of people at a distribution they are not on the list for, which is a real harm to real
families.

An anonymous or unlinked reader therefore sees municipality-wide posts only. There is no way to ask
an anonymous caller for a barangay that is not also a way to enumerate barangays.

---

## 6. Anonymous access is off by default

The master command permits anonymous reading "only if Taytay explicitly marks Newsfeed public", so
`newsfeed.public_access` defaults to **false**.

Defaulting the other way would have been the easy reading of "it is public information" — and it
would have published a barangay's relief schedule to the open internet before anybody at the MSWDO
was asked whether that was intended (gap **G-36**).

The reader routes sit **outside** the auth middleware group and refuse anonymous callers in the
controller. That is deliberate: putting them behind `auth:sanctum` would make enabling public
access a *routing* change, and routing changes are the ones nobody reviews as a policy decision.

---

## 7. Alt text is required

Not nullable, and not optional in the request. A published municipal announcement that a blind
resident cannot read is a service the LGU is not providing to somebody entitled to it — and an
optional field is an omitted field.

A genuinely decorative image is handled by an explicit `is_decorative` flag rather than an empty
string, so **"nobody wrote one" and "there is nothing to write" stay distinguishable**. The
projection always emits `alt_text`, so a client never has to decide what to do with a missing one.

---

## Consequences

* Unpublished content is unreachable through the reader API by construction, not by filtering.
* The scheduler is safe to run as often as anybody likes, and safe to miss.
* Enabling anonymous access is a config decision with a name, not a side effect of a route move.
* Comments are a column (`comments_enabled`) and nothing reads it yet — TAB 24 owns that.
