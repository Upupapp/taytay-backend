# API security checklist

Status: **the state of this backend against the OWASP API Security Top 10**, as reviewed in TAB 30.

Every "held by" below names a **test that fails the build**, not a convention. Where a control is
absent, it says so and names the gap. Reasoning: [ADR 0035](../adr/0035-api-security-and-abuse-protection.md).

---

## OWASP API Security Top 10

### API1 — Broken object-level authorization

| | |
| --- | --- |
| **State** | held |
| **How** | every citizen read is scoped **at the query** to the resident resolved from the token; staff reads are additionally scoped by barangay (ADR 0012) |
| **Answer on failure** | **`404`, never `403`** — a `403` confirms the id names a real record, which is most of what an enumeration attempt wants |
| **Held by** | `ApiSecurityTest::a_citizen_cannot_reach_another_citizens_object_by_substituting_its_id`, `…::a_barangay_scoped_clerk_cannot_read_a_resident_from_another_barangay`, `CitizenLeakScanTest` |

The scan is kept honest by `the_substitution_scan_actually_found_records_to_try`, which fails if the
fixture built nothing to substitute — a list of zero URLs yields zero findings and a green test.

### API2 — Broken authentication

| | |
| --- | --- |
| **State** | held |
| **How** | Sanctum bearer tokens; MFA; account lockout; revocable sessions and per-device tokens; the login response reveals nothing about whether an account exists |
| **Held by** | `ApiSecurityTest::the_login_response_does_not_reveal_whether_an_account_exists`, the Identity suite |

### API3 — Broken object property-level authorization

| | |
| --- | --- |
| **State** | held |
| **How** | two projections per module — a citizen one and a staff one, each its **own method** rather than the other with fields removed (ADR 0028 §1) |
| **Held by** | `CitizenLeakScanTest` calls every readable citizen endpoint against deliberately poisoned rows and scans the whole tree for forbidden field names |

### API4 — Unrestricted resource consumption

| | |
| --- | --- |
| **State** | held |
| **How** | every collection paginated (Article 4); per-classification upload limits (ADR 0033 §5); rate limits on every named surface |
| **Held by** | `ApiSecurityTest::every_declared_rate_limiter_is_registered`, `MediaSecurityTest::the_size_limit_is_per_context` |

### API5 — Broken function-level authorization

| | |
| --- | --- |
| **State** | held |
| **How** | `authorize($actor, Permission::X)` on every privileged endpoint; the `admin/` prefix grants nothing (Article 3) |
| **Answer on failure** | **`403`** — the existence of an approval endpoint is not a secret, and a `404` would make a permissions problem look like a broken client |
| **Held by** | `ApiSecurityTest::front_line_staff_cannot_call_the_endpoints_reserved_above_them`, `…::a_disbursing_officer_cannot_approve_and_an_approver_cannot_release`, `…::the_audit_trail_is_not_readable_by_the_office_it_audits` |

### API6 — Unrestricted access to sensitive business flows

| | |
| --- | --- |
| **State** | held |
| **How** | separation of duties enforced at the role level: no non-administrator role both approves a case and releases its money (ADR 0023 §3); the auditee cannot read the audit trail (ADR 0034 §7); event capacity decided under a row lock (ADR 0031 §2) |
| **Held by** | the two tests named above, plus `EventRegistrationTest` |

### API7 — Server-side request forgery

| | |
| --- | --- |
| **State** | held **by absence** |
| **How** | there is no server-side fetch at all. `map_url` on an event is stored and returned, never fetched |
| **Exception** | FCM — a fixed, configured endpoint, not a caller-supplied one |
| **Held by** | `NoServerSideFetchTest`, including a negative fixture proving the scanner detects a planted fetch |

### API8 — Security misconfiguration

| | |
| --- | --- |
| **State** | held |
| **How** | security headers on every response; CORS deny-by-default with no wildcard and no `*.netlify.app`; private cache directives by default; no `url` on any private disk |
| **Held by** | `ApiSecurityTest::every_response_carries_the_security_headers`, `ClientDeliveryTest`, `PublicMediaHasOneWriterTest` |

### API9 — Improper inventory management

| | |
| --- | --- |
| **State** | held |
| **How** | one version (`/api/v1`); every route classified as citizen or staff; the contract matrix checked against the router **in both directions** |
| **Held by** | `CitizenSurfaceTest`, `ContractMatrixTest` |
| **Gap** | no generated OpenAPI 3.1 document (**G-44**) |

### API10 — Unsafe consumption of third-party APIs

| | |
| --- | --- |
| **State** | held |
| **How** | one third-party dependency (FCM), fixed endpoint, short-lived OAuth from a stored service account, no PII in any payload (Article 8.3/8.4) |
| **Held by** | `InfrastructureAlignmentTest` |

---

## Mass assignment

Two shapes, protected differently — and the difference is worth understanding before changing
either:

| Shape | Behaviour | Why |
| --- | --- | --- |
| An unknown **resident** field (`verification_tier`, `philsys_number`) in a correction request | **`422`, named back to the caller** | A contract that silently dropped it would answer `201` and teach a client that self-promotion to a verified identity had worked |
| A field of the **request record itself** (`status`, `reviewed_by`) | absent | `$request->validate()` returns only validated keys — the field never arrives at the service |

Every Eloquent model is `$guarded = ['id']`, which is permissive. **The real control is the
validation allow-list at the controller**, and the tests above are what make that a tested property
rather than a convention (**G-49**).

---

## Rate limits

| Surface | Limit | Keyed by |
| --- | --- | --- |
| Sign-in / code verification | 10/min | IP **and** hashed identifier |
| OTP / reset request | 5/min | IP **and** hashed identifier |
| Registration | 5/min | IP |
| KYC submission | 5/min | account |
| Engagement (comments, reactions, shares) | 20/min | account |
| Event registration | 20/min | account |
| Search | 30/min | account |
| **Export request** | **10/hour** | account |
| Everything else | 120/min | account, or IP if anonymous |

All in `config/security.php`; all registered in `Modules\Shared\Http\RateLimits`.

---

## Error responses

* One renderer for every module (ADR 0003), so shape cannot drift.
* `{ "error": { "code", "message", "details", "request_id" } }` — `code` is stable and
  machine-readable; `message` is for operators and never leaks internals, stack traces or SQL.
* Every response carries `X-Request-Id`, echoed inside error payloads so a citizen can quote it.
* **Verified by attempting a real failure**, not by mocking one:
  `ApiSecurityTest::an_error_body_carries_no_internals`.

---

## Security headers

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `no-referrer` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=(), payment=(), usb=()` |
| `Strict-Transport-Security` | **off** until `HSTS_ENABLED`, and only over HTTPS |

Netlify sets the *frontend* headers (CSP for the SPA, frame restrictions, referrer policy) in its
own configuration. These are the API's.

---

## Not built, deliberately

| | Gap | Why |
| --- | --- | --- |
| CSRF tokens | **G-43** | Bearer tokens, deny-by-default CORS, credentials off — no ambient credential for a cross-site request to ride. Arrives with cookie mode if that is ever adopted |
| Firebase App Check | **G-50** | An attestation check that fails open is decoration; one that fails closed needs a staged rollout plan the LGU has not made |
| Model-level `$fillable` | **G-49** | The validation allow-list is the real control and is tested; tightening 85 models is a large change with its own regression risk |
| OpenAPI 3.1 | **G-44** | The contract matrix is machine-checked against the router in both directions |

---

## For whoever deploys this

1. Set `CORS_ALLOWED_ORIGINS` to the **exact** production custom domains. Never `*`, never a
   `*.netlify.app` pattern — anybody can create a site on that domain.
2. Leave `HSTS_ENABLED` off until the custom domains and certificates are confirmed. Turning it on
   early cannot be undone from the server.
3. Set `TRUSTED_PROXIES` to the NodeBalancer or private-network CIDR. Without it every caller
   shares one rate-limit key and every audit entry is attributed to the load balancer.
4. Keep nginx's `client_max_body_size` **above** the largest per-classification limit (10 MiB). If
   nginx rejects the body first it answers `413` without CORS headers, and the browser sees a
   network failure with status 0.
5. Decide `AUDIT_CAPTURE_NETWORK` with the DPO (ADR 0034 §3).
