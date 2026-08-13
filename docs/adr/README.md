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

An ADR is required when a change alters a module boundary, an API convention, an
authentication/authorization model, a persistence source of truth, or a lifecycle state
machine.
