# Client Visibility and Sensitivity Matrix

Which channel may see which field. This is an **authorization** document, not a styling
one: every exclusion here is enforced server-side by omitting the field from the response,
not by a client choosing not to render it.

Governing law and rules: RA 10173 (Data Privacy Act), RA 11055 (PhilSys), RA 9262 (VAWC),
RA 9344 (CICL); CLAUDE.md Articles 3.6 and 5; ADR 0002.

## Channels

| Channel | Who | Baseline |
| --- | --- | --- |
| `citizen-web`, `citizen-mobile` | a resident acting for themselves | **own records only** |
| `verifier-device` | a kiosk checking a credential | validity + minimum identity to match a face to a card |
| `admin-console` | LGU staff | role permissions **and** data scope |

Citizen web and citizen mobile share one column throughout. They are two deliveries of the
same services; a field visible on one is visible on the other, because the decision is
made once, server-side, from the actor.

## Legend

`✅` returned · `⛔` never returned to this channel · `🔒` returned only with the stated
permission · `own` returned only for the actor's own record

---

## 1. The internal-field exclusion list

**These never appear in any citizen or verifier response, in any endpoint, ever.** A
citizen projection is built by naming the fields to include, never by taking the staff
resource and deleting fields — the second approach leaks every field somebody forgets.

| Field / resource | Why it is internal |
| --- | --- |
| `CaseNote` where `visibility = "internal"` | Case narrative written between staff about the applicant. |
| `SocialWorkerAssessment` — `findings`, `recommended_amount`, `home_visit_conducted`, `assessed_by`, `assessed_at` | A case study is a professional assessment *about* a person, not a message *to* them. Disclosure changes what social workers are willing to write. |
| `AssistanceRequest.decision_remarks` | Internal justification. The applicant gets the applicant-facing reason (ADR 0007), not the deliberation. |
| `AssistanceRequest.assigned_to` | Naming the handling social worker exposes staff to pressure and, in VAWC cases, to danger. |
| `SubmittedRequirement.reviewed_by`, `.remarks` | Reviewer identity and internal review notes. The citizen sees the requirement's *status* and an applicant-facing instruction. |
| `StatusChange.actor_id`, `.actor_name` | Who moved the case. The citizen sees that it moved and when. |
| `AuditStamp.created_by`, `.updated_by` | Staff identifiers on every record. |
| `AuditEntry` (whole resource) | The audit trail is staff oversight material and itself sensitive. |
| `StaffUser` (whole resource) | Staff directory, roles, positions, barangay assignment. No citizen endpoint returns any part of it. |
| `Referral.reason`, `.outcome`, `.referred_by` | Case narrative and staff identity. A citizen sees *that* they were referred and to which office. |
| `Disbursement.instrument_reference`, `.released_by`, `.remarks` | Financial instrument identifiers and internal remarks. |
| `Program.funding_source` | Budget-line information; operationally useful internally, meaningless and misleading to an applicant. |
| Any other resident's data — `Household.members`, another resident's `monthly_income` | Cross-resident access is a critical defect (CLAUDE.md Article 5.3). |

The verifier device is even narrower: it receives **only** a validity decision and the
minimum needed to match a person to a credential. It receives no case data, no address,
no sector, no income — a kiosk operator is not an LGU case worker.

---

## 2. Resident

| Field | citizen | verifier | admin-console |
| --- | --- | --- | --- |
| `id` | own | ⛔ | ✅ |
| `name` | own | ✅ | ✅ |
| `sex`, `birth_date`, `civil_status` | own | `birth_date` only, for age checks | ✅ |
| `address.barangay_id` | own | ⛔ | ✅ |
| `address.street_address`, `.purok_or_sitio` | own | ⛔ | ✅ — full address never enters a log (Article 5.5) |
| `contact.mobile`, `.email` | own | ⛔ | ✅ |
| `sectors` (non-sensitive) | own | ⛔ | ✅ |
| `sectors` incl. `vawc-survivor`, `cicl` | own | ⛔ | 🔒 `request.view-sensitive` |
| `philsys_last_four` | own | ⛔ | 🔒 detail view only, audited |
| `monthly_income` | own | ⛔ | ✅ detail view; ⛔ in list projection |
| `is_active` | ⛔ | ⛔ | ✅ |
| `audit.created_by`, `.updated_by` | ⛔ | ⛔ | ✅ |
| `household` membership | own household only | ⛔ | ✅ within scope |

**Only the last four digits of a PhilSys number are ever stored** (RA 11055). There is no
column for the full PSN anywhere in the schema — a field that does not exist cannot leak.

### Sensitive-sector records

For an actor without `request.view-sensitive`, a `vawc-survivor` or `cicl` record is
returned **with the sensitive sector values omitted from `sectors`**, and its case records
are excluded from list results entirely.

The Angular reference masks these in presentation and says so explicitly: *"the adapter
returns the full record, suppression is a presentation decision, and the API enforces its
own copy"* (`decision-log.md` DL-19). This document is that copy — and it is not masking.
The backend does not send the value at all. A masked field that travels to the browser is
one devtools panel away from being unmasked.

---

## 3. Assistance request

| Field | citizen (own) | admin-console |
| --- | --- | --- |
| `id`, `reference_number` | ✅ | ✅ |
| `status` | ✅ as a citizen-facing projection (ADR 0007) | ✅ raw lifecycle state |
| `status_message` | ✅ plain-language, translated | ⛔ not needed |
| `available_actions` | ✅ server-computed | ✅ |
| `program` (code, name) | ✅ | ✅ |
| `requested_amount` | ✅ | ✅ |
| `approved_amount` | ✅ once `approved` | ✅ |
| `reason_for_request` | ✅ own words | ✅ |
| `requirements[].code/.label/.status/.is_mandatory` | ✅ | ✅ |
| `requirements[].applicant_instruction` | ✅ what to do next | ✅ |
| `requirements[].remarks`, `.reviewed_by` | ⛔ | ✅ |
| `assessment` (whole object) | ⛔ | 🔒 `request.assess` \| `request.approve` |
| `decision_remarks` | ⛔ | ✅ |
| `decision_reason_public` | ✅ when rejected or returned | ✅ |
| `assigned_to` | ⛔ | ✅ |
| `status_history[]` — `to`, `occurred_at` | ✅ milestone timeline | ✅ |
| `status_history[]` — `actor_id`, `actor_name`, internal `reason` | ⛔ | ✅ |
| `notes` where `visibility = "shared-with-applicant"` | ✅ | ✅ |
| `notes` where `visibility = "internal"` | ⛔ | ✅ |
| `barangay_id`, `resident_id` | ⛔ (implied by ownership) | ✅ |
| `audit` | ⛔ | ✅ |

`CaseNoteVisibility` already exists in the Angular domain
(`'internal' | 'shared-with-applicant'`). The backend adopts it as the **authorization
discriminator** for the notes endpoint: the citizen projection filters on it server-side,
so an internal note has no path to a citizen response.

The citizen timeline is milestones, not deliberation. `lgu_ids_taytay`'s detail screen
renders `item.remarks` on timeline entries; those must be populated from
`decision_reason_public`, never from `decision_remarks` or assessment findings.

---

## 4. Disbursement

| Field | citizen (own) | admin-console |
| --- | --- | --- |
| `id`, `reference_number` | ✅ | ✅ |
| `status`, `method`, `amount` | ✅ | ✅ |
| `scheduled_for` | ✅ — when and where to collect | ✅ |
| `released_at`, `acknowledged_at` | ✅ | ✅ |
| `instrument_reference` | ⛔ | ✅ |
| `released_by` | ⛔ | ✅ |
| `remarks` | ⛔ | ✅ |

A beneficiary needs to know *when and how much*. Cheque numbers and the releasing
officer's identity serve internal control, not the beneficiary.

---

## 5. Program

| Field | citizen | admin-console |
| --- | --- | --- |
| `code`, `name`, `category`, `description` | ✅ | ✅ |
| `status` | ✅ `active` only | ✅ all |
| `legal_basis` | ✅ — a citizen is entitled to know the basis of a benefit | ✅ |
| `maximum_grant` | ✅ | ✅ |
| `eligibility` (age, sectors, income ceiling, residency) | ✅ — needed to self-assess | ✅ |
| `requirements[]` | ✅ — needed to prepare documents | ✅ |
| `funding_source` | ⛔ | ✅ |
| `effective_from`, `effective_to` | ✅ | ✅ |
| `audit` | ⛔ | ✅ |

Eligibility rules are deliberately public. Publishing the criteria for a public benefit is
good administration, and it lets a citizen self-screen instead of queueing to be refused.

---

## 6. Credential (digital ID) and verification

| Field | citizen (own) | verifier-device | admin-console |
| --- | --- | --- | --- |
| Credential number, holder name, photo, validity dates | ✅ | ✅ | ✅ |
| `status` (active / suspended / expired / revoked) | ✅ | ✅ as a validity verdict | ✅ |
| Signed QR payload | ✅ own credential | ⛔ — submits one, never receives one | ✅ |
| **QR signing keys** | ⛔ | ⛔ | ⛔ — server-side only, never in any response |
| Address, sectors, income, case history | own | ⛔ | ✅ within scope |
| Verification tier, outstanding steps | own | ⛔ | ✅ |
| Uploaded ID images, selfie | own | ⛔ | 🔒 verification reviewers only |

Verification is a **server-side cryptographic decision** (CLAUDE.md Article 5.7). A client
reporting "valid" is not evidence; the verifier device posts the scanned payload and
receives the server's verdict.

---

## 7. Notification payloads

Push travels through FCM, a third party, and renders on a lock screen.

| In a push payload | Allowed |
| --- | --- |
| Notification type / event code | ✅ |
| Resource identifier (UUID) | ✅ |
| Title from a fixed, non-personal template | ✅ |
| Applicant name, case narrative, amount, programme name, document identifier, sector | ⛔ |

*"Your assistance request TYT-AR-2026-0500 has an update"* is acceptable.
*"Your burial assistance for ₱10,000 was approved"* is not: it discloses a bereavement and
a benefit to anyone glancing at the phone, and it puts personal data in a third-party
transport (CLAUDE.md Article 8.4). The client fetches detail over the authenticated API.

The same rule governs Crashlytics keys and Analytics properties: identifiers and types
only, never personal data.

---

## 8. Enforcement

| Rule | How it is enforced |
| --- | --- |
| Citizen projections are built by inclusion | Separate resource classes per channel — never a staff resource with fields removed |
| Sensitive sectors omitted, not masked | Server-side field suppression before serialization |
| Cross-resident access impossible | `/me/` routes resolve the resource from the actor, never from a client-supplied id |
| Existence not disclosed | `404 NOT_FOUND` rather than `403 FORBIDDEN` where confirming existence leaks (conventions §4) |
| Every read of another person's data | Audit entry, append-only |
| No personal data in push/telemetry | Payload allow-list above |

Each of these needs a feature test at the point the endpoint is built, including the
**unauthorized path** (CLAUDE.md Article 7.2). A citizen projection without a test proving
an internal field is absent is not finished.
