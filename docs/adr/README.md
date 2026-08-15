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

An ADR is required when a change alters a module boundary, an API convention, an
authentication/authorization model, a persistence source of truth, or a lifecycle state
machine.
