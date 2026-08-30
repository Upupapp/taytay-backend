# Architecture Decision Records

One file per material decision. Never edit an accepted ADR to change its decision —
supersede it with a new one and update the status here.

| # | Decision | Status |
| --- | --- | --- |
| [0001](0001-modular-monolith.md) | Modular monolith on Laravel 13, not microservices | Accepted |
| [0002](0002-multi-client-server-side-authorization.md) | One domain, many clients: authorization is server-side only | Accepted |
| [0003](0003-api-response-conventions.md) | Envelope-based responses, path versioning, clamped pagination | Accepted |
| [0004](0004-deployment-topology-and-provider-responsibilities.md) | Deployment topology: Netlify, Firebase and Linode/Akamai responsibilities | Accepted |
| [0005](0005-cross-origin-authentication.md) | First-party bearer tokens, not Sanctum cookie SPA auth | Accepted |
| [0006](0006-admin-console-authentication.md) | Admin console authenticates with an in-memory bearer token | Accepted |
| [0007](0007-canonical-assistance-lifecycle.md) | One canonical assistance lifecycle, projected per channel | Accepted |
| [0008](0008-database-conventions.md) | PostgreSQL-first relational conventions | Accepted |
| [0009](0009-account-model-and-authentication-factors.md) | Accounts are separate from residents; factors differ by account type | Accepted |
| [0010](0010-kyc-matching-and-resident-canonicity.md) | Deterministic matching with human adjudication; no silent resident creation | Accepted |
| [0011](0011-digital-id-and-qr-verification.md) | Digital ID: feature-flagged, and the QR is a handle, not a record | Accepted |
| [0012](0012-staff-scopes-and-provisioning.md) | Data scopes are enforced server-side, and provisioning cannot escalate | Accepted |
| [0013](0013-canonical-resident-registry-and-account-linking.md) | Registry writes leave history; merge is gated and transactional; an account link is a reviewable record | Accepted |
| [0014](0014-household-family-and-relationship-domain.md) | Household ≠ family; membership is effective-dated; one directed kinship row; the citizen household view is minimised | Accepted |
| [0015](0015-vulnerability-as-explainable-decision-support.md) | Vulnerability is time-bounded observations plus a versioned, itemised score that decides nothing; safeguarding factors are gated, unweighted and omitted without trace | Accepted |
| [0016](0016-welfare-case-engine.md) | One case state machine with legality checked before permission; additive citizen projection; separation of duties per case and actor | Accepted |
| [0017](0017-assistance-intake-and-assessment.md) | One submission path for every channel; drafts expire and are not cases; an assessment recommends but never approves; idempotency gets its first caller | Accepted |
| [0018](0018-programs-and-eligibility-guidance.md) | Programmes are rows, not config; eligibility guidance flags and never decides; the guidance version is pinned to the case | Accepted |
| … | **0019–0044 exist on disk and are not listed here.** The index stopped being updated somewhere around TAB 07; recorded rather than quietly closed, because an index that silently omits half its entries is worse than one that admits the gap. | — |
| [0045](0045-the-barangay-eligibility-fact-is-a-code.md) | The barangay eligibility fact is the published code, never the auto-increment key; criterion values are validated against the directory | Accepted |
| [0046](0046-pushing-to-main-is-authorised.md) | Pushing to `main` is authorised by the owner; Article 9 amended to agree with the admin console's rule rather than contradict it | Accepted |
| [0047](0047-the-second-optimisation-sweep.md) | The second optimisation sweep: four defects found by measuring, none by reading; three of its own exclusion reasons failed on inspection | Accepted |
| [0048](0048-an-exclusion-must-be-a-floor.md) | An exclusion from the query budgets must be a bound the endpoint cannot exceed, never a reading of the current code; coverage 31 -> 36 of 44, and one of the five was an N+1 | Accepted |
| [0049](0049-a-role-was-not-in-force-when-it-was-granted.md) | A role granted during the current second was not in force: the clock was bound without its microseconds, over a column PostgreSQL rounded UP | Accepted |
| [0050](0050-the-suite-runs-on-the-engine-production-uses.md) | `composer check:pg` runs the suite against a throwaway PostgreSQL cluster and Article 7 requires it before a push; six defects came from running it once | Accepted |
| [0051](0051-the-now-binding-audit.md) | Auditing the other 15 `now()` comparisons: none is a defect, because the class needs a database-written column — and the schema now guards that precondition | Accepted |

An ADR is required when a change alters a module boundary, an API convention, an
authentication/authorization model, a persistence source of truth, or a lifecycle state
machine.
