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
> The household panel is now built too — see §11e — under the same `/admin` prefix and the
> same gap. Export remains genuinely unbuilt, in TAB 21.

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

## 11e. Households, families and kinship — built in TAB 09

A household is who sleeps under one roof; a family is who belongs to one another. Several
families per household is the normal case, and the two counts drive different programmes
(relief per household, 4Ps per family), so both are first-class. Full reasoning: ADR 0014.

Membership is **effective-dated**. Nothing is edited or deleted — a move closes one row and
opens another, so "who lived here when the October relief was released" stays answerable in
November.

Reads take `resident.view`, not a separate household permission: a household is a group of
residents and opening one reveals their data, so a "household viewer" permission would be a
way to enumerate residents without holding the permission that guards them.

### Citizen

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My household | `GET /api/v1/me/household` | bearer | — | own-record | — | address, co-members by name, `relationship_to_me`, family units with `is_mine` | **minimised** — co-members carry no tier, sectors, income, contacts or case data; the home carries no verification status, completeness, dwelling or utility assessment. Exception: for members the caller is recorded responsible for (child/ward/provider), birth date and tier are included — resolved from recorded kinship, **never inferred from co-residence** | `implemented` |

### Staff — households

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Household list | `GET /api/v1/admin/households` | bearer | `resident.view` | role scope | `?q=&barangay_id=&status=&page=&per_page=` | paginated summaries + derived `member_count` | scoped at the query, so the pagination total never counts another barangay's rows | `implemented` |
| Household detail | `GET /api/v1/admin/households/{household}` | bearer | `resident.view` | role scope | — | household + members + families | **audited read** — the member list is other people's personal data; each member carries name, birth date and tier only, never their welfare file | `implemented` |
| Create | `POST /api/v1/admin/households` | bearer | `household.manage` | role scope | address + dwelling/utility facts | `201` household | refuses a barangay the caller does not serve — otherwise the record lands where its own office cannot see it | `implemented` |
| Update | `PATCH /api/v1/admin/households/{household}` | bearer | `household.manage` | role scope | partial payload | household | `profile_completeness` recomputed on write; never an eligibility input | `implemented` |
| Name the head | `POST /api/v1/admin/households/{household}/head` | bearer | `household.manage` | role scope | `{resident_id\|null}` | household | nominee must be a current member; **headship confers no read access** over the other members | `implemented` |
| Field verification | `POST /api/v1/admin/households/{household}/verification` | bearer | `household.manage` | role scope | `{verification_status}` | household | demotion clears `verified_at` | `implemented` |
| Dissolve / archive | `POST /api/v1/admin/households/{household}/status` | bearer | `household.manage` | role scope | `{status,reason}` | household | never a delete — assistance history references the household that received it | `implemented` |

### Staff — membership and transfer

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Add member | `POST /api/v1/admin/households/{household}/members` | bearer | `household.manage` | role scope | `{resident_id,effective_from?}` | `201` membership | idempotent; a resident already housed elsewhere is **refused (409)**, never silently moved | `implemented` |
| Remove member | `DELETE /api/v1/admin/households/{household}/members/{resident}` | bearer | `household.manage` | role scope | `{end_reason,effective_to?}` | closed membership | closes family memberships inside that household too, and clears headship if they held it | `implemented` |
| Transfer | `POST /api/v1/admin/households/{household}/transfers` | bearer | `household.manage` | role scope | `{resident_id,reason,effective_from?}` | new membership | **one call, one transaction** — a half-transfer would leave a real person belonging to no household | `implemented` |
| Residence history | `GET /api/v1/admin/residents/{resident}/households` | bearer | `resident.view` | role scope | — | every membership, newest first | audited read; the record that makes a past distribution auditable | `implemented` |

### Staff — families

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Create family | `POST /api/v1/admin/households/{household}/families` | bearer | `household.manage` | role scope | `{label?}` | `201` family | several per household is expected, not exceptional | `implemented` |
| Add family member | `POST /api/v1/admin/families/{family}/members` | bearer | `household.manage` | role scope | `{resident_id,effective_from?}` | family | requires a current membership of that family's household (`409` otherwise); one family per resident at a time | `implemented` |
| Remove family member | `DELETE /api/v1/admin/families/{family}/members/{resident}` | bearer | `household.manage` | role scope | `{end_reason}` | family | effective-dated close, never a delete | `implemented` |
| Name the family head | `POST /api/v1/admin/families/{family}/head` | bearer | `household.manage` | role scope | `{resident_id\|null}` | family | nominee must be a current member of the family | `implemented` |

### Staff — kinship

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Relationships | `GET /api/v1/admin/residents/{resident}/relationships` | bearer | `resident.view` | role scope | `?include_ended=` | stored rows **and derived inverses**, each flagged `derived` | internal primary keys never cross the boundary | `implemented` |
| Record | `POST /api/v1/admin/residents/{resident}/relationships` | bearer | `household.manage` | role scope | `{related_resident_id,type,note?,effective_from?}` | `201` relationship | self-relations rejected (`400`); an existing tie **or its inverse** rejected (`409`) — one directed row per fact | `implemented` |
| End | `DELETE /api/v1/admin/residents/{resident}/relationships/{relationship}` | bearer | `household.manage` | role scope | `{end_reason}` | ended relationship | sets `effective_to`; **never deletes** — a separation and "this never happened" are different claims | `implemented` |

Relationship types: `parent`/`child`, `guardian`/`ward`, `dependent`/`provider`, and the
symmetric `spouse`, `partner`, `sibling`, plus `other`. Only one direction is stored; the
opposite view is computed, which is also what makes the duplicate check possible.

---

## 11f. Vulnerability profiles — built in TAB 10

Factors are **time-bounded observations**, not labels: what was seen, by whom, how, when, and
whether anyone has since confirmed or refuted it. The score built from them is **decision
support that decides nothing** — it orders a queue, and no status, tier, eligibility or
approval anywhere in this backend reads it. Full reasoning: ADR 0015.

Every snapshot carries the arithmetic that produced it — each factor with its weight,
severity, multiplier and points, plus the ruleset version — because a total on its own cannot
be explained to the resident it is about.

**Safeguarding factors** (`vawc-survivor`, `cicl`, `child-at-risk`, `trafficking-survivor`)
carry weight 0 and require `vulnerability.view_protected`. Without that permission the
response is **identical to that of a resident who has none** — no count, no placeholder — the
factor is absent from the list, contributes nothing to the score, is missing from the
published catalog, and a guessed factor id returns `404`, never `403`.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Published ruleset | `GET /api/v1/admin/vulnerability/ruleset` | bearer | `vulnerability.view` | — | — | weights, multipliers, bands, factor catalog, `decision_support_only`, `status` | catalog **filtered** — safeguarding categories are absent without `vulnerability.view_protected`, or the catalog itself becomes the disclosure | `implemented` |
| Resident snapshot | `GET /api/v1/admin/residents/{resident}/vulnerability` | bearer | `vulnerability.view` | role scope | — | `score`, `band`, `ruleset`, `contributions[]`, `uncapped_subtotal`, `dependant_count`, factors by level | **audited read**; combines the resident's own factors, their current household's factors, and dependants derived from kinship — never re-tagged | `implemented` |
| Record a factor | `POST /api/v1/admin/residents/{resident}/vulnerability-factors` | bearer | `vulnerability.manage` | role scope | `{factor_code,status?,severity?,source?,note?,effective_from?}` | `201` factor | idempotent per open factor; a household-level code is refused (`400`); a **safeguarding code additionally requires `vulnerability.view_protected`** | `implemented` |
| Confirm / refute | `POST /api/v1/admin/residents/{resident}/vulnerability-factors/{factor}/review` | bearer | `vulnerability.manage` | role scope | `{status,note?}` | factor | a refuted factor is **kept and stops counting** — deleting it means the claim gets re-raised forever | `implemented` |
| End a factor | `DELETE /api/v1/admin/residents/{resident}/vulnerability-factors/{factor}` | bearer | `vulnerability.manage` | role scope | `{end_reason}` | closed factor | effective-dated close, never a delete — "pregnant" is not permanent | `implemented` |
| Household snapshot | `GET /api/v1/admin/households/{household}/vulnerability` | bearer | `vulnerability.view` | role scope | — | same shape, household factors only | audited read | `implemented` |
| Record household factor | `POST /api/v1/admin/households/{household}/vulnerability-factors` | bearer | `vulnerability.manage` | role scope | as above | `201` factor | a resident-level code is refused (`400`) | `implemented` |
| End household factor | `DELETE /api/v1/admin/households/{household}/vulnerability-factors/{factor}` | bearer | `vulnerability.manage` | role scope | `{end_reason}` | closed factor | — | `implemented` |
| Citizen vulnerability view | — | — | — | — | — | — | — | `mock-only` |

`me/vulnerability` is a **deliberate non-endpoint**, on the same footing as `signInAs` in §2.
A resident's right to see their own data (RA 10173 §16(c)) is served by the data-access
request workflow in TAB 29, where a person asks and a human answers with context. A live score
endpoint would invite gaming, present a placeholder ordering as a verdict about someone's
life, and — for a protected individual whose device is monitored by the person they are being
protected from — hand a disclosure channel straight to the abuser. It must not be implemented
without a new ADR.

The ruleset weights are **placeholders awaiting MSWDO validation**, and say so in their own
payload (`status: placeholder-pending-lgu-approval`). The master command forbids hardcoding
Taytay policy that has not been supplied; see gap **G-20**.

---

## 11g. Welfare case engine — built in TAB 11

The lifecycle ADR 0007 specified, now real: **13 canonical states, one transition endpoint**,
and a citizen projection computed server-side. TAB 01 §E's 14-term vocabulary is a paraphrase
of the same lifecycle — ADR 0016 §1 carries the mapping, including why *Assigned* is an
assignment and *Archived* is a flag rather than states.

The staff paths carry `/admin` like every other staff surface here; §5 documents them
unprefixed because that is what the Angular console calls today. Same deviation class as
residents and households — gap **G-19**.

### Citizen — tracking your own request

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My requests | `GET /api/v1/me/cases` | bearer | — | own-record | `?page=&per_page=` | reference, projected `status`, `status_message`, timestamps | resolved from the token — no identifier in the contract to tamper with | `implemented` |
| Request detail | `GET /api/v1/me/cases/{case}` | bearer | — | own-record | — | adds `available_actions[]` and the applicant timeline | **additive projection** — internal `reason`, staff identities, assignment, priority, `needs_home_visit`, `is_escalated` and barangay are absent by construction, not removed | `implemented` |
| Withdraw | `POST /api/v1/me/cases/{case}/cancel` | bearer | — | own-record | `{reason}` | cancelled request | ownership **and** state re-checked server-side; `available_actions` is what a client renders, never what authorises | `implemented` |

`assessment` and `endorsed` both project to `under-review`: which desk holds the file would
let an applicant infer the handling social worker. Timeline entries appear only where the
event was written with a `citizen_message` — a mis-flagged event falls back to nothing rather
than to the operator summary.

### Staff — the queue and the case file

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Case queue | `GET /api/v1/admin/cases` | bearer | `request.view` | role scope | `?status=&type=&open_only=&assigned_to=me&unassigned=&page=&per_page=` | paginated summaries | **protective cases excluded from rows and from the total** without `request.view-sensitive`; `assigned-cases` scope narrows to the caller's own | `implemented` |
| Case file | `GET /api/v1/admin/cases/{case}` | bearer | `request.view` | role scope | — | operational fields + `available_transitions[]` | **audited read**; deliberately carries **no** vulnerability snapshot (ADR 0016 §4) | `implemented` |
| Open a case | `POST /api/v1/admin/cases` | bearer | `request.create` | role scope | `{resident_id,type,household_id?,program_id?}` | `201` case | a `protective` type additionally requires `request.view-sensitive` — opening one is itself a protection decision | `implemented` |
| Lifecycle transition | `POST /api/v1/admin/cases/{case}/transitions` | bearer | **resolved from the target state** | role scope | `{to,reason?,applicant_message?}` | case | legality checked **before** permission (`409` beats `403`), or the error maps the authorization table; `reason` mandatory for rejected/cancelled/returned/completed/expired | `implemented` |
| Priority | `POST /api/v1/admin/cases/{case}/priority` | bearer | `request.assign` | role scope | `{priority,reason?}` | case | `urgent` requires a reason; **never derived from a vulnerability score** | `implemented` |
| Assign | `POST /api/v1/admin/cases/{case}/assignment` | bearer | `request.assign` | role scope | `{assignee_subject_id,team?}` | case | idempotent; a closed case cannot be assigned; routing is assignment, never state | `implemented` |
| Return to queue | `DELETE /api/v1/admin/cases/{case}/assignment` | bearer | `request.assign` | role scope | `{reason}` | case | an unassigned open case is the backlog, a first-class state | `implemented` |
| Archive | `POST /api/v1/admin/cases/{case}/archive` | bearer | `request.close` | role scope | — | case | only a terminal case; a flag, not a status | `implemented` |
| History | `GET /api/v1/admin/cases/{case}/history` | bearer | `request.view` | role scope | — | transitions + assignments + timeline | audited read; internal `reason` visible here and nowhere a citizen can reach | `implemented` |

**Permission by target state** (the table ADR 0007 §2 required, now enforced):

| Target | Permission |
| --- | --- |
| `submitted` | `request.create` |
| `intake-review`, `returned` | `request.intake` |
| `assessment` | `request.assess` |
| `endorsed` | `request.endorse` |
| `approved` | `request.approve` |
| `rejected` | `request.reject` |
| `scheduled` | `request.schedule` |
| `released` | `request.release` |
| `completed`, `expired` | `request.close` |
| `cancelled` | none — ownership (applicant) or `request.close` (staff) |

**Separation of duties, enforced per case and actor:** the person who endorsed a case may not
approve it. `lgu_staff` holds intake/assess/endorse and not approve; `lgu_admin` holds
approve/reject/schedule/close and not endorse. Neither holds `request.release` — TAB 18 must
grant it to a role that does not approve, asserted by
`WelfareCaseTest::no_role_holds_both_approval_and_release`.

---

## 11h. Assistance intake and assessment — built in TAB 12

One submission path for every channel. A walk-in, a barangay referral, a web form and a retried
mobile submission all reach the same service and produce the same case — the channel is
recorded as provenance and changes no rule (ADR 0017 §1). A citizen client cannot assert its
own source; the server derives it from the channel.

**Submission is idempotent.** `idempotency_keys` had no caller until this TAB; it now backs
both citizen submission and counter intake. Same key and body replays the stored response
verbatim, status included; a different body is `409`; an in-flight duplicate is `409`.

### Citizen — drafts and submission

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My drafts | `GET /api/v1/me/assistance/drafts` | bearer | — | own-record | — | open drafts + `expires_at`, `is_editable` | ownership is in the query — another caller's draft resolves to `404`, never `403` | `implemented` |
| Start / resume | `POST /api/v1/me/assistance/drafts` | bearer | — | own-record | intake fields | `201` draft | **idempotent by owner and channel** — a second tap resumes the same form; two open drafts are two half-finished stories about one need | `implemented` |
| Save progress | `PATCH /api/v1/me/assistance/drafts/{draft}` | bearer | — | own-record | partial fields | draft | an **expired** draft is refused (`409`), never silently resurrected | `implemented` |
| Discard | `DELETE /api/v1/me/assistance/drafts/{draft}` | bearer | — | own-record | — | `{status}` | a **real delete** — nobody acted on it and no decision rests on it; keeping it would retain data whose only justification was a request never made | `implemented` |
| Submit | `POST /api/v1/me/assistance/drafts/{draft}/submit` | bearer | — | own-record | `Idempotency-Key` header | `201` case reference + projected status | requires a privacy-notice acknowledgement (`422` without); an already-submitted draft answers `200` with its case rather than an error | `implemented` |

### Staff — counter intake and assessment

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Counter intake | `POST /api/v1/admin/assistance-intakes` | bearer | `request.create` | role scope | intake payload + `source` + `Idempotency-Key` | `201` intake + case | staff may assert `walk-in`, `barangay-referral` or `legacy-import`; never a citizen source | `implemented` |
| Assessment templates | `GET /api/v1/admin/assessment-templates` | bearer | `request.assess` | — | — | templates with versions and questions | placeholders pending MSWDO approval — gap **G-21** | `implemented` |
| Intake + assessment | `GET /api/v1/admin/cases/{case}/assessment` | bearer | `request.view` | role scope | — | intake detail + current assessment | the applicant's narrative is staff-facing and behind the audited case file | `implemented` |
| Open assessment | `POST /api/v1/admin/cases/{case}/assessment` | bearer | `request.assess` | role scope | `{template_code}` | `201` assessment | **pins `template_version` at open**; idempotent — a second call returns the one in progress | `implemented` |
| Record answers | `PATCH /api/v1/admin/cases/{case}/assessment` | bearer | `request.assess` | role scope | `{answers:{code:value}}` | assessment | choice answers validated against the pinned template; a **completed** assessment cannot be edited | `implemented` |
| Sign findings | `POST /api/v1/admin/cases/{case}/assessment/complete` | bearer | `request.assess` | role scope | `{recommendation,reason?,findings?}` | assessment + `suggested_next_status` | **moves nothing** — see below; required answers must be present; `recommend-deny` requires a reason | `implemented` |
| Prior cases | `GET /api/v1/admin/cases/{case}/prior-cases` | bearer | `request.assess` | role scope | — | identity, category, status, dates | **no narratives, no assessments, no amounts** — knowing somebody has come three times is a different question from reading what they said | `implemented` |

**A recommendation is not a decision.** Completing an assessment returns a *suggested* next
status and moves nothing. Acting on it goes through `POST .../transitions`, needs that target's
permission, and leaves the approver bound by separation of duties — the endorser may not
approve. `recommend-deny` deliberately suggests nothing at all: a refusal is a decision with
its own permission and its own mandatory reason.

The assessment templates carry **no weights, thresholds or totals**. A form that computed an
eligibility number would be the automatic decision the master command permits only behind an
explicit LGU-approved deterministic rule — none has been supplied. The vulnerability score
(gap **G-20**) is likewise read by nothing here.

---

## 11i. Programmes and eligibility guidance — built in TAB 13

Programmes are **rows, not config** — unlike the vulnerability ruleset and the assessment forms.
An MSWDO officer opens a relief programme on Tuesday because a storm landed on Monday, and a
config deploy is the wrong instrument for that. Full reasoning: ADR 0018.

**Publication and visibility are separate columns.** An internal referral programme can be
published and operational while invisible to the public catalogue; both are filtered at the
query, so an unannounced programme is absent from the rows *and* the pagination total.

### Public

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Programme catalogue | `GET /api/v1/programs` | public | — | — | `?page=&per_page=` | published + citizen-visible programmes | a caller holding `program.view` gets drafts through the same endpoint — the URL confers nothing (ADR 0002) | `implemented` |
| Programme detail | `GET /api/v1/programs/{program}` | public | — | — | — | requirements, instructions, conditions **in words** | **no comparators, thresholds or blocking flags** — publishing the numbers turns a programme into a form to be gamed; unpublished ids return `404`, never `403` | `implemented` |

### Staff — authoring

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Create | `POST /api/v1/admin/programs` | bearer | `program.manage` | — | programme payload | `201` programme | always starts draft and invisible — publishing and exposing are two deliberate acts | `implemented` |
| Update | `PATCH /api/v1/admin/programs/{program}` | bearer | `program.manage` | — | partial payload | programme | — | `implemented` |
| Publish / retire | `POST /api/v1/admin/programs/{program}/status` | bearer | `program.manage` | — | `{status}` | programme | **refuses to publish with no requirements** (`409`) — a programme asking for nothing sends people to a counter to be told what to bring | `implemented` |
| Add requirement | `POST /api/v1/admin/programs/{program}/requirements` | bearer | `program.manage` | — | `{code,label,obligation,citizen_instructions,accepted_documents[]}` | `201` requirement | instructions are **mandatory**; versioned per programme | `implemented` |
| Add criterion | `POST /api/v1/admin/programs/{program}/eligibility-criteria` | bearer | `program.manage` | — | `{code,fact,comparator,value,citizen_explanation,is_blocking?}` | `201` criterion | `citizen_explanation` **mandatory**; `fact` is a closed set that excludes the vulnerability score; unsupported comparators are refused | `implemented` |
| New guidance version | `POST /api/v1/admin/programs/{program}/guidance-versions` | bearer | `program.manage` | — | `{version}` | programme | **copies criteria forward** — editing in place would rewrite the rules a past decision was made against | `implemented` |

### Staff — guidance against a case

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Run a check | `POST /api/v1/admin/cases/{case}/eligibility-checks` | bearer | `request.assess` | role scope | `{program_id}` | `201` check + per-criterion results | **advisory** (`is_advisory: true` in the payload); pins `guidance_version`; **moves nothing** | `implemented` |
| Check history | `GET /api/v1/admin/cases/{case}/eligibility-checks` | bearer | `request.view` | role scope | — | every check ever run | append-only; the audit record behind a decision | `implemented` |
| Citizen eligibility view | — | — | — | — | — | — | — | `mock-only` |

**Guidance flags; it never decides.** Four structural controls, each enforced where a change
would have to notice:

* the verdict vocabulary has **no `ineligible`** — only `likely-eligible`, `likely-ineligible`
  and `needs-review`;
* every criterion carries a **mandatory** `citizen_explanation` — a rule nobody can explain to
  the person it excludes *is* the opaque denial;
* there is **no score, threshold or auto-deny column** anywhere in the schema;
* the fact vocabulary is a short closed set that **excludes the vulnerability score** (gap
  **G-20**) and admits no rule-expression language.

**Absence is `unknown`, never `not-met`**, and any unknown sends the check to `needs-review` —
outranking even a clear blocking mismatch, because the unknown may be what explains it. A
missing income figure means nobody has asked, not that the applicant earns too much.

`me/eligibility` is a **deliberate non-endpoint**, on the same footing as the citizen
vulnerability view in §11f: "you are likely ineligible" reads as a refusal however it is worded,
and nobody has decided anything.

---

## 11j. Beneficiary enrolment and assistance history — built in TAB 14

**A beneficiary is a canonical resident on a roll — there is no `beneficiaries` table.** Resident,
applicant, beneficiary and enrollee are four *roles* of one person, not four records: the
applicant is an account (`assistance_intakes.submitted_by`, because a daughter may apply for her
mother), the beneficiary is `program_enrollments.resident_id`, and an enrollee is the same row
with `household_id` set. Full reasoning: ADR 0019.

### Citizen

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Assistance received | `GET /api/v1/me/assistance-history` | bearer | — | own record | — | programme reference, type, date, outcome | resident resolved **from the token** — there is no identifier in the contract to tamper with; additively projected, so no case worker, reason, assessment, barangay, priority or programme id | `implemented` |

**In-flight cases are absent by design.** Those are tracked through `me/cases`, whose vocabulary
is built for it. Listing an open case under "assistance received" tells somebody they have been
given what they have not.

### Staff — programme rolls

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Roll listing | `GET /api/v1/admin/enrollments` | bearer | `enrollment.view` | via source case | `?program_id=&status=&resident_id=&from=&to=&as_of=` | paginated enrolments | `as_of` answers "who was on this roll when the October tranche went out" from the effective dates rather than today's status | `implemented` |
| Enrol | `POST /api/v1/admin/enrollments` | bearer | `enrollment.manage` | beneficiary's barangay | `{program_id,resident_id,household_id?,source_case_id?,effective_from?,entry_reason?}` | `201` enrolment | **idempotent** — an existing open enrolment is returned, not a second opened; a retired programme is `409`; an out-of-scope beneficiary is `404` | `implemented` |
| Suspend / reactivate | `POST /api/v1/admin/enrollments/{enrollment}/status` | bearer | `enrollment.manage` | via beneficiary | `{status,note?}` | enrolment | `active`/`suspended` only; an ended enrolment is `409` — reviving it would rewrite a period they were genuinely off the roll | `implemented` |
| Exit | `POST /api/v1/admin/enrollments/{enrollment}/exit` | bearer | `enrollment.manage` | via beneficiary | `{exit_reason,effective_to?}` | enrolment | **`exit_reason` mandatory**; closes the period, never deletes the row | `implemented` |
| Beneficiary history | `GET /api/v1/admin/residents/{resident}/assistance-history` | bearer | `enrollment.view` | resident's barangay | — | granted cases + every enrolment held | includes exited enrolments — the only way to see somebody was removed, when and by whom | `implemented` |

**Two permissions, split at the money.** `enrollment.view` goes to front-line staff, who answer
"am I enrolled?" at the counter; `enrollment.manage` is `lgu_admin` only, because putting a name
on a roll is money-adjacent.

**Scope runs through the source case**, since an enrolment has no barangay of its own and
denormalising the beneficiary's would be a second copy that stops moving when they do. An
enrolment with **no** source case — a bulk or legacy import — is visible only to an unrestricted
actor: it carries no barangay evidence, and guessing one would be worse than admitting there is
none.

**At most one *open* enrolment per programme per resident**, enforced twice: the service returns
the existing row so a double-tap is harmless, and a unique index refuses a second open row so a
write path added later cannot create one either. Two open enrolments is one person counted twice
on every payment run.

**Money is not here.** `released_amount_centavos` is present and `null` — TAB 18's release ledger
is the authority, and this shape is built for it to join onto rather than replace.

**Enrolment reads no score, guidance outcome or recommendation.** Guidance advises (§11i), an
assessment recommends (§11h), a case is approved by somebody who did not endorse it (§11g), and
only then does a human enrol. Gap **G-20** stays non-consequential: there is no path from
`config/vulnerability.php` to a roll.

---

## 11k. Files, documents and verification — built in TAB 15

**The `Files` module publishes no routes.** It cannot answer *may this caller see this document* —
only the module owning the record can, and here that is the case's barangay scope. So every file
operation in the system enters through a controller that has already resolved a case. Full
reasoning: ADR 0020.

**A replacement is an append.** There is no `replaceDocument` and no `deleteDocument`, and there
must not be: the superseded version is the evidence of what the office actually saw when it
decided, and a request approved in March on a certificate replaced in June has to stay explicable
in December. Guarded by `DocumentHistoryIsAppendOnlyTest`.

### Staff — requirements and documents

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Requirement checklist | `GET /api/v1/admin/cases/{case}/requirements` | bearer | `request.view` | case barangay | — | slots, satisfaction, outstanding count, upload limits | `accepts` publishes the same limits the server enforces, so a client's copy cannot drift | `implemented` |
| Attach a programme's template | `POST /api/v1/admin/cases/{case}/requirements` | bearer | `document.manage` | case barangay | `{program_id}` | `201` requirements | **copied, not referenced** — a case stays explicable against the list in force when it opened | `implemented` |
| Record a document | `POST /api/v1/admin/cases/{case}/requirements/{requirement}/documents` | bearer | `document.manage` | case barangay | multipart `{source,file?,document_number?,issued_on?,expires_on?,expiry_unknown?,replaces_because?}` | `201` version | type read from the file's **own bytes**; `415` on a mismatch, `413` over 10 MiB; a replacement **requires a reason** (`422`) | `implemented` |
| Version history | `GET /api/v1/admin/cases/{case}/requirements/{requirement}/documents` | bearer | `request.view` | case barangay | — | every version ever presented | superseded versions included, with reason and their own file — that is the point | `implemented` |
| Accept / refuse | `POST /api/v1/admin/cases/{case}/requirements/{requirement}/verification` | bearer | `document.verify` | case barangay | `{status,note?}` | version | **not `document.manage`** — the clerk who took the paper is not the one who judged it; a rejection **must** say why (`422`); a superseded version is `409` | `implemented` |
| Rule a conditional requirement | `POST /api/v1/admin/cases/{case}/requirements/{requirement}/applicability` | bearer | `document.verify` | case barangay | `{applicability,reason}` | requirement | reason **mandatory in both directions** — ruling a document out is the step that can waive a safeguard | `implemented` |
| Open a document | `POST /api/v1/admin/cases/{case}/requirements/{requirement}/documents/{version}/access` | bearer | `request.view` (+`document.view.sensitive`, +`document.share`) | case barangay | `{for_sharing?}` | single-use handle + expiry | version must sit in **this** requirement's slot, else `404`; sharing additionally needs a permission **nobody holds yet** | `implemented` |
| Download | `GET /api/v1/documents/{handle}` | bearer | — (the grant *is* the decision) | issued-to only | — | the bytes | single-use, 120s, bound to the account; `no-store`, `nosniff`, `DENY`, attachment | `implemented` |

### Staff — asking the applicant for something

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Requests on a case | `GET /api/v1/admin/cases/{case}/document-requests` | bearer | `request.view` | case barangay | — | open first, then most recent | `is_applicant_overdue` — named for its subject, because a case task is late when *staff* are | `implemented` |
| Ask for a document | `POST /api/v1/admin/cases/{case}/requirements/{requirement}/document-requests` | bearer | `document.manage` | case barangay | `{channel,message,needed_by?}` | `201` request | `message` **mandatory** — a record that something was asked for without saying what looks like follow-up the office cannot show; a past `needed_by` is `422` | `implemented` |
| No longer needed | `POST /api/v1/admin/cases/{case}/document-requests/{documentRequest}/withdraw` | bearer | `document.manage` | case barangay | `{reason}` | request | withdrawn, never deleted — an applicant who was chasing a document deserves the record that released them | `implemented` |

**A document arriving closes the request that asked for it**, automatically. A clerk who has just
recorded the certificate should not also have to tick off the request, and a request left open
after it was answered is what stops an overdue queue being believed.

### Citizen

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| What my case needs | `GET /api/v1/me/cases/{case}/requirements` | bearer | — | own case | — | slots, what the office asked for, upload limits | additively projected — no reviewer, no internal note, no scan status, no applicability reason | `implemented` |
| Supply a document | `POST /api/v1/me/cases/{case}/requirements/{requirement}/documents` | bearer | — | own case | multipart `{file}` | `201` version | source forced to `uploaded` **from the route** — an applicant cannot claim a clerk saw the paper; lands `pending` | `implemented` |
| Open what I supplied | `POST /api/v1/me/cases/{case}/requirements/{requirement}/documents/{version}/access` | bearer | — | own case | — | single-use handle | never `for_sharing` — an outward copy is the office's decision to make and record | `implemented` |

Ownership is part of every lookup: resident from the token, case from that resident, requirement
from that case. Another applicant's case id resolves to **`404`**, never `403`.

### Four things this contract does deliberately

* **The document number is masked before storage, not before display.** Only the last four
  characters are ever written, and only where the source holds no file — where there is a file,
  the image is the record. The full number is therefore absent from every backup, replica, dump
  and query log, and no future endpoint can leak what was never kept. The console currently
  receives a full `documentNumber` and masks it in the view (gap **G-24**); this backend does not
  send one.
* **`pending` is not `clean`.** An unscanned file is served to staff, who already carry the risk
  of the upload they accepted, and refused for any outward share, which would pass that risk to
  somebody else. A scanner is configuration, not code (gap **G-25**).
* **A grant, not a signed URL.** A signature is valid wherever it is pasted, to whoever holds it,
  and records nothing. Article 5.4 requires every read of another person's personal data to be
  auditable, and object storage never tells the application a fetch happened.
* **`no-expiry` and `unknown` are different facts.** A birth certificate never expires; a
  certificate whose expiry nobody wrote down might have lapsed. Only the second is somebody's
  unfinished work, and an unknown expiry never blocks an applicant for the office's omission.

---

## 11l. Referrals and the service provider directory — built in TAB 16

**A referral is the one record that leaves the building.** Every other endpoint here can be
tightened later; once a referral sheet is out, this office no longer controls who reads it and
nothing can be taken back. That shapes the whole surface. Full reasoning: ADR 0021.

### Staff — the directory

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Directory | `GET /api/v1/admin/service-providers` | bearer | `referral.view` | — | `?search=&destination_type=&status=` | paginated providers | **staff-only** — a public list of offices welfare clients are sent to is a map of where vulnerable people go, and invites impersonation of exactly the offices families are told to trust | `implemented` |
| Detail | `GET /api/v1/admin/service-providers/{provider}` | bearer | `referral.view` | — | — | provider + `problems[]` | `problems` is surfaced so the console can say *why* an entry cannot be activated | `implemented` |
| Add | `POST /api/v1/admin/service-providers` | bearer | `provider.manage` | — | `{name,destination_type,services_offered[],channels[],contact…}` | `201` provider | — | `implemented` |
| Edit | `PATCH /api/v1/admin/service-providers/{provider}` | bearer | `provider.manage` | — | partial | provider | editing contact details silently redirects every referral that follows, so it is audited | `implemented` |
| Activate / suspend / retire | `POST /api/v1/admin/service-providers/{provider}/status` | bearer | `provider.manage` | — | `{status}` | provider | **refuses to activate an unusable entry** (`409`) — "accepting referrals" with no channel and no contact produces referrals nobody can follow up | `implemented` |
| Re-check | `POST /api/v1/admin/service-providers/{provider}/verification` | bearer | `provider.manage` | — | — | provider | a directory nobody re-checks is a list of disconnected numbers within two years, and the failure is silent | `implemented` |

### Staff — referrals

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Queue | `GET /api/v1/admin/referrals` | bearer | `referral.view` | via case | `?status=&urgency=&destination_type=&resident_id=&overdue_only=&open_only=` | paginated referrals | ordered overdue → urgent → oldest, the order a queue is actually worked in | `implemented` |
| Detail | `GET /api/v1/admin/referrals/{referral}` | bearer | `referral.view` | via case | — | referral + disclosure record + notes + `blockers[]` | the disclosure record *is* the audit trail: every field released, with its reason | `implemented` |
| Draft | `POST /api/v1/admin/referrals` | bearer | `referral.manage` | client's barangay | `{resident_id,case_id?,provider_id?,destination_name?,urgency?,service_requested,reason}` | `201` referral | **`resident_id` is mandatory**; a case is optional but must belong to the same client (`409`); destination is **snapshotted**, never read through | `implemented` |
| Edit | `PATCH /api/v1/admin/referrals/{referral}` | bearer | `referral.manage` | via case | partial | referral | `409` once sent — corrections afterwards are notes | `implemented` |
| Record the lawful basis | `POST /api/v1/admin/referrals/{referral}/authority` | bearer | `referral.manage` | via case | `{basis,note}` | referral | RA 10173. Note **mandatory**, and each basis needs a different fact — a vital-interest referral noting "client agreed" contradicts its own basis | `implemented` |
| Release a field | `POST /api/v1/admin/referrals/{referral}/shared-fields` | bearer | `referral.manage` (+`referral.disclose.protected`) | via case | `{field,because}` | the plan | `because` **mandatory**; address / sector membership / assistance history need the second permission | `implemented` |
| Withhold a field | `DELETE /api/v1/admin/referrals/{referral}/shared-fields/{field}` | bearer | `referral.manage` | via case | — | the plan | withheld means **absent from the sheet**, never "withheld" printed on it | `implemented` |
| Attach a document | `POST /api/v1/admin/referrals/{referral}/attachments` | bearer | `referral.manage` **+ `document.share`** | via case | `{document_id,label,because}` | the plan | the **same** permission as any outward share (ADR 0020 §7), which nobody holds — so this is refused today, deliberately (gap G-26). `sensitive` files are refused outright | `implemented` |
| Detach | `DELETE /api/v1/admin/referrals/{referral}/attachments/{document}` | bearer | `referral.manage` | via case | — | the plan | — | `implemented` |
| The sheet | `GET /api/v1/admin/referrals/{referral}/summary` | bearer | `referral.view` | via case | — | lines, attachments, authority statement, handling notice | **producing one is audited** — a printed sheet exists whether or not it is sent | `implemented` |
| Send | `POST /api/v1/admin/referrals/{referral}/send` | bearer | **`referral.send`** | via case | — | referral | the one irreversible act, and its own permission; `422` with `blockers[]` when the disclosure record is incomplete | `implemented` |
| Record what they reported | `POST /api/v1/admin/referrals/{referral}/status` | bearer | `referral.manage` | via case | `{status,outcome?}` | referral | nothing past `sent` is inferred from elapsed time; `declined`/`served`/`closed` **require** an outcome | `implemented` |
| Note | `POST /api/v1/admin/referrals/{referral}/notes` | bearer | `referral.manage` | via case | `{audience,body}` | `201` note | `internal` vs `receiving-office` is a **column**, not a flag — a flag is what gets forgotten on export day | `implemented` |

### Citizen

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My referrals | `GET /api/v1/me/referrals` | bearer | — | own record | — | reference, office name, status, fixed message, date | the narrowest citizen projection here: **no reason, no notes, no contact, no outcome, no urgency** | `implemented` |

**The minimum is the default.** A referral sheet carries the client's name, the reference number
and the reason. Everything else is opt-in, one field at a time, each with a stated need, each its
own row — because *"which referrals released a home address"* is the first question asked after a
protection incident, and a JSON blob cannot answer it.

**Overdue is derived, never stored.** One query serves both the staff filter and the nightly
sweep, so they cannot disagree. The sweep writes nothing and raises `ReferralBecameOverdue`, which
TAB 19's tasks and TAB 20's notifications will listen for.

---

## 11m. Field visits, notes and safeguarding — built in TAB 17

**No coordinate exists anywhere in this contract.** No check-in, no arrival ping, no route, no
field to send one to. A visit records the address it was made to — which the household registry
already holds — and what happened there. `NoLocationTrackingTest` fails the build if a
position-shaped column or request key appears. Full reasoning: ADR 0022.

### Staff — field visits

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Calendar / my queue | `GET /api/v1/admin/visits` | bearer | `visit.view` | client's barangay | `?status=&purpose=&assigned_to=&resident_id=&from=&to=&overdue_only=` | paginated visits | **thin by design** — no observations, no outcome, and no safeguarding marker of any kind | `implemented` |
| Visit detail | `GET /api/v1/admin/visits/{visit}` | bearer | `visit.view` | client's barangay | — | checklist, observations, outcome, follow-up, `worker_safety_advisory` | the advisory is **one sentence, detail view only** — no category, no count, no history | `implemented` |
| Schedule | `POST /api/v1/admin/visits` | bearer | `visit.manage` | client's barangay | `{resident_id,case_id?,purpose,assigned_to?,scheduled_for,scheduled_window?,checklist[]?}` | `201` visit | a past date is `422`; a case is optional but must belong to the same client; `scheduled_window` is free text ("morning") because that is how visits are really arranged | `implemented` |
| Record an observation | `POST /api/v1/admin/visits/{visit}/observations` | bearer | `visit.manage` | client's barangay | `{kind,body,attributed_to?}` | `201` observation | **`kind` is the point**: `observed` / `client-said` / `third-party-said` / `worker-assessed`. Third-party **must** name who; the others **must not** | `implemented` |
| Tick the checklist | `POST /api/v1/admin/visits/{visit}/checklist` | bearer | `visit.manage` | client's barangay | `{code,checked,note?}` | item | a prompt, never a score — nothing totals these and nothing derives a rating from them | `implemented` |
| Conclude | `POST /api/v1/admin/visits/{visit}/conclusion` | bearer | `visit.manage` | client's barangay | `{status,outcome?,service_needs?,declined_reason?,next_action?,follow_up_on?}` | visit | every outcome is **terminal**; `completed` requires an outcome; `not-found` / `refused` / `cancelled` are held apart | `implemented` |

**`not-found`, `refused` and `cancelled` are three different facts.** Collapsing them into
"unsuccessful" is how a family that was out at work acquires a reputation for being uncooperative.

**A next action with a date raises `VisitFollowUpDue`**, which TAB 19's tasks will listen for. The
event carries the *action*, never the observations.

### Staff — the running record

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Notes | `GET /api/v1/admin/cases/{case}/notes` | bearer | `request.view` | case barangay | — | notes + `withheld_count` + `has_safeguarding_concern` | a protected note's **existence, author, sensitivity and time are disclosed**; only `body` is null | `implemented` |
| Add a note | `POST /api/v1/admin/cases/{case}/notes` | bearer | `request.view` (+`case-note.view-protected` for the protected tier) | case barangay | `{body,sensitivity?}` | `201` note | writing into the protected tier needs the same clearance as reading it — otherwise a note can be put beyond *review* rather than beyond disclosure | `implemented` |
| Withdraw | `POST /api/v1/admin/cases/{case}/notes/{note}/withdrawal` | bearer | `request.view` | case barangay | `{reason}` | note | withdrawn, never deleted, and **only by its author** — a record of what one worker believed is not another's to retract | `implemented` |

**Why existence is disclosed even when the body is not:** a caseworker who cannot see that three
restricted entries exist reads the file as complete and acts as though nothing happened. Knowing a
record is there, and that it is not theirs to read, is what makes it possible to ask the right
person. The body is removed **by the application**, so a payload that never held the paragraph
cannot leak it.

### Staff — safeguarding

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| One resident's concerns | `GET /api/v1/admin/residents/{resident}/safeguarding` | bearer | `safeguarding.view` | resident's barangay | — | full concerns | the narrowest read in this system; the **category alone** — "child-protection" against a named family — is itself the disclosure | `implemented` |
| Raise | `POST /api/v1/admin/safeguarding-concerns` | bearer | `safeguarding.manage` | resident's barangay | `{resident_id,case_id?,category,detail,worker_safety_advisory?}` | `201` concern | audited by identifier only — the trail never repeats the category or the detail | `implemented` |
| Close | `POST /api/v1/admin/safeguarding-concerns/{concern}/closure` | bearer | `safeguarding.manage` | resident's barangay | `{reason}` | concern | reason **mandatory** — deciding a family no longer needs watching is as consequential as deciding they do | `implemented` |

**There is deliberately no list endpoint.** A queue of safeguarding concerns is a list of families
under suspicion, and once it exists it will be filtered, sorted, exported and eventually joined to
something. Every read is scoped to one named resident somebody already had reason to open, which
is what makes each read a decision rather than a browse.

**Three tiers of exposure, on purpose:** nothing in any list; existence on a case detail; a
one-sentence worker-safety advisory on a visit detail; the full record only under
`safeguarding.view`. A worker sent to a house is entitled to know there is a risk to *them*
without being told a family's protection history.

---

## 11n. Release and distribution tracking — built in TAB 18

**Operational tracking, not a treasury ledger.** No journal entries, no account codes, no bank
posting, no reconciliation state. `funding_source` is a label a social worker types so a report can
be grouped by it — nothing joins on it and nothing may start treating it as a posting reference.
Full reasoning: ADR 0023.

**Money is integer centavos plus an explicit `currency`.** The master command asks for
"fixed-precision decimal columns"; the constitution requires integer minor units. Both forbid
floating point and both are exact, the constitution outranks the task instruction, and every other
money field in this system and in the Angular client is already centavos — including
`released_amount_centavos`, which TAB 14 published as null precisely so this TAB could fill it
without a client change (ADR 0023 §1).

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Release queue | `GET /api/v1/admin/releases` | bearer | `request.view` | beneficiary's barangay | `?status=&kind=&release_mode=&resident_id=&program_id=&from=&to=` | paginated releases | — | `implemented` |
| Release detail | `GET /api/v1/admin/releases/{release}` | bearer | `request.view` | beneficiary's barangay | — | release + every transition | movements are an append-only table, because money is where "what happened" must be reconstructable without inference | `implemented` |
| Prepare | `POST /api/v1/admin/cases/{case}/releases` | bearer | `request.schedule` | case barangay | `{kind,amount_centavos?,in_kind_description?,release_mode,program_id?,funding_source?,scheduled_for?}` | `201` release | **only against an approved case** (`409`); cash needs an amount, in-kind must **not** have one; `sequence` assigned inside a lock | `implemented` |
| **Confirm handover** | `POST /api/v1/admin/releases/{release}/confirmation` | bearer | **`request.release`** | beneficiary's barangay | `Idempotency-Key` + `{acknowledged_by_name?,acknowledged_relationship?,acknowledgement_method?}` | release | **the one operation that moves money** — see the three controls below | `implemented` |
| Other outcomes | `POST /api/v1/admin/releases/{release}/status` | bearer | from the **target** state | beneficiary's barangay | `{status,reason?}` | release | `failed`/`deferred`/`cancelled` **require** a reason; a released record cannot be rewound (`409`) | `implemented` |
| Open a distribution run | `POST /api/v1/admin/release-batches` | bearer | `request.schedule` | — | `{name,scheduled_for,location?}` | `201` batch | — | `implemented` |
| Add to the run | `POST /api/v1/admin/release-batches/{batch}/releases` | bearer | `request.schedule` | beneficiary's barangay | `{release_id}` | release | only a `ready` release may be added | `implemented` |
| Manifest | `GET /api/v1/admin/release-batches/{batch}/manifest` | bearer | `request.view` | — | — | lines + `total_count` + `total_cash_centavos` | **ordered by reference, not name** — two copies printed an hour apart then match line for line; in-kind contributes nothing to the total | `implemented` |

### Three controls on the one operation that moves money

| Control | Guards | Why the others do not cover it |
| --- | --- | --- |
| `Idempotency-Key` | a retry over a weak connection | one client, one intent, two requests — a lock does not help |
| Row lock + status re-check in the transaction | two staff at two tables at one distribution | two clients, two keys, both see `ready`, both click |
| **Approver ≠ releaser**, checked on the *person* | deliberate misuse | neither of the above cares who is acting |

A payout table has a weak connection and a queue behind it. That is the normal operating
condition, not an edge case.

### Segregation of duties

A new **`disbursing_officer`** role holds `request.release` and approves nothing. Until this TAB
nobody held `request.release` at all — correct while there was nothing to release, and the reason
`lgu_admin` still holds approval and not release. Granting release to `lgu_admin` would have
collapsed the split this system has kept since TAB 11.

The check is on the **person**, not only the permission: `releases.approved_by` is snapshotted at
preparation and compared at confirmation, because one person holding two roles is the failure mode
and it arrives the moment somebody covers a colleague's leave.

### `Released` and `Completed` are different claims

`Released` means the office handed it over; `Completed` means the handover is acknowledged.
Between them sits the cheque given to a relative who has not confirmed and the transfer sent but
not landed. Collapsing them would make "we paid them" and "they have it" the same claim.

**No biometric is stored.** `acknowledgement_method` records *that* a signature or thumbmark was
taken; the mark stays on the paper manifest.

---

## 11o. Tasks and work queues — built in TAB 19

**A task is the answer to "what happens next", as a record rather than an inference.** A screen
deriving the next step from a case's status can only say what the *process* expects; a task says
what this office undertook to do, by when, and who owes it. Full reasoning: ADR 0024.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Queues | `GET /api/v1/tasks` | bearer | `task.view` | — | `?mine=1&overdue=1&due_today=1&upcoming=1&type=&priority=&status=&team=` | paginated tasks | `mine` resolves **from the token**, never a parameter — a queue filtered by an account id in the query string is one anybody can point at anybody | `implemented` |
| Task detail | `GET /api/v1/tasks/{task}` | bearer | `task.view` | — | — | task | carries a type, an opaque identifier and an instruction — **nothing about its subject** | `implemented` |
| Raise | `POST /api/v1/tasks` | bearer | `task.manage` | — | `{type,title,subject_type?,subject_id?,assigned_to?,team?,priority?,due_on?}` | `201` task | — | `implemented` |
| Close | `POST /api/v1/tasks/{task}/closure` | bearer | `task.manage` | — | `{status,outcome}` | task | **records an outcome and nothing else**; `done` and `cancelled` both **require** it | `implemented` |
| Reassign | `POST /api/v1/tasks/{task}/assignment` | bearer | `task.manage` | — | `{assigned_to?,team?}` | task | does not touch the subject | `implemented` |

### Linked-entity access is still policy checked — by design, not by a check

The master command puts it precisely: *team membership alone must not grant access to a linked
sensitive entity.* Held two ways, and the first matters more:

* **a task payload carries nothing worth reading** — no case title, no beneficiary name, no
  narrative. Seeing a task discloses nothing about its subject, so there is no per-row permission
  check to forget when somebody adds a field;
* **the subject is opened through its own module's endpoint**, which does its own check. A task
  holds a pointer, not a key.

A barangay-scoped clerk can see a task pointing at a case in another barangay and still gets `404`
opening it.

### Completing a task changes nothing else

Completing "close the case" does not close the case — it records that somebody says they did. The
service structurally cannot do otherwise: it imports no case, referral or release, so there is no
line to add one to. That is the acceptance criterion, and it is also the master command's
instruction to avoid hidden automation that changes case outcomes.

### The automation

Two listeners, on events published by earlier TABs with no listener at all — a seam built before
it is needed is a seam; built after, it is a refactor.

| Event | Published | Raises |
| --- | --- | --- |
| `ReferralBecameOverdue` | TAB 16 | `referral-follow-up`, assigned to whoever made the referral |
| `VisitFollowUpDue` | TAB 17 | `field-visit`, assigned to whose visit it was, titled with the **next action** and not the observations |

**One open automatic task per subject**, via a derived `automation_key` unique index. The sweep
runs nightly and a referral stays overdue until somebody chases it — without the key the queue
grows a fresh copy every morning, and within a fortnight it is fourteen identical rows and nobody
trusts it. The key is released on closure, so a problem that recurs raises a fresh task.

`raised_by_event` is projected so a worker can tell *the system noticed this* from *a colleague
asked me to do this*. They carry different weight, and hiding the difference trains people to
ignore the automatic ones. An automatic task is attributed to **nobody** — `ActorContext::system()`
carries a null subject rather than a fictitious account.

---

## 11p. Notifications and multi-channel delivery — built in TAB 20

**Firebase is transport, not authority** (Article 8.3). Laravel decides that a notification is
warranted, who may receive it and what it may say. Full reasoning: ADR 0025.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| My notifications | `GET /api/v1/me/notifications` | bearer | — | own record | `?unread=1` | paginated notifications | resolved from the token; another person's id is **`404`**, never `403` | `implemented` |
| Mark read | `POST /api/v1/me/notifications/{notification}/read` | bearer | — | own record | — | notification | idempotent — a second read does not move the timestamp | `implemented` |
| Mark all read | `POST /api/v1/me/notifications/read-all` | bearer | — | own record | — | `{marked}` | — | `implemented` |
| Preferences | `GET /api/v1/me/notification-preferences` | bearer | — | own record | — | preferences + `mandatory_notice` | the mandatory rule is **stated in the payload** so a client can explain the switch it is not offering | `implemented` |
| Set a preference | `PUT /api/v1/me/notification-preferences` | bearer | — | own record | `{notification_type,channel,enabled}` | preferences | `database` is **not** a settable channel (`422`) | `implemented` |

**Device registration lives in Identity, not here.** `me/devices` (§8) already registers a device
with its fingerprint, trust stamp, revocation and `push_token`. A second registration surface was
written for this TAB and removed: it silently shadowed Identity's routes, and two registries would
have drifted the moment a device was revoked in one and kept receiving push from the other
(Article 6). Notification reads active tokens through `DeviceService::activePushTokensFor()`.

### The rendered text and the push payload are different things

> *Your AICS assistance of ₱5,000 is ready for release at the barangay hall.*

Correct in the app, behind authentication. On a lock screen it is visible to anyone holding the
phone; on a shared handset, to the household; in transit, to the provider and its logs.

So a push payload carries **`{type, notification_id, subject_type, subject_id}`** and nothing else.
`FcmChannel` has no line that reads the body, it sends a **data-only** message (a `notification`
message would require a provider-rendered title and body), and `notification_dispatches` has **no
payload column**.

### Channels

`database` (always), `push` (FCM), `email` and `sms` (null until configured). An unconfigured
channel records **`skipped`, never `sent`** — a dashboard showing "delivered" for a channel that
does not exist tells an operator the family was told. A channel never throws for a provider-side
failure: the assistance was approved whether or not the text lands.

### Preferences

Opt-**out** — an absent row means "on", so a notification type added next year reaches people
rather than silently reaching nobody. **Mandatory notices ignore preferences**: a scheduled release
date must reach somebody who switched notifications off months earlier. **The in-app record can
never be switched off** — opting out of email means "stop emailing me", not "stop keeping a record
of what you told me".

### Not every transition is announced

Six statuses are: `approved`, `rejected`, `scheduled`, `released`, `completed`, `returned`. A case
moving between `assessment` and `endorsed` is paper moving between desks, and announcing it teaches
people the notifications are noise. The event carries the **projected citizen sentence**, never the
internal reason.

**The FCM transport is not wired** (gap G-33) — credentials are environment configuration. Every
acceptance criterion holds without it.

---

## 11q. Dashboard metrics, reports and exports — built in TAB 21

**Aggregate-first**, and **no employee performance rankings** — the two instructions the master
command gives in prose, and the two that shape this section more than the acceptance criteria do.
Full reasoning: ADR 0026.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | `GET /api/v1/admin/dashboard` | bearer | `report.view` | caller's barangays | `?from=&to=&barangay_id=&program_id=&status=&assigned_to=` | summary, aging, reach, utilization, referral outcomes, workload, completeness | **no names anywhere**; every metric scoped; cells below 5 suppressed | `implemented` |
| My exports | `GET /api/v1/admin/exports` | bearer | `report.view` | own requests | — | export records | scoped to the caller's own requests; **no storage key in the payload** | `implemented` |
| Request an export | `POST /api/v1/admin/exports` | bearer | **from the report** | caller's barangays | `{report,format?,filters?}` | `201` `queued` | aggregate costs `report.view`; person-level costs `report.export.person-level` | `implemented` |
| Download | `GET /api/v1/admin/exports/{export}/download` | bearer | re-checked at download | requester only | — | the file | unknown / another person's / expired / unfinished / no-longer-permitted all answer **`404`** | `implemented` |

### A small cell is a person

"3 households with a safeguarding concern in Barangay Dolores" is a statistic; **"1 household" is
an identification.** Counts below **5** report `null` with `suppressed: true`. The row is kept
rather than dropped — dropping it would say the barangay has zero, and a reader comparing two
filtered views could recover the number from the difference in totals. Money follows the count, so
a suppressed cell publishes no peso total either. The threshold is in the payload so a client can
label a blank cell honestly.

### Exports are copies that leave this system's control

Always **queued**, never inline — not a size threshold, which somebody eventually tunes wrong on
the day the data grows. The request records **what the asker was allowed to see at that moment**,
because permissions change and a March export by somebody who has since moved offices must still be
explicable. The job rebuilds scope from that snapshot. Download is **re-authorized** and scoped to
the requester. The file expires (24h person-level, 7d aggregate); **the row does not**, because the
record that an export happened is what an audit needs. Requesting and downloading are audited as
**separate** acts.

### No per-caseworker report

Workload is reported **by team**. There is no grouping by caseworker anywhere and no report
returning one row per worker — one `GROUP BY assigned_to` and the office has a leaderboard.

A caseworker's open-case count measures the cases they were *given*: the worker handed the hardest
families has the longest queue and the slowest closures, and a ranking presents that as
underperformance. **Filtering to one named worker is still allowed** — that is how somebody sees
their own queue — and is not the same thing.

### One person-level report

`release-manifest`, because a payout table needs a printed list of who is expected. It carries the
reference, beneficiary identifier, amount and schedule — and **not** the case narrative or the
eligibility reasoning. A manifest is a list of who is coming, not a summary of their circumstances.

---

## 11r. Search, filter grammar and saved views — built in TAB 22

**There is no search index**, and that is the design. Every searcher runs a scoped query against
the owning module's table, applying the same permission and barangay scope that module's detail
endpoint applies — so search results and detail endpoints cannot disagree. Full reasoning:
ADR 0027.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Global search | `GET /api/v1/admin/search` | bearer | `request.view` + per-entity | caller's barangays | `?q=` | typed result snippets | **never an object the caller could not open**; an unreadable entity is *absent*, not refused; a term under 2 characters returns nothing | `implemented` |
| Saved views | `GET /api/v1/admin/saved-views` | bearer | `request.view` | own + shared | — | views + **the published grammar** | a client builds its filter UI from the server's list rather than a copy that drifts | `implemented` |
| Save a view | `POST /api/v1/admin/saved-views` | bearer | `request.view` (+`saved-view.share`) | — | `{entity,name,filters?,columns?,sort?,is_shared?}` | `201` view | filters validated **on the way in**; sharing costs its own permission | `implemented` |
| Delete | `DELETE /api/v1/admin/saved-views/{view}` | bearer | `request.view` | owner only | — | `{deleted}` | a shared view is somebody else's to remove | `implemented` |

### What is never searched

Case note bodies, safeguarding detail, referral reasons, visit observations. *"Show me cases whose
notes mention 'shelter'"* is a disclosure performed by a search box — the protected tier defeated
without ever reading a note. The filter grammar enforces it from the other side: `body` and
`detail` are not filterable fields on any entity.

Result snippets carry a safe title, a barangay and a status. No birth date, no address, no
narrative — a snippet finds a record, it does not read one.

### The filter grammar

A filter is a `(field, operator, value)` triple where field and operator are **keys in a closed
table**. A field name that is not a key cannot become a column reference, which is the whole
injection defence. `in` is bounded at 100 values; a structured value on a scalar operator is
refused rather than cast; sorting uses the same table, so `sort=id;DROP TABLE` is a `422`.

**Validated when saved, not only when run** — a saved view is executed later by whoever loads it,
and a filter checked only at execution time is a stored query waiting for a code path that forgets.

### A saved view shares a question, never an answer

Two people opening the same shared view see different rows, because each query is scoped to
whoever runs it. A view carrying its author's scope would be a permission escalation dressed as a
convenience. The payload says so in words.

### There is no citizen search endpoint

Not a filtered one — none. A citizen's own records are reachable through `me/*`, which resolves the
resident from the token. A citizen search that "only returned their own records" would be a
resident-enumeration endpoint one authorization bug away, and the bug would be invisible because
the endpoint would still look like it was working. **The absence is the control.**

---

## 11s. Newsfeed publishing — built in TAB 23

**The only records in this system meant to be read by people who are not their subject.** That
inverts the usual risk: the danger is not disclosure but publishing early, or to the wrong
audience. Full reasoning: ADR 0028.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Published feed | `GET /api/v1/newsfeed` | bearer (or anonymous **only if enabled**) | — | reader's barangay | `?page=&per_page=` | published posts | a draft is **absent**, not filtered; anonymous is refused unless `NEWSFEED_PUBLIC` (gap G-36) | `implemented` |
| Read a post | `GET /api/v1/newsfeed/{post}` | as above | — | reader's barangay | — | the post | the lookup runs **against the public query**, so a guessed draft id is `404` with no status check after it | `implemented` |
| Staff detail | `GET /api/v1/admin/newsfeed/{post}` | bearer | `newsfeed.manage` | — | — | the post incl. status and schedule | the staff projection; a draft is readable here and nowhere else | `implemented` |
| Staff list | `GET /api/v1/admin/newsfeed` | bearer | `newsfeed.manage` | — | `?status=&category=&audience=&search=` | all posts incl. drafts | — | `implemented` |
| Draft | `POST /api/v1/admin/newsfeed` | bearer | `newsfeed.manage` | — | `{body,category,headline?,audience?,audience_barangay_id?,comments_enabled?}` | `201` post | a `barangay` audience needs a barangay (`422`) | `implemented` |
| Edit | `PATCH /api/v1/admin/newsfeed/{post}` | bearer | `newsfeed.manage` | — | partial | post | a **published** post can still be corrected; an archived one cannot (`409`) | `implemented` |
| Schedule / publish / archive | `POST /api/v1/admin/newsfeed/{post}/status` | bearer | **from the target state** | — | `{status,publish_at?}` | post | publishing and scheduling cost `newsfeed.publish`; a past `publish_at` is `422`; archived is terminal | `implemented` |
| Pin | `POST /api/v1/admin/newsfeed/{post}/pin` | bearer | `newsfeed.publish` | — | `{is_pinned}` | post | pinned sorts first | `implemented` |
| Attach media | `POST /api/v1/admin/newsfeed/{post}/media` | bearer | `newsfeed.manage` | — | `{file_id,alt_text?,is_decorative?,position?}` | post | **alt text required** unless explicitly decorative (`422`) | `implemented` |
| Metrics | `GET /api/v1/admin/newsfeed-metrics` | bearer | `newsfeed.manage` | — | — | counts by status | — | `implemented` |

### The public query is the boundary

Three conditions applied **at the query**: published, `publish_at` arrived, audience matches. A
`where uuid = ?` against that cannot return a draft, and no status check follows it — which is what
makes it survive the next endpoint somebody adds. Status and schedule are checked **together**: a
row whose status says published but whose time has not come is still embargoed.

### A scheduled post publishes at most once

A conditional `UPDATE ... WHERE status = 'scheduled' AND publish_at <= now`, which is atomic — two
workers racing produce one update and one no-op, because the second one's `WHERE` no longer
matches. Idempotent under replay, and a missed sweep is harmless.

### Two projections, two queries

The public projection is a **separate method**, not the staff one with fields removed. Absent by
construction: status, author, audience, schedule, transitions. Subtractive projection leaks the
first time somebody adds a field.

### Alt text

Required unless explicitly decorative, so "nobody wrote one" and "there is nothing to write" stay
distinguishable. A municipal announcement a blind resident cannot read is a service the LGU is not
providing to somebody entitled to it.

---

## 11t. Newsfeed engagement and moderation — built in TAB 24

**This is not a social network.** No follow graph, no profiles, no mentions. The LGU publishes;
residents respond to the LGU. Full reasoning: ADR 0029.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| React | `POST /api/v1/newsfeed/{post}/reaction` | bearer | — | own | `{reaction?}` | aggregate counts + `my_reaction` | **no field names a reactor**; one reaction per person per post, upserted; rate limited | `implemented` |
| Un-react | `DELETE /api/v1/newsfeed/{post}/reaction` | bearer | — | own | — | counts | scoped to the caller's own row | `implemented` |
| Read a thread | `GET /api/v1/newsfeed/{post}/comments` | bearer | — | — | `?page=` | visible comments | hidden and deleted are **absent**, not filtered; **no moderation fields at all** | `implemented` |
| Comment | `POST /api/v1/newsfeed/{post}/comments` | bearer | — | own | `{body,parent_id?}` | `201` comment | `is_official` is set by the **server** from permission; one level of reply; `409` when comments are closed | `implemented` |
| Edit own | `PATCH /api/v1/newsfeed-comments/{comment}` | bearer | — | author only | `{body}` | comment | **15-minute window**; a hidden comment cannot be edited back into visibility | `implemented` |
| Withdraw own | `DELETE /api/v1/newsfeed-comments/{comment}` | bearer | — | author only | — | `{deleted}` | a **state**, not a missing row | `implemented` |
| Share | `POST /api/v1/newsfeed/{post}/share` | bearer | — | own | — | counts | **accepts no destination**; the row holds a post, an optional account and a timestamp | `implemented` |
| Moderation queue | `GET /api/v1/admin/newsfeed-comments` | bearer | `newsfeed.moderate` | — | `?moderation_state=&search=` | all comments incl. removed | the moderator view; the only place a removed comment is readable | `implemented` |
| Moderate | `POST /api/v1/admin/newsfeed-comments/{comment}/moderation` | bearer | `newsfeed.moderate` | — | `{moderation_state,reason?}` | comment | a reason is **mandatory** for anything but restoring | `implemented` |

### Moderation state is a state, not a missing row

`deleted` does not delete. *"What did it say, who wrote it, who removed it and why"* is the
question asked when the author complains, and a hard delete makes every answer "we do not know".

The citizen thread narrows on `visible` **at the query**, and the comment count that travels with a
post agrees — a count including hidden comments would be a moderation log by arithmetic.

### A share is a counter

`newsfeed_shares` holds a post, an optional account and a timestamp, and
`NoShareRecipientDataTest` uses an **allow-list** so a destination column with an innocent name
still fails the build. *"Which platform do people share to?"* is a reasonable product question
whose answer turns a municipal welfare system into a record of who talks to whom.

### Abuse control

**20 writes a minute, keyed by account not IP** — a household behind one connection is several
legitimate residents, and a barangay hall's wifi is dozens. Reading is not throttled beyond the
global limit. `review-needed` is a state nothing currently sets: the hook a future moderation
provider writes into, so adding one is a listener rather than a migration (gap G-37).

---

## 11u. Events and public scheduling — built in TAB 25

**An event is not a post.** A post is a statement; an event is an operational commitment with a
venue, a time and a set of people who will physically travel somewhere because this system told
them to. Full reasoning: ADR 0030.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Public events list | `GET /api/v1/events` | **anonymous allowed** | — | — | `?category=&include_past=&page=` | published, cancelled and completed events | a draft is **absent**, not filtered; upcoming only unless `include_past` | `implemented` |
| Read an event | `GET /api/v1/events/{event}` | **anonymous allowed** | — | — | — | the event | accepts **a slug or a UUID** — a slug is what is on the poster; the lookup runs **against the public query**, so a guessed draft slug is `404` with no status check after it | `implemented` |
| Staff list | `GET /api/v1/admin/events` | bearer | `event.manage` | — | `?status=&category=&search=` | all events incl. drafts | — | `implemented` |
| Staff detail | `GET /api/v1/admin/events/{event}` | bearer | `event.manage` | — | — | the event incl. status and author | the staff projection; a draft is readable here and nowhere else | `implemented` |
| Draft | `POST /api/v1/admin/events` | bearer | `event.manage` | — | `{title,description,category,starts_at,ends_at,venue_name,venue_address,…}` | `201` event | end must be **after** start (`422`); a cover needs alt text (`422`) | `implemented` |
| Edit | `PATCH /api/v1/admin/events/{event}` | bearer | `event.manage` | — | partial | event | times validated against **merged** values, so a one-field update cannot invert the schedule | `implemented` |
| Publish / cancel / complete / archive | `POST /api/v1/admin/events/{event}/status` | bearer | **from the target state** | — | `{status,reason?}` | event | publishing and cancelling cost `event.publish`; a cancellation **must say why** (`422`); cancelled is not un-cancelled (`409`) | `implemented` |
| Duplicate | `POST /api/v1/admin/events/{event}/duplicate` | bearer | `event.manage` | — | — | `201` **draft** copy | fresh slug, no `published_at`, no cancellation carried over | `implemented` |
| Registration summary | `GET /api/v1/admin/events/{event}/registration-summary` | bearer | `event.manage` | — | — | window, capacity, computed availability, counts | `registered_count`/`waitlisted_count` are **present placeholders at 0** until TAB 26 | `implemented` |

### Availability is computed on every read

There is **no `registration_availability` column**, and `EventTest::there_is_no_stored_availability_column`
fails the build if one appears. A stored answer is wrong the moment the clock moves past it, and
whatever job was meant to rewrite it will one day not run — stale in the one direction that matters,
saying **open** to somebody registering for a window that shut.

Five distinct values: `not-required`, `not-open`, `open`, `closed`, `full`. `not-required` is not
`open` (a walk-in event told to a resident as "closed" turns them away from something they could
just attend), and `full` is not `closed` (a waitlist accepts in the first case and not the second).
The human wording travels with the state, so no client can invent a friendlier phrasing.

### Anonymous reading is ON for events and OFF for the newsfeed

Deliberate, and the asymmetry is the point. A poster with a QR code is read by somebody with no
account. The newsfeed carries **barangay-targeted** advisories, so anonymous access there would show
a relief schedule to people who are not on that hall's list (gap G-36); events have no audience
targeting at all — one municipality-wide list — so the same risk does not arise. If per-barangay
events are ever wanted, both questions come back together in a new ADR.

### Cancelled and completed events stay visible

Only `draft` and `archived` are invisible. **A cancelled event stays on the public list with its
reason showing** — somebody arranged their day around it, and removing it silently means they
travel to a covered court to find nobody there. `archived` is the separate, deliberate act for
taking something off the list.

### There is no citizen write path

Not a permission check that a future endpoint might omit: **`EventService` has no method a citizen
could call**, and the route file mounts no citizen write route. The test walks all seven staff
endpoints as a resident, asserts `403` on each, and re-reads the row to confirm nothing landed.

---

## 11v. Event registration, waitlist and attendance — built in TAB 26

**The only place in this system where two citizens compete for the same scarce thing.** Everything
here follows from one sentence: concurrent registrations cannot exceed capacity *according to
committed backend state*. Full reasoning: ADR 0031.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Take a place | `POST /api/v1/events/{event}/registration` | bearer | — | own | `Idempotency-Key?` | `201` new place / `200` place already held | decided under a **row lock** against counted rows; `409` carries capacity state; a draft event is `404`; rate limited | `implemented` |
| Withdraw | `DELETE /api/v1/events/{event}/registration` | bearer | — | own | — | the registration | **takes no id** — resolves the caller's own; refused once the event has started (`409`) | `implemented` |
| My registrations | `GET /api/v1/me/event-registrations` | bearer | — | own | `?upcoming=&page=` | own registrations + event summary | scoped at the query to the resident behind the token | `implemented` |
| One of mine | `GET /api/v1/me/event-registrations/{registration}` | bearer | — | own | — | the registration | somebody else's is **absent**, so `404` not `403`; by uuid **or reference** | `implemented` |
| Registrant list | `GET /api/v1/admin/events/{event}/registrations` | bearer | `event.manage` | — | `?status=&attendance=&page=` | list + live counts in `meta` | carries the resident's **name only** — no address, contact or vulnerability factor | `implemented` |
| Staff cancel | `POST /api/v1/admin/events/{event}/registrations/{registration}/cancel` | bearer | `event.manage` | — | `{reason}` | the registration | reason is **mandatory** and the registrant sees it; promotes the queue afterwards | `implemented` |
| Restore | `POST /api/v1/admin/events/{event}/registrations/{registration}/restore` | bearer | `event.manage` | — | — | the registration | **does not displace** whoever took the seat — goes to the waitlist if full | `implemented` |
| Work the waitlist | `POST /api/v1/admin/events/{event}/registrations/promote` | bearer | `event.manage` | — | — | promoted rows + counts | promotes **in order**, never a chosen person; idempotent | `implemented` |
| Mark attendance | `POST /api/v1/admin/events/{event}/registrations/{registration}/attendance` | bearer | `event.mark-attendance` | — | `{attendance}` | the registration | a **waitlisted** person cannot be marked (`409`); audit records **both** the old and new value | `implemented` |
| Registration summary | `GET /api/v1/admin/events/{event}/registration-summary` | bearer | `event.manage` | — | — | window, capacity, availability, **live counts** | TAB 25's zero placeholders are now real, with no client shape change | `implemented` |

### The registrant export

Requested through the existing export lifecycle — `POST /api/v1/admin/report-exports` with
`report=event-registrants` and `filters.event_id` — so it inherits everything ADR 0026 §3 decided:
queued and never inline, permission context snapshotted at request time, re-authorized at download.

It is **person-level**, so 24-hour retention and its own audit action, and it costs
`event.export-registrants` rather than `report.export.person-level` — a door list and a payout
manifest are two different authorities held by two different offices. Minimal fields: reference,
name, status, attendance. No address, no contact, no barangay, no staff note. An export naming no
event returns nothing rather than every registrant the LGU has ever had.

### There is no seat counter

A counter and the rows it counts are two sources of one fact, and they drift. When they disagree
**the counter wins**, because the counter is what the capacity check reads — and the court is
oversold with nothing in the log. Seats are counted from committed rows inside the lock that
decides the outcome. `there_is_no_registered_count_column_to_drift` fails the build if a counter
appears.

### Retry safety has two layers, and only one is optional

`Idempotency-Key` replays the stored response for clients that opt in. A client that sends no key
still cannot duplicate: the service returns the place already held (`200` rather than `201`), and
`uniq_event_registrations_active` — a nullable `active_key` unique with `event_id`, NULLs distinct
in both Postgres and SQLite — makes a second live row impossible at the database. The third layer
is the one that survives a code path nobody thought of.

Registration is keyed on the **resident**, not the account: a household sharing one phone is
several people, and one account may act for several residents.

### The citizen has no unscoped read

Every citizen read narrows to the resident behind the token *at the query*, so another person's
registration is **absent** rather than refused. Withdrawal takes no id at all. `403` would confirm
the id names a real registration, which is most of what an enumeration attempt wants.

The citizen projection carries no `staff_notes`, no cancelling officer and no account id — a
separate method, not the staff one with fields removed.

### Promotion is deterministic and idempotent

Order is `id` ascending — not a stored position, which drifts from the order people joined the
first time somebody in the middle cancels. Each promotion is a conditional
`UPDATE ... WHERE status = 'waitlisted'` inside the event lock, so running it twice promotes nobody
twice. It runs after every cancellation **and after a capacity increase**, which is the other way
room appears and the easy one to forget.

Announced as `EventRegistrationPromoted` and delivered by Notification — a push outage cannot roll
back a promotion. A no-show deliberately does **not** free a seat: the event is already running,
and promoting somebody at home is a message telling them to travel to something that started an
hour ago.

### Attendance defaults to `not-checked-in`, never `no-show`

Before the door opens, "we have not marked this person" is the truth. A no-show record quietly
shapes who gets a seat next time, so it is always an act somebody performed, and it is audited with
**both** the old and the new value. A waitlisted person cannot be marked present: it would put the
attendance list above capacity, and every later count would silently disagree with how many were
let in.

### What the suite does not prove

The tests are single-process and SQLite compiles `lockForUpdate()` away, so the capacity test
proves the arithmetic and not the race. The lock is asserted structurally instead
(`every_seat_decision_is_taken_behind_a_row_lock`), and verification under real concurrency against
PostgreSQL is **gap G-40**.

---

## 11w. Citizen API composition — built in TAB 27

**No business logic was added by this TAB, and that is the design.** Every citizen endpoint the
master command lists already existed in the module that owns the fact behind it. What was missing
was a way to know what the citizen surface *is*, an enforced guarantee that nothing internal
reaches it, and the two platform concerns no domain module owns. Full reasoning: ADR 0032.

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| App bootstrap | `GET /api/v1/app/bootstrap` | **anonymous** | — | — | `X-Client-Channel?` | api version, server time, minimum client version, feature flags, header conventions, support contact | **carries nothing worth protecting** — it must answer a client that cannot yet sign in; asserted against secret-shaped words | `implemented` |

### The citizen surface is declared, not inferred

`Modules\Shared\Support\CitizenSurface` lists every citizen route name and every staff route name
that does not sit behind `admin/`. `CitizenSurfaceTest` fails the build for a registered route in
neither list.

A prefix rule would have been wrong on day one: `staff/*` and `tasks/*` are staff endpoints with no
`admin` prefix, and a path test classifies all fourteen as citizen-facing. The list is the
*mechanism*, not the documentation — declaring a route "citizen" enrols it in the leak scan
automatically, and there is no way to abstain.

### Internal fields are absent by construction

`CitizenLeakScanTest` calls every readable citizen endpoint as a real resident, against rows
deliberately poisoned first — a staff note on their event registration, a moderation reason and
moderating officer on their comment, an assigned caseworker on their welfare case — and scans the
whole response tree at any depth, inside paginated arrays included.

Three things make it trustworthy: **the fixture asserts the poison landed** (an `UPDATE` matching
no rows returns zero and throws nothing, so a scan against an empty table would be green for the
reason it exists to rule out); **the scanner is tested positively and negatively** before it is
trusted; and **the scan proves its own coverage** — a declared citizen `GET` that is never called
fails the build.

One stated exemption: `GET /api/v1/me` returns the caller's **own** `permissions` and `roles`.
Article 3.4 forbids the server *trusting* an authority list from a client, which is not the same as
telling a client what it holds — the admin console cannot render a menu otherwise. Keyed to that
URL and those two fields, with the reason recorded; the list entry still catches an authority list
turning up inside a case, a comment or a registration.

### Web and mobile see the same business outcome

Every readable citizen URL is called twice with different `X-Client-Channel` values and the `data`
payloads must be equal. `meta` is not compared — the channel picks a default page size, which is
presentation (ADR 0002). Asserted across the whole surface rather than one endpoint, because the
one that drifts will be the one nobody chose. `app/bootstrap` is exempt: describing the client is
its job.

### Cache directives

| Response | Directive |
| --- | --- |
| Authenticated, any route | `no-store, no-cache, private, must-revalidate` + `Vary: Authorization, X-Client-Channel` |
| A route that declares nothing | the same — **private is the default** |
| Error or write | the same |
| `events`, `events/{event}`, `services`, `programs`, `programs/{program}` — **anonymous only** | `public, max-age=<PUBLIC_CACHE_SECONDS>` |

`no-store`, not `no-cache`: the two are routinely confused and only one means what is needed —
`no-cache` permits storage and requires revalidation, so a resident's file is still written to a
proxy's disk.

Public is opt-in per route and **downgraded the moment there is an authenticated caller**. The
events list is genuinely public until a signed-in resident asks for it, at which point the response
is about somebody. No `ETag` is emitted: on a private response it is a small fingerprint of that
response sitting in a proxy's memory, and `max-age` is the part that saves a phone real time.

### Netlify origins

`config/cors.php` was already a deny-by-default allow-list with credentials off. What TAB 27 adds
is that weakening it now fails a test: no wildcard origin while credentials are enabled, no origin
patterns while credentials are enabled, and **no `*.netlify.app` in any pattern** — anybody can
create a site on that domain, so allowing the suffix allows every Netlify user. Deploy previews
point at staging and must never reach production.

---

## 11x. Media and object-storage security — built in TAB 28

**No new endpoint.** This TAB changed what two existing projections carry and added a second object
store behind them. Full reasoning: ADR 0033.

| Projection | New field | Meaning |
| --- | --- | --- |
| `GET /api/v1/newsfeed`, `GET /api/v1/newsfeed/{post}` | `media[].urls` | `{thumbnail, web}` public URLs of the **re-encoded** renditions. **Empty until the post is live**, and empty is also the answer for a post that never had an image — so the absence discloses nothing about a draft. |
| `GET /api/v1/events`, `GET /api/v1/events/{event}` | `cover_urls` | the same, for an event cover. Empty until published; **kept when the event is cancelled** (ADR 0030 §3); removed when archived. |

`file_id` and `cover_file_id` are unchanged and still name the **private** original, which staff
reach through the authorization-gated document endpoint. Nothing about the private path moved.

### Publication derives; it never promotes

The uploaded file is never moved, copied or re-permissioned. Publishing derives a **new** object by
re-encoding the image and writes that to a separate bucket, so "do not place sensitive attachments
in a public bucket even temporarily" is not a rule anybody follows — there is no code path that
could.

Four gates, all inside Files rather than in the calling module: the classification must be
`public-reference` (personal, sensitive and operational are refused outright), the scan must not
have failed, it must be an image, and the derivation must succeed. A module that attached a KYC
photograph to a post cannot publish it by being wrong.

### EXIF is never present, rather than stripped

Stripping requires knowing every segment that can carry a location — EXIF, XMP and IPTC in JPEG;
`eXIf`, `tEXt` and `iTXt` in PNG — and a stripper that knows five of six leaks silently. Re-encoding
decodes to a pixel buffer and writes a new file: **there is nowhere for a coordinate to be.**

`MediaSecurityTest` builds a JPEG carrying a real GPS block, asserts the fixture really carries it,
publishes through the real workflow and reads the bytes back out of the public bucket.

Orientation is baked into the pixels **before** the re-encode — otherwise an image that displayed
correctly beforehand displays on its side afterwards, and nothing looks wrong because the file is
genuinely valid.

If the image library is missing, **no public object is produced**. A fallback to copying the
original would leak metadata on exactly the one host where the library was absent.

### Withdrawal is the direction that matters

Archiving a post or an event removes the public objects and their rows. A takedown whose image
stayed at a public URL would not have taken anything down, and the URL is the part that gets
shared, screenshotted and indexed. Driven by the content's own visibility predicate, so a status
added to either enum later cannot leave an image public on content nobody can see.

### Two buckets, one writer

| Disk | Holds | Visibility | Base URL |
| --- | --- | --- | --- |
| `object-storage` | every upload: KYC IDs, case requirements, referral attachments, welfare documents | private | **none** — delivered by an authorization-gated stream or a short-lived grant |
| `public-media` | re-encoded renditions of published content, and nothing else ever | public | yes |

Separate buckets with separate credentials, not a public prefix in the private one: one misapplied
policy on a shared bucket exposes everything in it. `PublicMediaHasOneWriterTest` fails the build if
any file under `modules/` other than `MediaPublisher` names the public disk, and if any declared
disk but the public ones publishes a base `url`.

### Uploads

| Rule | Value |
| --- | --- |
| Type | read from the **leading bytes**; the declared `Content-Type` and the extension are both caller-supplied and both look correct on a file that is neither |
| Storage key | opaque, UUID-derived; nothing the caller sent contributes a character |
| Size | **per context** — 4 MiB `public-reference`, 8 `operational`, 10 `personal`/`sensitive`, all under the proxy's `client_max_body_size` |
| Duplicates | detected by checksum and **recorded, not refused** — re-sending one clearance against a second requirement is legitimate; the office wants to be told, not to block the resident |
| Variants | `thumbnail` 400px and `web` 1280px, longest edge, never enlarged |

---

## 11y. Audit, privacy and data governance — built in TAB 29

**The trail existed for ten TABs and had no reader.** Every entry was written and none could be
retrieved without a database console. Full reasoning: ADR 0034. The technical inventory prepared
for a Privacy Impact Assessment is [`docs/privacy/personal-data-inventory.md`](../privacy/personal-data-inventory.md).

| Screen / caller | Endpoint | Auth | Permission | Scope | Request | Response | Sensitivity | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Privacy notice | `GET /api/v1/privacy/notice` | **anonymous** | — | — | — | current version, whether the caller acknowledged it, **the legal bases**, the consent purposes | a notice requiring an account to read is one a person cannot consult before creating an account | `implemented` |
| Acknowledge | `POST /api/v1/me/privacy/acknowledgement` | bearer | — | own | — | timestamp | records being **shown** the notice — not consent to anything | `implemented` |
| My consents | `GET /api/v1/me/privacy/consents` | bearer | — | own | `?page=` | own consents incl. withdrawn | scoped at the query; **no identifier in the contract** | `implemented` |
| Give consent | `POST /api/v1/me/privacy/consents` | bearer | — | own | `{purpose,evidence?}` | `201` consent | **`422` for any purpose whose legal basis is not consent** | `implemented` |
| Withdraw | `DELETE /api/v1/me/privacy/consents/{purpose}` | bearer | — | own | `{reason?}` | the consent | a **timestamp**, never a deleted row; re-consenting later is allowed | `implemented` |
| Audit search | `GET /api/v1/admin/audit-entries` | bearer | **`audit.view`** | — | `?action=&entity_type=&entity_id=&actor_subject_id=&request_id=&risk=&from=&to=` | entries | **the search is itself audited**; held only by `data_protection_officer` | `implemented` |
| One entry | `GET /api/v1/admin/audit-entries/{entry}` | bearer | `audit.view` | — | — | the entry | — | `implemented` |
| Trail for a record | `GET /api/v1/admin/audit-entries/for-entity` | bearer | `audit.view` | — | `?entity_type=&entity_id=` | entries, **oldest first** | the order somebody reconstructing events reads in | `implemented` |
| Vocabulary | `GET /api/v1/admin/audit-entries/vocabulary` | bearer | `audit.view` | — | — | risk bands, the declared high-risk catalogue, whether network capture is on | so a console builds filters without hardcoding a list | `implemented` |
| Publish a notice | `POST /api/v1/admin/privacy/notices` | bearer | `privacy.manage` | — | `{version,title,summary,document_url?,effective_from?}` | `201` notice | the previous version is **superseded, never edited** | `implemented` |
| Retention schedule | `GET /api/v1/admin/privacy/retention` | bearer | `privacy.manage` | — | — | categories, legal bases, `approved` flag, and a notice saying none of it is law | answered by the running system, not a document | `implemented` |
| Legal holds | `GET /api/v1/admin/privacy/legal-holds` | bearer | `privacy.manage` | — | `?active=&page=` | holds | — | `implemented` |
| Place a hold | `POST /api/v1/admin/privacy/legal-holds` | bearer | `privacy.manage` | — | `{entity_type,entity_id?,subject_id?,reference,reason}` | `201` hold | a **reason is mandatory**; a subject-level hold covers every record about them | `implemented` |
| Lift a hold | `POST /api/v1/admin/privacy/legal-holds/{hold}/lift` | bearer | `privacy.manage` | — | `{reason}` | the hold | lifting is what **allows a record to be destroyed** — reason mandatory, high-risk audited, never deleted | `implemented` |

### One writer, and the interface lives in Shared

Ten modules hand-rolled the same insert and had begun to differ. Consolidating produced the cycle
`AccessControl → Audit → AccessControl` and `ModuleBoundaryTest` failed the build — correctly,
since `Audit` has a surface of its own that must ask who may read it. The resolution is
`Modules\Shared\Contracts\AuditWriter`: an interface in the module everyone may depend on, bound to
the one implementation in `Audit`. **No null fallback** — if the binding is missing the application
does not boot.

`AuditIsAppendOnlyTest` fails the build if any file but `AuditTrail` inserts into `audit_entries`,
or if anything anywhere calls `update`, `delete` or `truncate` on it.

### The trail is not a second copy of the data

`changed_fields` holds **column names**. An associative array is read for its **keys** — a
`$changes` array is already keyed by field name, so passing one whole looks right and would write
the values. Anything not matching a column-name pattern is **dropped, not stored**.

`AuditAndPrivacyTest` exercises the system, scans every persisted summary for PhilSys numbers,
emails, mobile numbers, birth dates and street addresses, and **tests the scanner against planted
identifiers first**.

Network identifiers are captured for `high` risk entries only, and only when
`AUDIT_CAPTURE_NETWORK` is on — off by default, because capturing an IP on every routine read
builds a movement log of the office's own staff.

### Consent is the minority case

Five purposes are `public-task` or `legal-obligation`; four are genuinely `consent`. The system
**refuses** to record a consent for the first group, and the refusal is the point: consent implies a
right to withdraw, and offering withdrawal for statutory processing is a promise the office cannot
keep. The purposes are derived from the bases, never listed twice.

### Retention: the machinery exists, the switch is off

`RetentionPolicy::mayPurge()` refuses everything while `PRIVACY_RETENTION_APPROVED` is false —
tested against a record twenty years past any plausible schedule. A legal hold outranks the schedule
in one direction only: it can prevent a deletion and can never cause one.

### The auditee is not the auditor

`audit.view` is deliberately **not** on `lgu_admin`. The trail records the MSWDO head's own
approvals, document reads and exports. `Role::DataProtectionOfficer` holds it — and holds **no
operational permission at all**, so a DPO cannot open a case, read a resident record or approve
anything. Same shape as `DisbursingOfficer` in ADR 0023 §3.

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
