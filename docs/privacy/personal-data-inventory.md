# Technical personal-data inventory

Status: **a technical artifact prepared to support a Privacy Impact Assessment.**
It is **not** a PIA, not a legal assessment, and nothing in it is approved.

Prepared under TAB 29 for review by the Data Protection Officer of the Municipality of Taytay,
Rizal, and the LGU's legal counsel, against Republic Act No. 10173 (Data Privacy Act of 2012) and
current National Privacy Commission issuances.

Decisions and reasoning: [ADR 0034](../adr/0034-audit-privacy-and-data-governance.md).

---

## What this document is for

A PIA asks questions this repository can answer factually and cannot answer legally:

| The DPO decides | This document supplies |
| --- | --- |
| Is this the correct legal basis? | What basis the system is *currently configured* to assume |
| Is this retention period lawful and proportionate? | What period is *currently configured*, and that none is yet approved |
| Is this collection necessary? | What is collected, by which module, and what reads it |
| Who should be able to see this? | Which permission currently gates it |

Every "currently" above is a placeholder in `config/privacy.php`. **While
`PRIVACY_RETENTION_APPROVED` is false, no scheduled deletion occurs anywhere in this system.**

---

## 1. Where personal data lives

One row per owning module. "Sensitivity" uses the system's own `FileClassification` vocabulary
where files are involved, and otherwise describes the category plainly.

| Owning module | Data held | Sensitivity | Configured basis | Retention category | Gated by |
| --- | --- | --- | --- | --- | --- |
| `Identity` | account, email, mobile number, password hash, MFA secrets, device registrations, session tokens | credentials + contact details | `public-task` | `account` | own token; `staff.manage` to provision |
| `ResidentProfile` | name, birth date, sex, civil status, address, barangay, PhilSys/government identifiers, aliases, household and family membership, kinship, vulnerability observations | **the core personal record** | `public-task` (`resident_registry`) | `resident` | `resident.view`, scoped by barangay |
| `ResidentProfile` (protection tier) | safeguarding factors — VAWC, CICL, child-at-risk, trafficking | **the most sensitive data in the system** | `legal-obligation` | `safeguarding` | `vulnerability.view-protected` |
| `Credential` | issued LGU ID records, card artifacts, QR signing material | identity credential | `public-task` (`credential_issuance`) | `resident` | `credential.manage`; own via `me/credential` |
| `Welfare` | case files, intake narratives, assessments, the running record, referrals and their disclosures, field visits, safeguarding concerns | **case narrative — the office's judgements about a household** | `legal-obligation` (`welfare_assistance`) | `welfare_case` | `request.view` (+ `request.view-sensitive`, `case-note.view-protected`) |
| `Welfare` (releases) | amounts in centavos, scheduled and confirmed handovers, acknowledgement details | financial | `legal-obligation` | `release` | `request.release` |
| `Files` | uploaded documents: identity documents, certificates, proofs of residence, safeguarding images | per `FileClassification` | `legal-obligation` (`kyc_verification`) | `document` | the owning module authorises, then calls in |
| `Files` (published media) | re-encoded renditions of newsfeed and event images | **public by design**, metadata-free | `consent` where a person is identifiable (`photography_for_publication`) | — | none: this is the public bucket |
| `Notification` | delivery receipts, push tokens, per-person channel preferences | contact routing | `public-task` | `notification` | own token |
| `Content` | resident comments, reactions, share counts, moderation decisions | citizen speech | `public-task` | — | own; `newsfeed.moderate` |
| `Events` | event registrations, waitlist position, attendance | **attendance at a welfare event reveals need** | `public-task` | — | own; `event.manage` |
| `Reporting` | export requests, the permission context snapshotted at request time, produced files | **a person-level export is a copy of a caseload** | `legal-obligation` (`audit_and_accountability`) | `export` (**24h**) | `report.export-person-level`, `event.export-registrants` |
| `Audit` | who did what to which record, when, with which correlation id | **an act log, never the data acted upon** | `legal-obligation` | `audit` | `audit.view` — held only by `data_protection_officer` |
| `Audit` (governance) | privacy-notice acknowledgements, consent grants and withdrawals, legal holds | governance metadata | `legal-obligation` | `audit` | own; `privacy.manage` |

### Deliberate absences

These are **not** oversights. Each was refused with a stated reason, and several are enforced by a
test that fails the build if reintroduced:

| Not collected | Why | Enforced by |
| --- | --- | --- |
| **Location of any person** — no coordinates, no check-in, no device position | A welfare system that knows where people are is a different system with a different risk profile (ADR 0022 §1) | `NoLocationTrackingTest` |
| **Share recipients** — who a resident sent an advisory to | *"Which platform do people share to?"* is a reasonable product question whose answer turns this into a record of who talks to whom (ADR 0029 §3) | `NoShareRecipientDataTest` |
| **EXIF/GPS in published images** | Never present rather than stripped — derived images are re-encoded from a pixel buffer (ADR 0033 §2) | `MediaSecurityTest` |
| **Old/new values in the audit trail** | A trail that duplicates the data it protects is a second, less-guarded copy of it (ADR 0034 §2) | `AuditAndPrivacyTest` |
| **IP address on routine reads** | A movement log of the office's own staff, disproportionate to any use (ADR 0034 §3) | off by default in `config/audit.php` |
| **Per-caseworker performance reports** | A ranking presents "given the hardest families" as underperformance (ADR 0026 §1) | absent from `ReportCatalog` |
| **A seat counter, an availability column, a stored share destination** | Derived or absent by construction | enforced-absence tests |

---

## 2. Legal bases as currently configured

**Not approved.** `config/privacy.php` → `legal_bases`.

| Purpose | Configured basis | Withdrawable? |
| --- | --- | --- |
| `resident_registry` | `public-task` | **No** |
| `welfare_assistance` | `legal-obligation` | **No** |
| `kyc_verification` | `legal-obligation` | **No** |
| `credential_issuance` | `public-task` | **No** |
| `audit_and_accountability` | `legal-obligation` | **No** |
| `marketing_communications` | `consent` | Yes |
| `photography_for_publication` | `consent` | Yes |
| `referral_to_external_provider` | `consent` | Yes |
| `research_and_statistics` | `consent` | Yes |

The system **refuses** to record a consent for any purpose in the first group. See ADR 0034 §4: a
consent record for statutory processing is a promise of withdrawal the office cannot keep.

---

## 3. Retention as currently configured

**Not approved.** `config/privacy.php` → `retention.categories`. While `retention.approved` is
false, `RetentionPolicy::mayPurge()` refuses everything and states the reason.

| Category | Configured days | Note for the reviewer |
| --- | --- | --- |
| `account` | 2555 (~7y) | While an account can still be recovered |
| `resident` | 3650 (10y) | A resident's relationship with the LGU is lifelong; re-registering somebody already known is its own harm |
| `welfare_case` | 1825 (5y) | Including the running record |
| `release` | 3650 (10y) | Ordinarily governed by COA rules; the longer of the two applies |
| `document` | 1825 (5y) | Mirrors `FileClassification::retentionDays()` |
| `safeguarding` | **1095 (3y)** | **Shortest deliberately.** The one category where retention and protection point in opposite directions, and the protective answer is the shorter one |
| `audit` | 3650 (10y) | **Longer than most of what it describes** — a trail expiring before its records could not answer the question it exists for |
| `notification` | 365 (1y) | No reason to keep a record of every text message for years |
| `export` | **1 (24h)** | A person-level export is a copy of a caseload behind a URL somebody bookmarked |

### Questions this inventory cannot answer

1. Are these periods lawful under RA 10173 and applicable COA/DSWD issuances?
2. Should `safeguarding` be shorter still, or does a protection case require longer?
3. Does the LGU have a records-disposal schedule these must align to?
4. Who signs the approval, and where is that approval recorded?

---

## 4. Cross-border and third-party flows

| Flow | Data | Control |
| --- | --- | --- |
| **Firebase Cloud Messaging** (push transport) | **routing only** — an identifier and a type. No PII, no case narrative, no document identifier | Article 8.3/8.4; `OutboundNotification::routingPayload()` |
| **Akamai Object Storage** (private) | every uploaded document | private disk, no base URL, least-privilege key, authorization-gated delivery |
| **Akamai Object Storage** (public media) | re-encoded published images only | separate bucket, separate credentials, one writer |
| **Netlify** (web delivery) | **none** — static assets only; no secret may be configured there | Article 8.2 |
| **Referrals to external providers** | disclosed fields, per referral | `referral.send` + `referral.disclose-protected`; every disclosure recorded |

**Not used:** Firebase Auth, Firestore, Realtime Database, Firebase Storage. Introducing any as a
parallel authority or store requires a new ADR (Article 8.3).

---

## 5. Data subject rights — where each is served today

| RA 10173 right | Served by | Status |
| --- | --- | --- |
| To be informed | `GET /api/v1/privacy/notice`, public | implemented |
| To access | `me/*` across every module | implemented |
| To object / withdraw consent | `DELETE /api/v1/me/privacy/consents/{purpose}` — for the four purposes where consent is the basis | implemented |
| To rectify | resident correction requests (TAB 09) | implemented |
| To erasure / blocking | — | **gap G-48**: no RA 10173 §16 request lifecycle |
| To damages | — | outside this system |
| To data portability | export machinery (ADR 0026 §3) exists; no subject-initiated export | **gap G-48** |

---

## 6. For the reviewer

The technical controls in this inventory are testable and tested. What is **not** settled, and what
this document exists to put in front of the DPO:

1. **Approve or correct the legal bases** in §2. The system's behaviour depends on them: a purpose
   marked `consent` becomes withdrawable, and one marked otherwise cannot be consented to at all.
2. **Approve or correct the retention periods** in §3, and record who approved them and when
   (`PRIVACY_RETENTION_APPROVED_BY`, `PRIVACY_RETENTION_APPROVED_ON`). Nothing is deleted until
   this happens.
3. **Decide whether IP addresses are captured** on high-risk audit entries
   (`AUDIT_CAPTURE_NETWORK`). Currently off.
4. **Appoint a Data Protection Officer** and assign the `data_protection_officer` role. Until
   somebody holds it, **nobody in this system can read the audit trail** — including the MSWDO
   head, deliberately (ADR 0034 §7).
5. **Decide whether the six permissions parked on `lgu_admin` should move to a protection officer**
   (gap G-30): `vulnerability.view-protected`, `document.view-sensitive`,
   `case-note.view-protected`, `safeguarding.view`, `safeguarding.manage`, and
   `referral.disclose-protected`.
6. **Publish the first privacy notice.** The system ships with none, because a repository that
   published one would be putting words in the DPO's mouth about how the LGU handles residents'
   data.
