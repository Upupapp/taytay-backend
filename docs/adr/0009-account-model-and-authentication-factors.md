# ADR 0009 — Accounts are separate from residents; factors differ by account type

* Status: **Accepted**
* Date: 2026-08-14
* Deciders: backend architecture (TAB 05)
* Relates to: ADR 0005/0006 (bearer tokens), ADR 0008 (schema conventions), ADR 0002
  (authorization)

## Context

TAB 05 had to answer three questions that shape everything built on top of it: what an
account *is*, how each kind of holder proves themselves, and what a successful sign-in
entitles them to.

The system serves two very different populations. LGU staff read other people's welfare
records all day from a shared office. Residents use a phone occasionally, may share it,
may have no email address, and may be in a situation — a VAWC case — where the mere fact
of holding an account is sensitive.

## Decision

### 1. An account is not a person

`accounts` holds the ability to authenticate. The resident a person *is* lives in
ResidentProfile. The link is `accounts.resident_id`: nullable, and carrying **no foreign
key** (CLAUDE.md Article 2.2).

They are not 1:1 and must not become so:

* a resident can exist with no account — walk-in intake, assisted registration by a
  barangay link, or someone who never uses a phone;
* one account may later act for several residents — a guardian, a representative, a
  parent filing for a child.

Collapsing them would be convenient today and a rewrite at the first guardian case. The
mapping is therefore a nullable identifier, not an identity.

### 2. Different factors for different populations

| | Staff | Citizen |
| --- | --- | --- |
| First factor | email + password (bcrypt) | one-time code to a registered mobile |
| Second factor | **TOTP required**, recovery codes as fallback | none |
| Token lifetime | 12 hours | 30 days |

**Staff get mandatory TOTP** because a stolen staff password reaches other people's case
files, and because ADR 0006 accepts an in-memory browser token partly on the strength of a
second factor existing.

**Citizens get no TOTP.** A code sent to their registered mobile is already a possession
factor, and requiring an authenticator app of a resident applying for burial assistance
would push them to the counter — which is the opposite of what the service is for. The
long mobile token lifetime is a deliberate trade: revocation (from another device, or by
staff) is the control, not expiry, because a citizen re-authenticating daily on a phone
stops using the service.

### 3. Authentication grants nothing

A token proves who is calling. It does not imply access to any resident or case object —
every such decision is made per object by AccessControl from the actor's server-resolved
permissions (ADR 0002). Token *abilities* (`staff`, `citizen`) are coarse client
capabilities, not authorization: they stop a citizen token driving a staff endpoint, and
that is all.

This is asserted, not assumed: a freshly authenticated staff account holds zero
permissions and sees exactly what an anonymous caller sees until a role is assigned.

### 4. Secrets are hashed, encrypted, or absent

| Secret | Storage | Why |
| --- | --- | --- |
| Password | bcrypt | low-entropy and human-chosen — deliberately slow |
| Bearer token | SHA-256 (Sanctum) | high-entropy; verified on every request |
| One-time code | SHA-256 | high-entropy, 5-minute life |
| Reset token | SHA-256 | high-entropy, 30-minute life, single use |
| Recovery code | SHA-256 | shown once, never retrievable |
| TOTP secret | **encrypted** | verification needs it back — the one recoverable secret |
| Device push token | **encrypted** | must be usable to send; a dump must not enable push spoofing |
| Device fingerprint | SHA-256 | in the clear it is a cross-account tracking key |

### 5. Failures are indistinguishable

Unknown account, wrong password, suspended, locked and expired all return the same status
and the same message. A password is checked against a dummy hash when no account matched,
so timing does not answer what the message refuses to. Requesting a code or a reset always
returns `202`.

This is not politeness. "Does this person hold an account with the LGU" is, for a VAWC
survivor, a question with physical consequences.

### 6. Lockout is timed, not permanent

Eight failures locks the account for fifteen minutes. A permanent lock would be a
denial-of-service primitive against any staff member whose email address is known.

## Consequences

* Positive: the guardian and walk-in cases are already representable.
* Positive: one authentication path per population, both ending in the same token type, so
  the four clients share one contract (ADR 0005/0006).
* Positive: no endpoint can be used to enumerate residents.
* Negative: citizens depend on SMS delivery, which is a cost and a reliability risk, and a
  resident who changes number needs a staff-assisted recovery path that does not exist yet.
* Negative: 30-day mobile tokens mean a stolen unlocked phone retains access until someone
  revokes it. Mitigated by device revocation and "revoke all sessions", both built.
* Negative: `accounts.resident_id` has no foreign key, so an orphaned reference is possible
  and must be handled by ResidentProfile's service rather than the database.

## Alternatives rejected

* **One `users` table serving both staff and residents, with a role flag.** Rejected: it
  conflates authentication with identity and makes the guardian case unrepresentable.
* **Passwords for citizens.** Rejected: another low-entropy secret to protect, reset and
  breach, for a population that already proves possession of a phone.
* **Mandatory TOTP for everyone.** Rejected as an access-to-services failure, not a
  security win.
* **Short-lived mobile tokens with refresh tokens.** Rejected for now: a refresh token is
  itself a long-lived credential, so it moves the risk rather than removing it. Revisit if
  device theft proves to be the dominant threat.
* **Keeping the config-backed role map.** Rejected: with accounts real, two sources of
  truth for authority is exactly what ADR 0008 §10 forbids.

## Sources

* NIST SP 800-63B — memorised secrets: length over composition rules, no forced rotation,
  throttling: <https://pages.nist.gov/800-63-3/sp800-63b.html>
* OWASP ASVS v4, V2 Authentication — anti-automation, lockout, credential storage:
  <https://owasp.org/www-project-application-security-verification-standard/>
* OWASP Authentication Cheat Sheet — uniform failure messages, timing:
  <https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html>
* RFC 6238 (TOTP), including §5.2 on rejecting a reused time step:
  <https://www.rfc-editor.org/rfc/rfc6238>
* Laravel Sanctum — token abilities, expiry, revocation:
  <https://laravel.com/docs/13.x/sanctum>
* RA 10173 (Data Privacy Act), RA 9262 (VAWC) — proportionality and the confidentiality
  that makes non-enumeration a safety requirement rather than a nicety.
