# Entity-Relationship Map — all planned domains

High-level shape of the whole schema, with **module ownership** marked. Conventions:
[ADR 0008](../adr/0008-database-conventions.md). Ownership rules:
[domain-boundary-map.md](domain-boundary-map.md).

This is a map, not a specification. Each module's TAB designs its own columns; what is
fixed here is **who owns which fact** and **where a foreign key may not go**.

## How to read it

* **Built** — the table exists today (TAB 04 foundation).
* **Planned** — the owning module's TAB creates it.
* A solid relationship is a real foreign key. It only ever appears **inside one module**.
* A dashed relationship is an **identifier reference with no foreign key** — the only way
  modules may point at each other (CLAUDE.md Article 2.2). The database does not enforce
  it; the owning module's application service does.

---

## 1. Foundation — built in TAB 04

```mermaid
erDiagram
    BARANGAYS ||--o{ ROLE_ASSIGNMENTS : "scopes (FK)"

    BARANGAYS {
        bigint id PK
        uuid uuid UK
        string code UK
        string name
        string psgc_code UK "nullable — PSA dataset not loaded (G-11)"
    }
    ROLE_ASSIGNMENTS {
        bigint id PK
        uuid uuid UK
        uuid subject_id "Identity account — NO FK"
        string role
        enum scope_type "check constraint"
        bigint barangay_id FK "nullable"
        timestamptz valid_from
        timestamptz valid_until "nullable = open"
        uuid granted_by "NO FK"
    }
    AUDIT_ENTRIES {
        bigint id PK
        uuid uuid UK
        timestamptz occurred_at
        uuid actor_subject_id "NO FK"
        string action
        string entity_type
        uuid entity_id
        string request_id
        timestamptz created_at "no updated_at — append-only"
    }
    IDEMPOTENCY_KEYS {
        bigint id PK
        uuid uuid UK
        string idempotency_key
        uuid subject_id "NO FK"
        string endpoint
        string request_fingerprint
        json response_body "listed JSON exception"
        timestamptz expires_at
    }
```

`AUDIT_ENTRIES` and `IDEMPOTENCY_KEYS` intentionally have no relationships at all. Audit
must survive the deletion of anything it describes, and an idempotency key is a short-lived
cache keyed by caller.

---

## 2. The whole map

```mermaid
erDiagram
    ACCOUNTS ||--o{ AUTH_TOKENS : issues
    ACCOUNTS ||--o{ DEVICES : registers
    ACCOUNTS ||--o{ MFA_FACTORS : holds

    RESIDENTS ||--o{ RESIDENT_SECTORS : "tagged with"

    HOUSEHOLDS ||--o{ HOUSEHOLD_MEMBERSHIPS : "houses (effective-dated)"
    RESIDENTS ||--o{ HOUSEHOLD_MEMBERSHIPS : "lives in"
    HOUSEHOLDS ||--o{ FAMILIES : "contains (several)"
    FAMILIES ||--o{ FAMILY_MEMBERSHIPS : "groups (effective-dated)"
    RESIDENTS ||--o{ FAMILY_MEMBERSHIPS : "belongs to"
    RESIDENTS ||--o{ RESIDENT_RELATIONSHIPS : "related to (one directed row)"
    RESIDENTS ||--o{ RESIDENT_VULNERABILITY_FACTORS : "observed (time-bounded)"
    HOUSEHOLDS ||--o{ HOUSEHOLD_VULNERABILITY_FACTORS : "observed (time-bounded)"

    RESIDENTS ||--o{ RESIDENT_STATUS_EVENTS : "history (append-only)"
    RESIDENTS ||--o{ RESIDENT_ALIASES : "also known as"
    RESIDENTS ||--o{ RESIDENT_CORRECTION_REQUESTS : "corrected by"
    RESIDENT_CORRECTION_REQUESTS ||--o{ RESIDENT_CORRECTION_FIELDS : proposes
    RESIDENTS ||--o{ ACCOUNT_RESIDENT_LINKS : "acted for by"
    RESIDENTS ||--o{ RESIDENT_DUPLICATE_PAIRS : "possibly duplicates"
    RESIDENTS ||--o{ RESIDENT_MERGES : "survivor of"

    CREDENTIALS ||--o{ CREDENTIAL_TRANSITIONS : "lifecycle (append-only)"
    CREDENTIALS ||--o{ CREDENTIAL_ARTIFACTS : renders

    VERIFICATION_ATTEMPTS }o--|| VERIFIER_DEVICES : "scanned by"

    PROGRAMS ||--o{ PROGRAM_REQUIREMENTS : requires
    PROGRAMS ||--o{ PROGRAM_ELIGIBILITY_CRITERIA : "advised by (never gated)"
    PROGRAM_REQUIREMENTS ||--o{ PROGRAM_REQUIREMENT_DOCUMENTS : accepts
    PROGRAMS ||--o{ PROGRAM_INTAKE_CHANNELS : "accepts via"
    PROGRAMS ||--o{ PROGRAM_APPROVERS : "signed by"
    WELFARE_CASES ||--o{ WELFARE_CASE_ELIGIBILITY_CHECKS : "checked against"
    WELFARE_CASE_ELIGIBILITY_CHECKS ||--o{ WELFARE_CASE_ELIGIBILITY_RESULTS : explains
    SERVICES ||--o{ SERVICE_CHANNELS : "offered on"

    WELFARE_CASES ||--o{ WELFARE_CASE_TRANSITIONS : "lifecycle (append-only)"
    WELFARE_CASES ||--o{ WELFARE_CASE_ASSIGNMENTS : "held by (effective-dated)"
    WELFARE_CASES ||--o{ WELFARE_CASE_EVENTS : "timeline (append-only)"
    WELFARE_CASES ||--|| ASSISTANCE_INTAKES : "opened by"
    WELFARE_CASES ||--o{ ASSESSMENTS : "assessed by"
    ASSESSMENTS ||--o{ ASSESSMENT_ANSWERS : records
    ASSISTANCE_DRAFTS ||--o| ASSISTANCE_INTAKES : "becomes (on submit)"

    PROGRAMS ||--o{ PROGRAM_ENROLLMENTS : "enrols onto (effective-dated)"
    WELFARE_CASES ||--o{ PROGRAM_ENROLLMENTS : "produced (where one did)"

    WELFARE_CASES ||--o{ WELFARE_CASE_REQUIREMENTS : "must satisfy (copied at intake)"
    WELFARE_CASE_REQUIREMENTS ||--o{ DOCUMENT_REQUESTS : "chased by"
    WELFARE_CASE_REQUIREMENTS ||--o| DOCUMENTS : "filled by"
    DOCUMENTS ||--o{ DOCUMENT_VERSIONS : "history (append-only)"
    DOCUMENT_VERSIONS }o--o| STORED_FILES : "points at (null for encoded)"
    STORED_FILES ||--o{ FILE_ACCESS_GRANTS : "opened by (single-use)"

    SERVICE_PROVIDERS ||--o{ REFERRALS : "receives (snapshotted, never re-read)"
    WELFARE_CASES ||--o{ REFERRALS : "routed from (optional)"
    REFERRALS ||--o{ REFERRAL_SHARED_FIELDS : "released (one row per field, with a reason)"
    REFERRALS ||--o{ REFERRAL_ATTACHMENTS : "released (opt-in, never automatic)"
    REFERRALS ||--o{ REFERRAL_NOTES : "annotated (append-only, split by audience)"

    ASSISTANCE_REQUESTS ||--o{ REQUEST_REQUIREMENTS : "must satisfy"
    ASSISTANCE_REQUESTS ||--o{ REQUEST_TRANSITIONS : "lifecycle (append-only)"
    ASSISTANCE_REQUESTS ||--o{ CASE_NOTES : "annotated by"
    ASSISTANCE_REQUESTS ||--o| ASSESSMENTS : "assessed by"
    ASSISTANCE_REQUESTS ||--o{ DISBURSEMENTS : "paid by"
    DISBURSEMENTS ||--o{ DISBURSEMENT_TRANSITIONS : "lifecycle (append-only)"
    ASSISTANCE_REQUESTS ||--o{ REFERRALS : "routed as"

    NOTIFICATIONS ||--o{ NOTIFICATION_DISPATCHES : "delivered by"
```

### Cross-module identifier references — no foreign key, ever

| From | To | Owned by | Why no FK |
| --- | --- | --- | --- |
| `role_assignments.subject_id` | account | Identity | AccessControl must not join Identity's tables |
| `audit_entries.actor_subject_id` | account | Identity | Audit must outlive the account it names |
| `idempotency_keys.subject_id` | account | Identity | Shared kernel depends on no domain |
| `residents.account_id` | account | Identity | a resident may exist with no account (walk-in) |
| `credentials.resident_id` | resident | ResidentProfile | Credential asks ResidentProfile's service |
| `assistance_requests.resident_id` | resident | ResidentProfile | ServiceDelivery asks, never joins |
| `assistance_requests.program_id` | programme | ServiceCatalog | catalog is a separate owner |
| `verification_attempts.credential_id` | credential | Credential | verification runs at the edge, sees no PII |
| `notifications.recipient_subject_id` | account | Identity | Notification decides *whether*, not *who* |
| `welfare_cases.resident_id` | resident | ResidentProfile | Welfare asks ResidentProfile's directory, never joins |
| `welfare_cases.assigned_to` | account | Identity | the assignee may be deactivated; the case history must survive |
| `account_resident_links.account_id` | account | Identity | the link is ResidentProfile's record of Identity's account; written with `accounts.resident_id` in one transaction |
| `resident_correction_requests.requested_by` | account | Identity | the requester may be deactivated later; the request must survive |
| `resident_status_events.actor_subject_id` | account | Identity | history outlives the staff member who made it |
| `program_enrollments.resident_id` | resident | ResidentProfile | **the beneficiary is the canonical resident** — a `beneficiaries` table would be a second population for duplicate detection to reconcile (ADR 0019 §1) |
| `program_enrollments.household_id` | household | ResidentProfile | set for household-scoped programmes; relief is per household, 4Ps is per family |
| `program_enrollments.program_id` | programme | ServiceCatalog | Welfare asks the catalogue, never joins it |
| `documents.owner_id` | the owning record | whichever module set `owner_type` | Files must not know about the modules above it; a real FK would force it to (ADR 0020 §1) |
| `welfare_case_requirements.document_id` | document | Files | Welfare holds the pointer and asks `DocumentLibrary`; it never holds a Files model |
| `stored_files.uploaded_by` | account | Identity | the uploader may be deactivated; the evidence of who supplied a document must outlive the account |
| `file_access_grants.issued_to` | account | Identity | a grant is bound to a person, and outlives their session by design |
| `referrals.resident_id` | resident | ResidentProfile | **a referral always links to a client** — a disclosure about nobody in particular cannot be audited or answered to a subject-access request |
| `referrals.provider_id` | provider | ServiceCatalog | Welfare asks the directory; the name and type are **snapshotted** so a renamed office does not rewrite where a referral actually went |
| `referral_attachments.document_id` | document | Files | opt-in, one at a time, each with a reason |

Twenty-four references, twenty-four places a convenient join would have silently welded two
modules together. Each is an identifier plus a service call.

**Every table above carrying `resident_id` must be repointed by a merge**, and
`ResidentMergeCoverageTest` fails the build if a module holding one has no mechanism to do it.
That test exists because `welfare_cases.resident_id` sat in this list for three TABs with nothing
moving it (ADR 0019 §4).

---

## 3. Ownership and classification

`sensitive` follows ADR 0008 §9: RA 9262 / RA 9344 membership, health and biometric data.

| Module | Canonical tables | Highest class | Status |
| --- | --- | --- | --- |
| **Shared** | `idempotency_keys`, `barangays` | `personal` (cached response body) / `public` | **built** |
| **AccessControl** | `role_assignments` | `internal` | **built** |
| **Audit** | `audit_entries` | `internal` (records access, never the data) | **built** |
| **Identity** | `accounts`, `auth_tokens`, `devices`, `mfa_factors` | `personal` | planned — TAB 05 |
| **ResidentProfile** | `residents`, `resident_sectors`, `kyc_cases`, `resident_match_candidates`, `resident_status_events`, `resident_aliases`, `resident_duplicate_pairs`, `resident_merges`, `resident_correction_requests`, `resident_correction_fields`, `account_resident_links`, `households`, `families`, `household_memberships`, `family_memberships`, `resident_relationships`, `resident_vulnerability_factors`, `household_vulnerability_factors` | **`sensitive`** | **built** — TAB 06, TAB 08, TAB 09, TAB 10 |
| **ServiceCatalog** | `programs`, `program_requirements`, `program_requirement_documents`, `program_eligibility_criteria`, `program_intake_channels`, `program_approvers`; `services` and `service_channels` remain config-backed | `public` | **built** — TAB 13 (services: config) |
| **Welfare** | `welfare_cases`, `welfare_case_transitions`, `welfare_case_assignments`, `welfare_case_events`, `assistance_drafts`, `assistance_intakes`, `assessments`, `assessment_answers`, `welfare_case_eligibility_checks`, `welfare_case_eligibility_results` | **`sensitive`** | **built** — TAB 11, TAB 12, TAB 13 |
| **Credential** | `credentials`, `credential_artifacts`, `credential_transitions` | `personal` | planned |
| **Verification** | `verification_attempts`, `verifier_devices` | `internal` | planned |
| **ServiceDelivery** | `assistance_requests`, `request_requirements`, `request_transitions`, `case_notes`, `assessments`, `disbursements`, `disbursement_transitions`, `referrals` | **`sensitive`** | planned |
| **Notification** | `notifications`, `notification_dispatches`, `notification_preferences` | `internal` | planned |

Two facts are worth stating because they are the ones most likely to be duplicated by
accident:

* **A resident's identity lives only in `residents`.** Not on the request, not on the
  credential, not on the disbursement. Those carry `resident_id`. A name copied onto an
  assistance request is a second source of truth that will disagree after the first
  correction.
* **Lifecycle history lives only in the `*_transitions` tables.** The parent row carries
  the *current* state; how it got there is append-only elsewhere (ADR 0007, ADR 0008 §12).

## 4. Patterns every planned table inherits

* `id` bigint PK + `uuid` (v7) unique — internal joins, external identifiers.
* `timestamptz` throughout; `date` for date-only facts.
* Append-only history tables: `created_at` only, no `updated_at`, no delete path.
* Soft deletes where a record must stay referenceable; never for citizen records that carry
  statutory retention.
* A unique index on every relationship pair, with any nullable column kept **out** of the
  key (ADR 0008 §5).
* Every foreign key indexed.
* No application state in JSON — the allow-list is in
  `tests/Architecture/DatabaseConventionsTest.php`.
