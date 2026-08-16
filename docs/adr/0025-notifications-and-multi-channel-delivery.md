# ADR 0025 — Notifications and multi-channel delivery

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 20
* Extends: ADR 0004 (topology), ADR 0016 (case engine), ADR 0024 (tasks)

---

## Context

Three acceptance criteria: notifications are not sent before required transactions commit; a
device token cannot be attached to another user by spoofing an id; provider outages do not block
core API transactions.

And one constraint that predates the TAB and outranks it: **Article 8.3–8.4.** Firebase is
transport, not authority. Laravel decides that a notification is warranted, who may receive it and
what it may say; FCM carries bytes. No PII, case narrative, document identifier or welfare detail
may reach a third-party push channel.

---

## 1. Notification owns *how*, never *why*

Welfare decides that a case was approved. This module decides how to tell somebody. Nothing here
imports a case, and the listener registration lives in `NotificationServiceProvider` — removing one
line turns notifications off entirely and changes nothing else.

That split is what makes the third criterion structural: a provider outage cannot reach welfare
work, because welfare work does not call a provider. It dispatches an event and returns.

---

## 2. The rendered text and the push payload are different things

**Decision: `notifications` holds rendered title and body because it is read back over an
authenticated API. A push payload holds a type and two opaque identifiers.**

The same sentence is correct in both places and dangerous in one:

> Your AICS assistance of ₱5,000 is ready for release at the barangay hall.

In the app, behind authentication, that is exactly right. On a lock screen it is visible to anyone
holding the phone; on a shared handset it is visible to the household; in transit it is visible to
the provider and lives in their logs.

So `OutboundNotification` carries both shapes and `routingPayload()` is the only thing a push
adapter may use. `FcmChannel` has no line that reads `$message->body`, and the separation lives in
a contract rather than in each adapter so that reaching for the wrong field is a visible choice in
a reviewable file.

It is sent as a **data-only** FCM message for the same reason: a `notification` message requires a
title and body that the provider itself renders, which is precisely the content that may not leave
this system.

**`notification_dispatches` has no payload column**, deliberately. A stored push body would be a
second copy of exactly what this design keeps out of the push channel.

---

## 3. Channels are interfaces, and an absent provider is `skipped`

Four adapters: `database` (always configured), `push` (FCM), `email` and `sms` (null until an
environment configures them).

**`skipped` is not `failed` and neither is `sent`.** A dashboard showing "delivered" for a channel
that does not exist is worse than one showing nothing — it tells an operator the family was told.
"We have no SMS provider configured" is a deployment fact; "the SMS provider rejected this" is an
incident; and they need different responses.

**A channel never throws for a provider-side failure.** A notification is a side effect of
something that already happened — the assistance was approved whether or not the text lands — and
an exception escaping a channel would let a provider outage roll back committed welfare work.

The FCM **transport** is not wired: credentials are environment configuration this repository must
not assume (gap **G-33**). What is fixed is everything around it — the payload shape, the bounded
retry, the dead-token handling and the `skipped` behaviour that keeps the third criterion true
even when the provider does not exist yet.

---

## 4. Preferences are opt-out, and mandatory notices ignore them

A preference row exists only when something has been **switched off**. An absent row means "on",
which is the right default for a service that has to be able to tell people things — and it means a
notification type added next year reaches people rather than silently reaching nobody because no
row exists for it.

**Mandatory notices ignore preferences entirely.** A scheduled release date and a security alert
are things the office must be able to send; letting them be switched off would mean somebody
misses a payout because of a toggle they set months earlier.

**The in-app record can never be switched off.** `database` is not an allowed channel for a
preference. Opting out of email means "stop emailing me", not "stop keeping a record of what you
told me" — and a person who opted out of everything and then asks why they were never informed
deserves a list to be shown.

The mandatory rule is stated in the preferences payload so a client can explain the switch it is
not offering. An absent toggle with no explanation reads as a bug.

---

## 5. A device token belongs to whoever presented the bearer token

**Decision: `DeviceTokenService::register()` takes an `ActorContext` and no account identifier.**

The acceptance criterion is held by there being nothing to spoof. A registration endpoint accepting
`{account_id, token}` would let anybody redirect anybody's push notifications to their own phone —
a disclosure channel with no trail at the receiving end, because the victim simply stops receiving
things.

Two further rules, both from how tokens actually behave:

* **A token is unique across the table, not per account.** FCM reissues a registration token to
  whichever app instance holds it, so the same string arriving for a second account means the
  device changed hands. The old registration is deactivated rather than both being kept.
* **A refreshed token replaces the previous one from the same install.** Without that, every
  launch after a provider-side rotation leaves another dead registration behind, and a year later
  one phone has fifty.

**The raw token never appears in a response.** It is a credential for reaching somebody's phone,
and a response carrying it puts it into every proxy log on the way home.

**Dead tokens are deactivated, not deleted**, with the provider's reason — "unregistered on 12
August because the app was uninstalled" explains why somebody stopped receiving notices, which is
otherwise an unanswerable support question.

---

## 6. Not every transition is announced

`CaseStatusChanged::isWorthTellingTheApplicant()` lists six statuses. A case moving between
`assessment` and `endorsed` is the office moving paper between desks; telling somebody each time
teaches them the notifications are noise, and then the one that matters arrives among fourteen
that did not.

The rule lives on the event rather than in the listener so "which movements a family hears about"
is answerable by reading one method.

**The event carries the projected citizen sentence, never the internal reason.** The case timeline
already draws that line (ADR 0016 §5); passing `reason` here would let a listener put a
caseworker's justification into an email, and the wording that survives an appeal is not the
wording written for a colleague.

**Every account acting for the resident is notified**, because a daughter applying for her mother
is a real and common arrangement (ADR 0013 §5) — the person who filed is the person watching for
the answer.

---

## Consequences

* A push provider learns a notification exists and nothing about what it says.
* Turning notifications off is one line; turning a channel on is configuration.
* A rolled-back transition leaves no message, because the dispatch is bound to the commit.
* An in-app record survives every preference and every provider outage, so "were they told?" is
  always answerable.
* The FCM transport is a gap (**G-33**), and the acceptance criteria hold without it.
