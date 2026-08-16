# ADR 0029 — Newsfeed engagement and moderation

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 24
* Extends: ADR 0028 (newsfeed publishing), ADR 0022 (safeguarding, for the enforced-absence pattern)

---

## Context

Three acceptance criteria: a citizen can modify only their own engagement except authorized
moderators; hidden and deleted moderation state is respected across all citizen feeds; no external
share-recipient data is stored.

And one instruction the master command gives twice: **this is not a social network.** No follow
graph, no profiles, no mentions, no AI moderation.

---

## 1. Every citizen write is bound to the token and to a live post

There is no field anywhere in this contract that names an author, a reactor or a sharer. That is
how the first criterion is held — not by a check, but by there being nothing to tamper with.

The post is loaded through `NewsfeedService::publicQuery()`, so engagement is only possible on
something already public. A draft that could accumulate reactions would appear the moment it went
live with counts nobody could account for.

**One reaction per person per post**, upserted on a unique key. Changing a reaction updates the
row; removing it deletes it. There is no reaction history, because *"who disliked the mayor's post
in March"* is not a record this office needs to be able to produce.

**The edit window is bounded at 15 minutes.** An unbounded edit lets somebody write something
agreeable, collect replies, and rewrite it into something else — leaving a thread of people
apparently agreeing with a statement nobody saw. Fifteen minutes covers a typo and not that.

**`is_official` is set by the server** from the author's permission, never accepted from the
request. A resident able to post a comment marked official could impersonate the municipality
directly under its own announcement — a more effective lie than most, because of where it appears.

---

## 2. Moderation state is a state, not a missing row

`visible`, `hidden`, `deleted`, `review-needed`.

**`deleted` does not delete.** A comment removed for abuse must stay readable by a moderator:
*"what did it say, who wrote it, who removed it and why"* is the question asked when the author
complains, and a hard delete makes every answer "we do not know".

The citizen thread narrows on `visible` **at the query**, which is what makes the second criterion
hold across every citizen surface — including the comment count that travels with a post. A count
that included hidden comments would be a moderation log by arithmetic: subtract what you can see
from what you are told and you know how much was removed.

**A moderation decision must say why.** A comment that disappears with no recorded reason is
indistinguishable from censorship to its author, and from a mistake to the colleague who finds it
later.

**A hidden comment is not editable back into visibility.** Correcting the wording of something a
moderator removed would let an author launder a decision they disagree with.

**Readers receive no moderation fields at all** — not the state, not the reason, not the moderator.
A reader only ever gets visible comments, so a state field would be a constant, and a reason field
would publish a moderator's note about somebody to everybody.

---

## 3. A share is a counter

**Decision: `newsfeed_shares` holds a post, an optional account and a timestamp. Nothing else, and
`NoShareRecipientDataTest` fails the build if that changes.**

The master command forbids tracking external destinations or personal contacts. Like the location
rule in ADR 0022 §1, that is easy to refuse as a feature and easy to acquire as a column —
*"which platform do people share to?"* is a reasonable product question, and answering it turns a
municipal welfare system into a record of who talks to whom.

The test uses an **allow-list** rather than only a deny-list, so a destination column with an
innocent name still fails. `subject_id` is nullable because an anonymous reader may share a public
post: the row says an advisory travelled, not who carried it.

---

## 4. Moderating and replying are one permission, and not implied by publishing

`newsfeed.moderate` does both. Replying officially and removing a comment are the two ways the
office speaks in a thread, and somebody trusted to do one is trusted to do the other.

It is **not** implied by `newsfeed.publish`. Writing an announcement and judging what residents may
say underneath it are different responsibilities — the same reasoning that separates authoring from
publishing in ADR 0028 §4.

**One level of reply, and no deeper.** A thread that nests arbitrarily is a thread somebody has to
moderate arbitrarily deep, and on a municipal announcement the useful shape is a comment and the
office's answer to it.

**Closing comments does not close reactions.** Turning off a conversation the office cannot
moderate is not the same as refusing acknowledgement.

---

## 5. Abuse control is a rate limit, and deliberately nothing more

The master command asks for rate limiting and explicitly says not to build AI moderation now. So:

* **20 writes a minute, keyed by account.** Generous for a person, useless for a script.
* **Keyed by account, not by IP.** A household behind one connection is several legitimate
  residents, and a barangay hall's public wifi is dozens — an IP limit would silence a whole
  neighbourhood because one person was enthusiastic.
* **Reading a thread is not throttled** beyond the global API limit. A reader refreshing a feed is
  ordinary use.

`review-needed` exists as a state nothing currently sets. It is the hook a future moderation
provider writes into, so adding one is a listener rather than a migration and a state-machine
change (gap **G-37**).

---

## Consequences

* An engagement row can only ever belong to whoever created it.
* A removed comment is recoverable, explicable and invisible — all three at once.
* This system cannot answer "who did this resident share the advisory with", and cannot be made to
  without deleting a test that says why not.
* There is no follow graph, no profile and no mention. The LGU publishes; residents respond to the
  LGU. Nothing models residents' relationships to each other, because the moment it does, the LGU
  is operating a social network.
