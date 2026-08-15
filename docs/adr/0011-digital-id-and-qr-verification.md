# ADR 0011 — Digital ID: feature-flagged, and the QR is a handle, not a record

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 06)
* Relates to: ADR 0010 (verification tiers), ADR 0004 (topology), ADR 0002 (authorization)

## Context

An LGU digital ID is attractive and is where these projects usually leak. The failure is
predictable: the QR encodes the holder's details so that a verifier can read them offline,
and from then on every screenshot, every photo taken over a shoulder and every printout
left on a counter is a copy of somebody's identity record.

A QR code should be assumed public the moment it is displayed.

## Decision

### 1. Off by default

The whole surface is behind `credential.digital_id.enabled`, which ships **false**. A
digital ID is optional to the service: every resident must be able to receive assistance
without one, and an LGU should be able to run the platform for a year before deciding
whether to issue cards at all.

Disabled routes return **404, not 403** — a feature that is not live should look absent
rather than forbidden, since "forbidden" tells a caller it exists and is worth pursuing.

Registering the routes either way keeps the route list and the contract matrix honest
about what exists; the flag decides whether they answer.

### 2. Only for fully verified residents

Issuance requires `verification_tier = verified` (ADR 0010 §4). A credential asserts
identity to third parties, so the LGU must have established it. Partial verification
remains enough to *receive assistance* — help must not be conditional on paperwork a
person cannot produce.

Issuance is idempotent: a resident holding a live credential gets it back rather than a
second one, so a retried request cannot leave two valid cards in circulation.

### 3. The QR payload is five fields

```
{ v: version, s: serial, e: expiry, n: nonce, k: key-id } . HMAC-SHA256
```

**Absent by design:** name, birth date, address, barangay, sectors, income, household,
photo, case history, account id, and any identifier that could be used to look those up.

The payload carries a *handle*; the server answers questions about it. This is the whole
decision — a payload that describes the holder is a portable copy of their record, and
copies cannot be revoked.

**Replay resistance has two independent layers**, because either alone is weak:

* a **90-second expiry**, so a photographed code dies on its own; and
* a **single-use nonce** enforced by a unique index, so it cannot be used twice even
  inside its window. The index is the enforcement rather than a prior `SELECT`: two
  simultaneous scans of the same code must not both succeed, and only the database can
  arbitrate that race.

Validity is decided **at scan time against current state**, never from the payload — a
credential revoked one minute after minting fails the next scan.

Signing is HMAC-SHA256 with a server-held key. Symmetric is correct here because only this
backend both issues and verifies. Keys are held per-id so one can be rotated or retired
without invalidating credentials sealed by the others; the credential row records which id
signed it and **never the key material**, which lives in the environment.

### 4. The verification response is deliberately thin

`outcome`, `valid`, `serial`, `expires_at`, and — only when valid — `holder_name` as given
name plus family name.

That is enough for a person at a counter to know the card is good and that the face in
front of them matches a name. A kiosk operator is not an LGU case worker, and the response
is too thin to be worth attacking for.

Verification is authenticated so scans are attributable, but requires **no permission**: a
verifier device is not staff.

Every scan is recorded, including failures. A forged or replayed scan is exactly the event
an investigation later asks about.

## Consequences

* Positive: a captured QR is worthless — expired, spent, and uninformative even before
  that.
* Positive: revocation is immediate and total, because validity is never carried in the
  artifact.
* Positive: the LGU can ship the platform without deciding on digital ID, and can review
  the schema and contracts before anyone depends on them.
* Negative: **verification requires connectivity.** A barangay hall with no signal cannot
  verify a card. This is the real cost of not putting data in the payload, and it is
  accepted: offline verification means a signed, self-describing payload, which is the
  design this ADR rejects. If offline becomes a hard requirement it needs asymmetric keys,
  a published verification key, a much shorter validity window, and its own ADR — and it
  should still carry a handle plus a status, not a profile.
* Negative: a 90-second window is tight for a slow queue; the holder re-mints, which is
  cheap. Widening it trades directly against the value of a stolen screenshot.
* Negative: symmetric signing means the verification endpoint must be this backend. That
  is true today by construction.

## Alternatives rejected

* **Encode name, photo and validity in the QR for offline verification.** Rejected: it
  makes every screenshot a copy of an identity record, and copies cannot be revoked.
* **A long-lived QR printed on a physical card.** Rejected for the same reason, worse — a
  printed payload cannot even expire.
* **No nonce, expiry only.** Rejected: 90 seconds is plenty of time to photograph and
  reuse a code at the next counter.
* **Asymmetric signing now.** Not wrong, but unnecessary while only this backend verifies,
  and it invites offline verification by the back door. Revisit with §3's caveats.
* **Storing minted payloads.** Rejected: a payload in a table can be stolen from it, and
  its expiry becomes whatever the reader decides.

## Sources

* Laravel encryption, hashing and `hash_equals` for constant-time comparison:
  <https://laravel.com/docs/13.x/encryption>
* OWASP — replay protection and nonce handling:
  <https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html>
* NIST SP 800-63B §5.1 — verifier requirements and the limits of possession proofs:
  <https://pages.nist.gov/800-63-3/sp800-63b.html>
* RFC 2104 (HMAC): <https://www.rfc-editor.org/rfc/rfc2104>
* RA 10173 (Data Privacy Act) — data minimisation and proportionality, which is the basis
  for §3's exclusion list.
