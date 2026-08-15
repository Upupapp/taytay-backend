# Frontend → Backend Endpoint Matrix

Every screen in every client, and the backend contract that serves it.

Sources audited: `Taytay_Rizal_Social_Welfare_Angular` (`src/app/app.routes.ts`,
`src/app/core/navigation/navigation.ts`, `src/app/domain/ports/repositories.ts`,
`src/app/data/http/http-repositories.ts`), `Taytay_Rizal_LGUIDS_Resident_Mobile_Flutter`
(`lib/core/api/`, `lib/core/router/`), `lgu_ids_taytay` (`lib/core/services/api_service.dart`,
`lib/features/`).

Conventions that apply to every row without being repeated: `/api/v1` prefix,
`{data,meta}` / `{error:{code,message,details,request_id}}` envelope, `X-Request-Id` on
every response, snake_case fields, UUID identifiers, ISO-8601 UTC timestamps, money as
integer centavos, collections always paginated (`page`, `per_page` default 25 / max 100).
Full text: [`../api/conventions.md`](../api/conventions.md).

**Auth** is `bearer` (first-party Sanctum token, ADR 0005/0006) or `public`.
**Scope** is the server-side data boundary applied *after* the permission check —
`all-barangays`, `own-barangay`, `assigned-cases` for staff (from the Angular
`DataScope`), or `own-record` for a citizen.

> A permission column entry is a **contract**, not a claim that the permission exists in
> code today. The backend `Permission` catalog currently holds only `services.*` — see
> gap **G-09**. Rows marked `implemented` are the exception and do resolve.

---

## 1. Platform

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mobile splash, uptime probe, NodeBalancer | `GET /api/v1/health` | public | — | — | — | `{service,status,api_version}` | none — must never expose env, versions or config | `implemented` |
| Citizen service catalog | `GET /api/v1/services` | public | — | — | `?category=&channel=&page=&per_page=` | published catalog entries | public reference data | `implemented` |
| Admin service catalog | `GET /api/v1/admin/services` | bearer | `services.view_unpublished` widens the result | all-barangays | as above | adds `draft`/`retired` entries | operational | `implemented` |

The two catalog routes share one controller and one application service; the `/admin`
prefix confers nothing. This is the reference pattern every row below follows.

---

## 2. Identity and session

Angular `StaffRepository`; mobile `AuthRepository`. Both clients are unauthenticated
today — the mobile one declines honestly (`PendingBackendAuthRepository`).

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Admin sign-in (`/sign-in`) | `POST /api/v1/auth/tokens` | public | — | — | `{email,password,device_name}` | `201` `{token,expires_at}` or `200` `{status:"mfa-required",challenge}` | credentials — never logged, rate-limited, one generic failure message for every cause | `implemented` |
| Admin sign-in — second factor | `POST /api/v1/auth/tokens/mfa` | public | — | — | `{challenge,code}` | `201` `{token,expires_at}` | challenge is single-use and expires in 5 minutes | `implemented` |
| Citizen sign-in — request code | `POST /api/v1/auth/otp` | public | — | — | `{mobile_number}` | `202` accepted | **identical response whether or not the number is registered** | `implemented` |
| Citizen sign-in — verify code | `POST /api/v1/auth/otp/verify` | public | — | — | `{mobile_number,code,device_name}` | `201` `{token,expires_at}` | attempt-capped; guessing burns the code | `implemented` |
| Session bootstrap (all clients) | `GET /api/v1/me` | bearer | — | own-record | — | actor identity + **server-resolved** `permissions[]`, `roles[]`, `resident_id` | own identity only; `resident_id` grants nothing | `implemented` |
| Sign out | `DELETE /api/v1/auth/tokens/current` | bearer | — | own-record | — | `{status}` | revokes server-side, immediately | `implemented` |
| Forgot password (staff) | `POST /api/v1/auth/password/forgot` | public | — | — | `{email}` | `202` accepted | identical response for unknown addresses | `implemented` |
| Reset password | `POST /api/v1/auth/password/reset` | public | — | — | `{token,password,password_confirmation}` | `{status}` | single-use, 30-minute token; revokes every session on success | `implemented` |
| Angular `signInAs(staffUserId)` | — | — | — | — | — | — | — | `mock-only` |

`verification_tier` is **not** yet on `/me`: identity verification belongs to ResidentProfile
(TAB 06), and Identity only proves control of a contact channel. `/me` returns
`email_verified` and `mobile_verified`, which are the facts Identity actually owns.

`signInAs` posts `{staffUserId}` to `session` with **no credential**
(`http-repositories.ts:172`). As a backend endpoint that is an authentication bypass —
anyone could become the MSWDO head by guessing an id. It exists to switch personas
against mock data and must never be implemented. See gap **G-02**.

`/api/v1/me` returning `permissions[]` is what lets the Angular console stop deriving
authority locally (`toAuthenticatedUser`, gap **G-03**). The client mirrors the server's
answer for usability; it never computes it.

---

## 3. Dashboard — `/dashboard`, `dashboard.view`

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `DashboardPage` | `GET /api/v1/dashboard/summary` | bearer | `dashboard.view` | role scope | — | `DashboardSummary` — counts + `disbursed_this_month` + breakdowns by status, barangay, category | **aggregates only, no person is identifiable**; a barangay bucket below a minimum count must be suppressed, not rounded | `planned` |

A barangay-scoped user gets a summary computed over their own barangay only. The same
service, a different actor — not a second endpoint.

---

## 4. Residents — `/residents`, `resident.view`

Angular `ResidentRepository`. `ResidentListPage` is fully built against mocks.

> **The registry is built — see §11d.** TAB 08 implemented search, detail, create, correct,
> verification, activation, history, account links, duplicates and merge under the `/admin`
> prefix every other staff surface here uses. The rows below keep the shapes the Angular
> repository calls *today* and stay `planned` until that client is repointed (gap **G-19**).
> Household and export remain genuinely unbuilt, in TAB 09 and TAB 21 respectively.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Resident list | `GET /api/v1/residents` | bearer | `resident.view` | role scope | `?search=&barangay_id=&sector=&include_inactive=&sort=&page=&per_page=` | paginated resident summaries | list projection excludes income and PhilSys; sensitive-sector records **redacted server-side** without `request.view-sensitive` | `planned` |
| Resident detail | `GET /api/v1/residents/{resident_id}` | bearer | `resident.view` | role scope | — | full resident | `philsys_last_four` and `monthly_income` require detail view + audit entry | `planned` |
| Household panel | `GET /api/v1/households/{household_id}` | bearer | `resident.view` | role scope | — | household + members | member list is other people's data — audited read | `planned` |
| Create resident | `POST /api/v1/residents` | bearer | `resident.create` | role scope | resident payload | `201` + resident | intake of new personal data | `planned` |
| Update resident | `PATCH /api/v1/residents/{resident_id}` | bearer | `resident.update` | role scope | partial payload | resident | audited field-level change | `planned` |
| Deactivate | `POST /api/v1/residents/{resident_id}/deactivation` | bearer | `resident.deactivate` | all-barangays | `{reason}` | resident | never a hard delete — retention is statutory | `planned` |
| Export | `GET /api/v1/residents/export` | bearer | `resident.export` | role scope | `?` same filters | CSV | **bulk personal data** — always audited, rate-limited, no sensitive-sector rows without `request.view-sensitive` | `planned` |

`sector=vawc-survivor` or `sector=cicl` as a filter is itself a sensitive query: it must
be rejected with `403 FORBIDDEN` for an actor lacking `request.view-sensitive`, rather
than silently returning nothing — a silent empty result teaches a probing caller that the
filter is real.

---

## 5. Assistance requests — `/assistance-requests`, `request.view`

The core casework module. Angular screen is a placeholder; the domain model and lifecycle
are fully specified (`domain/assistance/assistance-request.ts`).

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Request list | `GET /api/v1/assistance-requests` | bearer | `request.view` | role scope | `?search=&status=&program_id=&barangay_id=&assigned_to=&open_only=&sort=&page=&per_page=` | paginated summaries | sensitive-sector cases redacted server-side | `planned` |
| Request detail | `GET /api/v1/assistance-requests/{request_id}` | bearer | `request.view` | role scope | — | request + requirements + assessment + status history | assessment and decision remarks are **staff-only** | `planned` |
| Case notes | `GET /api/v1/assistance-requests/{request_id}/notes` | bearer | `request.view` | role scope | `?visibility=` | notes | `internal` notes are staff-only; see visibility matrix | `planned` |
| Add case note | `POST /api/v1/assistance-requests/{request_id}/notes` | bearer | `request.intake` \| `request.assess` | assigned-cases | `{body,visibility}` | `201` | author recorded, append-only | `planned` |
| Create request | `POST /api/v1/assistance-requests` | bearer | `request.create` | role scope | request payload | `201` | staff-assisted intake | `planned` |
| Lifecycle transition | `POST /api/v1/assistance-requests/{request_id}/transitions` | bearer | per target — see below | assigned-cases | `{to,reason}` + `Idempotency-Key` | request | reason may be applicant-visible; see ADR 0007 | `planned` |
| Requirement review | `PATCH /api/v1/assistance-requests/{request_id}/requirements/{requirement_id}` | bearer | `request.intake` | assigned-cases | `{status,remarks}` | requirement | `remarks` is internal | `planned` |
| Linked payouts | `GET /api/v1/assistance-requests/{request_id}/disbursements` | bearer | `disbursement.view` | role scope | — | disbursements | amounts and instrument references | `planned` |

**One endpoint, not nine verbs.** The Angular port already models this correctly
(`changeStatus(id, to, reason)`). The permission is resolved from the *target* state, so
the state machine and the authorization table stay in one place:

| Target | Permission |
| --- | --- |
| `intake-review` | `request.intake` |
| `assessment` | `request.assess` |
| `endorsed` | `request.endorse` |
| `approved` | `request.approve` |
| `rejected` | `request.reject` |
| `returned` | `request.intake` \| `request.assess` |
| `scheduled` | `request.schedule` |
| `completed` | `request.close` |
| `cancelled` | `request.create` (own draft) \| `request.close` |

The server rejects any transition not permitted by `ASSISTANCE_STATUS_TRANSITIONS` with
`409 INVALID_STATE_TRANSITION`, *before* checking the permission, so a probe cannot use
the error to map who holds what.

**Separation of duties** (Angular `CLAUDE.md` §5, asserted by
`domain/access/permission.spec.ts`): no single non-administrator role may both approve a
request and release its money. The backend must assert this over its own role catalog —
inheriting the constraint, not the client's copy of it.

---

## 6. Programs — `/programs`, `program.view`

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Programme list | `GET /api/v1/programs` | bearer | `program.view` | all-barangays | `?search=&category=&status=&page=&per_page=` | paginated programmes | reference data | `planned` |
| Programme detail | `GET /api/v1/programs/{program_id}` | bearer | `program.view` | all-barangays | — | programme + eligibility + requirements | reference data | `planned` |
| Active programmes (pickers) | `GET /api/v1/programs?status=active` | bearer | `program.view` | all-barangays | — | active programmes | reference data | `planned` |
| Citizen programme list | `GET /api/v1/programs?status=active` | bearer | — | own-record | — | **narrowed projection** — no `funding_source`, no internal notes | see visibility matrix | `planned` |
| Create / edit programme | `POST` / `PATCH /api/v1/programs[/{program_id}]` | bearer | `program.manage` | all-barangays | programme payload | programme | changes eligibility for money | `planned` |

`listActive()` is the same endpoint with a filter, not a second route. The citizen
projection is the same service with a narrower resource — one place to change eligibility
text, not two.

---

## 7. Disbursements — `/disbursements`, `disbursement.view`

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Payout list | `GET /api/v1/disbursements` | bearer | `disbursement.view` | role scope | `?search=&status=&method=&scheduled_from=&scheduled_to=&page=&per_page=` | paginated payouts | financial | `planned` |
| Payout detail | `GET /api/v1/disbursements/{disbursement_id}` | bearer | `disbursement.view` | role scope | — | payout | `instrument_reference` is a financial instrument id — staff-only | `planned` |
| Schedule | `POST /api/v1/disbursements/{disbursement_id}/transitions` `{to:"scheduled"}` | bearer | `disbursement.schedule` | all-barangays | `{scheduled_for,method}` + `Idempotency-Key` | payout | — | `planned` |
| Release | `POST /api/v1/disbursements/{disbursement_id}/transitions` `{to:"released"}` | bearer | `disbursement.release` | all-barangays | `{instrument_reference}` + `Idempotency-Key` | payout | **money leaves here** — idempotency mandatory | `planned` |
| Void | `POST /api/v1/disbursements/{disbursement_id}/transitions` `{to:"voided"}` | bearer | `disbursement.void` | all-barangays | `{reason}` | payout | reason mandatory | `planned` |
| Acknowledge receipt | `POST /api/v1/me/disbursements/{disbursement_id}/acknowledgement` | bearer | — | own-record | `{}` + `Idempotency-Key` | payout summary | beneficiary confirms — closes the loop | `planned` |

Release without an `Idempotency-Key` must be rejected: a retried release on a flaky
connection is a double payout of public funds.

---

## 8. Referrals — `/referrals`, `referral.view`

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Referral list | `GET /api/v1/referrals` | bearer | `referral.view` | role scope | `?search=&status=&destination=&page=&per_page=` | paginated referrals | `barangay-vaw-desk` / WCPD destinations are themselves sensitive — see below | `planned` |
| Referral detail | `GET /api/v1/referrals/{referral_id}` | bearer | `referral.view` | role scope | — | referral | `reason` and `outcome` are case narrative — staff-only | `planned` |
| Create / update | `POST` / `PATCH /api/v1/referrals[/{referral_id}]` | bearer | `referral.manage` | assigned-cases | referral payload | referral | — | `planned` |

A referral to `barangay-vaw-desk` or `women-and-children-protection-desk` discloses, by
its destination alone, that the resident is likely a VAWC survivor (RA 9262). Those rows
are gated on `request.view-sensitive` exactly as a flagged record is.

---

## 9. Reports — `/reports`, `report.view`

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Report catalog | `GET /api/v1/reports` | bearer | `report.view` | role scope | — | available reports | — | `planned` |
| Run report | `GET /api/v1/reports/{report_code}` | bearer | `report.view` | role scope | report params | aggregate result | **aggregates only** | `planned` |
| Export report | `GET /api/v1/reports/{report_code}/export` | bearer | `report.export` | role scope | report params | CSV | bulk export — audited, rate-limited | `planned` |

Statutory DSWD reporting keys off PSGC codes, which are `null` in the client today —
gap **G-11**.

---

## 10. Administration

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Staff list (`/administration/staff`) | `GET /api/v1/staff` | bearer | `staff.view` | all-barangays | `?page=&per_page=` | paginated staff + effective authority | staff personal data — never reaches a citizen channel | `implemented` |
| Staff detail | `GET /api/v1/staff/{staff}` | bearer | `staff.view` | all-barangays | — | staff + effective authority | — | `implemented` |
| Create staff | `POST /api/v1/staff` | bearer | `staff.manage` | all-barangays | `{email, display_name}` | staff (no authority yet) | **no password is set or returned** — staff activate through password reset | `implemented` |
| Deactivate staff | `DELETE /api/v1/staff/{staff}` | bearer | `staff.manage` | all-barangays | — | staff | drops every live token; role history is retained as evidence | `implemented` |
| Assign role + scope | `POST /api/v1/staff/{staff}/roles` | bearer | `staff.manage` | all-barangays | `{role, scope_type, barangay_id?}` | effective authority | **privilege granting** — audited; a granter may not exceed their own authority or act on themselves (ADR 0012) | `implemented` |
| Revoke role | `DELETE /api/v1/staff/{staff}/roles/{role}` | bearer | `staff.manage` | all-barangays | — | effective authority | ends validity, never deletes the row | `implemented` |
| Grant extra barangay | `POST /api/v1/staff/{staff}/barangays` | bearer | `staff.manage` | granter's own scope | `{barangay_id, reason, valid_until?}` | effective authority | the only way to widen a barangay scope; reason mandatory | `implemented` |
| Revoke barangay grant | `DELETE /api/v1/staff/{staff}/barangays/{barangay}` | bearer | `staff.manage` | all-barangays | — | effective authority | — | `implemented` |
| Authority catalog (grant screen) | `GET /api/v1/staff/authority-catalog` | bearer | `staff.view` | — | — | permissions, roles (+`grantable`), scope types | reference data — knowing a permission name grants nothing | `implemented` |
| Audit trail (`/administration/audit`) | `GET /api/v1/audit-entries` | bearer | `audit.view` | all-barangays | `?entity_type=&entity_id=&actor_id=&from=&to=&page=&per_page=` | paginated entries | the audit log itself is sensitive; **append-only, never editable or deletable** | `planned` |
| Settings (`/administration/settings`) | `GET` / `PATCH /api/v1/settings` | bearer | `settings.manage` | all-barangays | settings payload | settings | office configuration | `planned` |
| Barangay reference list | `GET /api/v1/barangays` | bearer | — | — | — | barangays + PSGC | public reference data | `planned` |

---

## 11. Notifications (shell, all channels)

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Inbox | `GET /api/v1/me/notifications` | bearer | — | own-record | `?unread_only=&page=&per_page=` | paginated notifications | recipient's own only | `planned` |
| Mark read | `POST /api/v1/me/notifications/{notification_id}/read` | bearer | — | own-record | — | notification | — | `planned` |
| Mark all read | `POST /api/v1/me/notifications/read-all` | bearer | — | own-record | — | `204` | — | `planned` |
| Device registration (push) | `POST /api/v1/me/devices` | bearer | — | own-record | `{fingerprint,display_name,platform,push_token}` | `201` `{id,display_name}` | fingerprint hashed, push token encrypted, never returned | `implemented` |
| List devices | `GET /api/v1/me/devices` | bearer | — | own-record | — | own devices | push token never returned | `implemented` |
| Revoke device | `DELETE /api/v1/me/devices/{device}` | bearer | — | own-record | — | `{status}` | clears the push token as well as the flag | `implemented` |
| Angular `NotificationRepository.create()` | — | — | — | — | — | — | — | `mock-only` |

A client that can create its own notifications can forge an official LGU message. Raising
a notification is a **server-side consequence of a domain event** (ADR 0004: Laravel
decides, FCM only delivers). The client-side `create()` is the mock adapter's way of
showing a local toast and must stay local — the `NotificationStore` toast path needs no
backend at all.

---

## 11a. Account security (all channels) — built in TAB 05

Every route is scoped to the caller by construction: the account is resolved from the
token, never from a path or body parameter, so there is no identifier to tamper with.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| List my sessions | `GET /api/v1/me/sessions` | bearer | — | own-record | — | sessions with `current` flag | own sessions only; other accounts' return `404` | `implemented` |
| Revoke one session | `DELETE /api/v1/me/sessions/{session}` | bearer | — | own-record | — | `{status}` | `404` not `403` for another account's session | `implemented` |
| Revoke all sessions | `POST /api/v1/me/sessions/revoke-all` | bearer | — | own-record | — | `{status,count}` | includes the current session — the "lost phone" control | `implemented` |
| Verify a contact channel | `POST /api/v1/me/contact/verify` | bearer | — | own-record | `{channel}` | `202` | code never returned or logged | `implemented` |
| Confirm a contact channel | `POST /api/v1/me/contact/verify/confirm` | bearer | — | own-record | `{channel,code}` | `{status}` | proves control of a channel, **not** identity | `implemented` |
| Begin MFA enrolment (staff) | `POST /api/v1/me/mfa` | bearer | staff account type | own-record | — | `201` `{secret,otpauth_uri}` | secret shown once; `403` for citizens | `implemented` |
| Confirm MFA enrolment | `POST /api/v1/me/mfa/confirm` | bearer | staff account type | own-record | `{code}` | `{status,recovery_codes}` | recovery codes shown **once**, stored hashed | `implemented` |
| Regenerate recovery codes | `POST /api/v1/me/mfa/recovery-codes` | bearer | staff account type | own-record | `{code}` | `{recovery_codes}` | requires a current second factor | `implemented` |
| Disable MFA | `DELETE /api/v1/me/mfa` | bearer | staff account type | own-record | `{code}` | `{status}` | lowering protection is privileged — requires a current second factor | `implemented` |

MFA applies to staff because they read other people's welfare records. Citizens
authenticate with a code to their mobile, which is already a possession factor; requiring
TOTP as well would push residents off the service entirely.

## 11b. Citizen onboarding and KYC — built in TAB 06

The rule the whole section turns on: **nothing here creates a canonical resident.**
Registration and submission touch only the KYC case; `residents` is written exclusively by
a reviewer's approval (ADR 0010).

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Start registration | `POST /api/v1/me/kyc` | bearer | — | own-record | claimed name, birth date, sex, barangay, address | `201` case | **idempotent** — a second call returns the same case, never a second one | `implemented` |
| My application | `GET /api/v1/me/kyc` | bearer | — | own-record | — | status, own claims, reviewer message | **no candidates, no internal reasons** — an applicant must never learn their record resembles someone else's | `implemented` |
| Submit for review | `POST /api/v1/me/kyc/submit` | bearer | — | own-record | — | case in `manual-review` | screening can never reach `approved` | `implemented` |
| Review queue | `GET /api/v1/admin/kyc-cases` | bearer | `kyc.review` | role scope | `?status=&page=&per_page=` | paginated cases | claimed identity only | `implemented` |
| Case detail | `GET /api/v1/admin/kyc-cases/{case}` | bearer | `kyc.review` | role scope | — | case + match candidates | candidates carry name, birth date, barangay — not the other resident's income, sectors or case history | `implemented` |
| Re-run matching | `POST /api/v1/admin/kyc-cases/{case}/rescreen` | bearer | `kyc.review` | role scope | — | candidates | idempotent; decisions already made are preserved | `implemented` |
| Rule on a candidate | `POST /api/v1/admin/kyc-cases/{case}/candidates/{candidate}` | bearer | `kyc.review` | role scope | `{decision}` | candidates | the step that actually prevents duplicates | `implemented` |
| Approve | `POST /api/v1/admin/kyc-cases/{case}/approve` | bearer | `kyc.approve` | role scope | `{link_resident_id?,message?}` | approved case | **the only path to a canonical resident**; refused while any candidate is undecided | `implemented` |
| Reject | `POST /api/v1/admin/kyc-cases/{case}/reject` | bearer | `kyc.approve` | role scope | `{reason,message?}` | rejected case | internal reason and applicant message are separate fields | `implemented` |
| Ask for more | `POST /api/v1/admin/kyc-cases/{case}/request-information` | bearer | `kyc.review` | role scope | `{message}` | case | returns the case to the applicant | `implemented` |

`kyc.review` and `kyc.approve` are separate permissions on purpose: deciding that two
records are the same person and deciding that somebody becomes a verified resident are
different responsibilities, and an LGU may want them held by different people.

## 11c. Digital ID — built in TAB 06, **feature-flagged off**

Every route below returns `404` while `credential.digital_id.enabled` is false, which is
the default. A feature that is not live should look absent rather than forbidden.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My digital ID | `GET /api/v1/me/credential` | bearer | — | own-record | — | serial, status, validity | resolved from the token — no identifier to tamper with | `implemented` |
| Mint a QR | `POST /api/v1/me/credential/qr` | bearer | — | own-record | — | `{payload,expires_at}` | payload is a **handle**: serial, expiry, nonce, key id, signature — and nothing about the holder | `implemented` |
| Verify a scan | `POST /api/v1/credential-verifications` | bearer | — | — | `{payload}` | `{outcome,valid,serial,expires_at,holder_name}` | **the minimal response** — no birth date, address, barangay, sectors, income or case data | `implemented` |
| Issue | `POST /api/v1/admin/credentials` | bearer | `credential.manage` | all-barangays | `{resident_id}` | `201` credential | only for a fully verified resident; idempotent | `implemented` |
| Revoke | `POST /api/v1/admin/credentials/{credential}/revoke` | bearer | `credential.manage` | all-barangays | `{reason}` | `{status}` | validity is decided at scan time, so revocation is immediate | `implemented` |

QR payloads are single-use (nonce, enforced by a unique index) and short-lived (90s), so a
photographed code is worthless. Verification is authenticated for attribution but requires
no permission — a verifier device is not staff.

## 11d. Canonical resident registry — built in TAB 08

The registry the Angular `/residents` screen (§4) was designed against, now real. The
built routes carry the `/admin` prefix used by every other staff surface here; the §4 rows
keep the shapes the Angular repository currently calls and stay `planned` until that client
is repointed — see gap **G-19**.

### Citizen — the resident's own record

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My profile | `GET /api/v1/me/profile` | bearer | — | own-record | — | own canonical record + `editable_fields[]` / `requestable_fields[]` | resolved from the token — **no identifier in the contract to tamper with**; omits income, PhilSys digits, matching fingerprint, sector tags and all internal history | `implemented` |
| File a correction | `POST /api/v1/me/profile/corrections` | bearer | — | own-record | `{changes:{…},note?}` | `201` request — `approved` if self-service only, else `pending` | address and contact apply immediately; **name, birth date, sex, civil status and barangay are proposals only**; `verification_tier` and `is_active` are rejected, never ignored | `implemented` |
| My corrections | `GET /api/v1/me/profile/corrections` | bearer | — | own-record | `?page=&per_page=` | paginated own requests | reviewer's note is returned, reviewer's identity is not | `implemented` |
| Withdraw | `DELETE /api/v1/me/profile/corrections/{correction}` | bearer | — | own-record | — | withdrawn request | scoped to the caller's own resident — another citizen's id resolves to `404`, not `403` | `implemented` |

### Staff — the registry

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Resident search | `GET /api/v1/admin/residents` | bearer | `resident.view` | role scope | `?q=&barangay_id=&verification_tier=&status=&page=&per_page=` | paginated summaries | scoped **at the query**, so the pagination total never counts another barangay's rows; `q` also matches preserved aliases | `implemented` |
| Resident detail | `GET /api/v1/admin/residents/{resident}` | bearer | `resident.view` | role scope | — | operational record | **audited read** (`resident.viewed`); income, PhilSys digits and the fingerprint are absent by construction | `implemented` |
| Create resident | `POST /api/v1/admin/residents` | bearer | `resident.manage` | role scope | resident payload | `201` resident | always starts `unverified` — there is no field by which a create can assert a tier | `implemented` |
| Correct fields | `PATCH /api/v1/admin/residents/{resident}` | bearer | `resident.manage` | role scope | partial payload + `reason?` | resident | every field writes a history row; a name change records an alias and re-keys the fingerprint | `implemented` |
| Change verification | `POST /api/v1/admin/residents/{resident}/verification` | bearer | `resident.verify` | role scope | `{verification_tier,reason}` | resident | **reason mandatory in both directions**; demotion clears `verified_at` | `implemented` |
| Deactivate / reactivate | `POST /api/v1/admin/residents/{resident}/activation` | bearer | `resident.verify` | role scope | `{is_active,reason}` | resident | never a hard delete — retention is statutory | `implemented` |
| Record history | `GET /api/v1/admin/residents/{resident}/history` | bearer | `resident.view` | role scope | — | `{events[],aliases[]}` | audited read; append-only, carries before/after per field | `implemented` |

### Staff — correction review

Only requests touching a reviewed field ever reach this queue. A resident changing their own
mobile number is applied immediately and recorded — making staff rubber-stamp phone numbers
would bury the requests that actually matter, which are the ones proposing to change a
verified name or birth date.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Correction queue | `GET /api/v1/admin/resident-corrections` | bearer | `resident.manage` | role scope | `?status=&page=&per_page=` | paginated requests | scoped through the resident's barangay by subquery — the correction table carries no barangay of its own, and denormalising one would be a second copy of a fact that moves whenever a resident does | `implemented` |
| Correction detail | `GET /api/v1/admin/resident-corrections/{correction}` | bearer | `resident.manage` | role scope | — | request + per-field `current`/`proposed` | both values shown so a reviewer can see the record moved since filing, rather than approving a stale proposal | `implemented` |
| Approve | `POST /api/v1/admin/resident-corrections/{correction}/approve` | bearer | `resident.manage` | role scope | `{review_note?}` | approved request | applied through the registry, so it produces the same history, alias and fingerprint rebuild as any other edit; a resolved request cannot be decided twice (`409`) | `implemented` |
| Reject | `POST /api/v1/admin/resident-corrections/{correction}/reject` | bearer | `resident.manage` | role scope | `{review_note}` | rejected request | **note mandatory** — a refusal the resident cannot read is one they cannot act on or appeal (RA 10173 §16) | `implemented` |

### Staff — account links

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Link review | `GET /api/v1/admin/residents/{resident}/account-links` | bearer | `resident.link_review` | role scope | — | active and revoked links | who linked whom, when and on what authority | `implemented` |
| Attach an account | `POST /api/v1/admin/residents/{resident}/account-links` | bearer | `resident.link_review` | role scope | `{account_id}` | `201` link | refuses a staff account, and refuses an account already linked elsewhere (`409`) rather than silently repointing it | `implemented` |
| Withdraw a link | `DELETE /api/v1/admin/residents/{resident}/account-links/{link}` | bearer | `resident.link_review` | role scope | `{reason}` | revoked link | the row is **kept and marked revoked** — "this account could once act for that resident" is what a privacy complaint asks about | `implemented` |

### Staff — duplicates and merge

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Duplicate queue | `GET /api/v1/admin/resident-duplicates` | bearer | `resident.merge` | role scope | `?decision=&page=&per_page=` | paginated pairs | a pair outside the caller's scope is omitted from the page, not summarised | `implemented` |
| Run detection | `POST /api/v1/admin/resident-duplicates/detect` | bearer | `resident.merge` | role scope | `?barangay_id=` | counts | deterministic only; idempotent, and decisions already made survive a re-run | `implemented` |
| Rule on a pair | `POST /api/v1/admin/resident-duplicates/{pair}/decide` | bearer | `resident.merge` | role scope | `{decision,note?}` | pair | `same-person` **does not merge** — it only unlocks the merge call | `implemented` |
| Merge preview | `POST /api/v1/admin/resident-duplicates/{pair}/preview` | bearer | `resident.merge` | role scope | `{survivor_resident_id}` | field-by-field comparison, conflicts, reassignment counts | the reviewer's last chance to notice the two records disagree about a birth date | `implemented` |
| Execute merge | `POST /api/v1/admin/resident-duplicates/{pair}/merge` | bearer | `resident.merge` | role scope | `{survivor_resident_id,reason}` | merge record | **one transaction**; refused unless a reviewer confirmed this exact pair; absorbed row is soft-deleted, never destroyed | `implemented` |

Scope is enforced on **both** residents in a pair. A clerk who could merge a record they
can see into one they cannot would be able to move a resident beyond their own reach, or
rewrite a record in a barangay they were never granted.

`resident.merge` belongs to no role by default beyond `lgu_admin`. It is the most
destructive permission in the catalog: a wrong merge makes one resident disappear and hands
their assistance history to somebody else, by destroying the evidence that they were two
people.

---

## 12. Citizen clients

Sources: `Taytay_Rizal_LGUIDS_Resident_Mobile_Flutter` (aligned to this contract already)
and `lgu_ids_taytay` (broader feature set, placeholder API). See gap **G-10** on which is
authoritative.

Citizen-owned collections live under `/me/` deliberately: ownership becomes structural
rather than a filter someone can forget to apply, and there is no path on which a citizen
could even attempt to enumerate another resident. The `/me/` routes call the **same
application services** as the staff routes above, with an actor-derived `own-record`
scope — not a parallel implementation.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Home, profile | `GET /api/v1/me` | bearer | — | own-record | — | actor + `verification_tier` | own only | `planned` |
| Profile update | `PATCH /api/v1/me/profile` | bearer | — | own-record | contact fields only | profile | a citizen may not edit their own eligibility-bearing fields | `planned` |
| Verification status | `GET /api/v1/me/verification` | bearer | — | own-record | — | tier + outstanding steps | own only | `planned` |
| Submit verification | `POST /api/v1/me/verification/submissions` | bearer | — | own-record | document/selfie upload | `202` | biometric + ID images — private object storage only | `planned` |
| Digital ID / credential | `GET /api/v1/me/credential` | bearer | — | own-record | — | credential + signed QR payload | **QR signing material never leaves the server**; the client receives a signed artifact only | `planned` |
| Credential verification (kiosk) | `POST /api/v1/credential-verifications` | bearer | — | — | `{qr_payload}` | validity + minimal display fields | verifier sees name + photo + validity, **never** address, sectors or case data | `planned` |
| My assistance requests | `GET /api/v1/me/assistance-requests` | bearer | — | own-record | `?status=&page=&per_page=` | citizen projection | see visibility matrix | `planned` |
| Request detail | `GET /api/v1/me/assistance-requests/{request_id}` | bearer | — | own-record | — | citizen projection + `available_actions` | **no assessment, no internal notes, no staff identities** | `planned` |
| Submit request | `POST /api/v1/me/assistance-requests` | bearer | — | own-record | request payload + `Idempotency-Key` | `201` | self-service intake | `planned` |
| Cancel request | `POST /api/v1/me/assistance-requests/{request_id}/transitions` `{to:"cancelled"}` | bearer | — | own-record | `{reason}` | citizen projection | server decides cancellability — ADR 0007 | `planned` |
| Resubmit documents | `POST /api/v1/me/assistance-requests/{request_id}/requirements/{requirement_id}` | bearer | — | own-record | upload | requirement | private object storage | `planned` |
| Announcements (`balita`) | `GET /api/v1/announcements` | public | — | — | `?page=&per_page=` | announcements | public content | `planned` |
| Events | `GET /api/v1/events` | public | — | — | `?page=&per_page=` | events | public content | `planned` |
| Emergency hotlines | `GET /api/v1/emergency-hotlines` | public | — | — | — | hotlines | public content | `planned` |

### Citizen web vs citizen mobile

There is **no row in this matrix that exists for one and not the other.** Both are
`X-Client-Channel` values over the identical routes above. The channel may set a default
page size and is recorded for audit; it grants nothing and changes no rule
(`ClientChannelIsNotAuthorityTest`). Anything a citizen may do on mobile, they may do on
web, because it is the same service and the same authorization decision.

---

## 13. Screens with no backend contract

| Screen | Why |
| --- | --- |
| `/forbidden` | Renders the client's reaction to a `403 FORBIDDEN`. No data. |
| `/**` (not found) | Client-side routing only. |
| Placeholder pages | `FeaturePlaceholderPage` is a build-status notice; the endpoints its real screen will need are listed above under that screen's section. |

Every other route in `app.routes.ts` has at least one contract row.
