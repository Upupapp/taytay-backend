# ADR 0038 — API contract, versioning and client types

* **Status:** accepted
* **Date:** 2026-08-18
* **Built in:** TAB 33
* **Closes:** gap G-44
* **Related:** ADR 0003 (error envelope), ADR 0032 (citizen surface), Article 4 (conventions)

---

## Context

The API had run for thirty-two TABs with no machine-readable specification. What existed was the
contract matrix — prose, but *checked* prose: `ContractMatrixTest` compares it to the router in both
directions, so a row without a route and a route without a row both fail the build.

That is more than most projects have, and it is not enough for the acceptance criterion here.
*"Frontend developers can build without reading backend code"* needs schemas, enum values and error
shapes, and a markdown table does not carry them.

---

## 1. The document is generated, never written

**A hand-written specification is a second description of the system, and the two drift starting
the day after the first is written.** Not through carelessness — the change that invalidates the
document is a change to a *response shape*, and nothing about editing a projection method suggests
opening a YAML file.

Six months later it is confidently wrong, which is **worse than absent**: a frontend developer
builds against it and discovers the divergence at integration, having written code around a field
that does not exist.

So everything that can come from the code does: paths and methods from the router, path parameters
from the URI, auth and audience from the middleware and `CitizenSurface`, error responses from
`ErrorCode` — and **enums from the PHP enums themselves.**

That last one is what makes *"documented enums match actual backend output"* a property rather than
a promise. The document cannot describe a status the backend does not have, because both read the
same `cases()`.

Enums are discovered from the filesystem rather than listed, so one added next year is documented
without anybody remembering — the same reasoning as the queued-job scan in ADR 0036 §5.

### The bug this caught in its own generator

The first version detected authentication by matching the resolved middleware class name, while
`gatherMiddleware()` returns the alias (`auth:sanctum`). Every authenticated endpoint in the API was
tagged `public` and carried no security scheme.

The document looked complete and was **confidently wrong about the single most important thing a
client needs to know.** `ApiContractTest::no_authenticated_route_is_documented_as_public` exists
because of it.

---

## 2. Prose lives in one small file

What code cannot know is what an endpoint is *for*. `docs/api/annotations.php` holds that, keyed by
route name.

Kept deliberately small. A large annotation file has the same drift problem as a hand-written spec;
a small one whose entries are all about *intent* stays true even when a projection changes shape.

**An endpoint with no entry still appears**, with an inferred summary. A missing annotation degrades
the document rather than omitting the endpoint, so nothing can go undocumented by being forgotten.

---

## 3. TypeScript is enums and the envelope, not an SDK

The master command says to generate TypeScript where useful but not to overwrite frontend
architecture blindly. The line between those is exactly here.

A generated SDK arrives with opinions about HTTP clients, error handling, retries and state
management — four decisions belonging to two Angular applications and a Flutter one that have
already made them. It would be regenerated over, argued with, and eventually deleted.

A file of enums and envelope types is the part a frontend developer would otherwise **retype by
hand from prose**, getting one value wrong and finding out in production. One source of truth for
enums, which is what the master command actually asks for.

---

## 4. The committed document is what enforces the versioning policy

Both artefacts are **committed**, and `ApiContractTest` fails the build when either is stale.

That is the mechanism behind *"breaking response changes require a version or deprecation
decision"*. There is no way to make a checker understand whether a change is breaking — that is a
judgement. What a checker *can* do is guarantee the judgement gets made: a change to a response
shape produces a **diff in `openapi.json`, in the same commit**, where a reviewer sees it and is
prompted to write the changelog entry.

Committing also means a frontend developer reads the contract from the repository without booting
PHP.

### The policy itself

Recorded in `CHANGELOG_API.md`. Two parts worth restating:

**`v1` does not disappear when `v2` arrives.** The mobile app is on somebody's phone and may not be
updated for months. A version switched off because the server moved on is a resident who cannot use
their ID.

**A new enum value is additive but not free.** A client with an exhaustive `switch` falls through
the day one is added, so new values are announced and clients are told to treat enums as open — in
the generated file itself, where somebody writing the switch will see it.

---

## 5. What the contract test found

`ApiContractTest::every_enum_value_a_client_can_observe_is_documented` compares **real API
responses** to the document, not the enum classes to themselves — the latter would prove only that
the generator is deterministic.

It found `single`, from `civil_status`: a bare validation string
`in:single,married,widowed,separated,annulled,cohabiting`, written out in **three** places and
invisible to clients. A frontend developer would have had to read backend code to learn the
vocabulary, which is precisely what this TAB exists to make unnecessary — and three copies of a
list drift into a value one endpoint accepts and another refuses, on the same field of the same
record.

Now `ResidentProfile\Contracts\CivilStatus`, with `rule()` as the single source. No value changed.

The scan's heuristic is deliberately narrow — only fields whose *name* says they hold a state
(`*_status`, `*_type`, `*_priority`…). Scanning every string would find category slugs, barangay
codes and venue names, and the resulting noise is how a detector gets switched off.

---

## Consequences

* Adding an endpoint regenerates the document. Forgetting fails the build.
* Adding an enum to `Domain/` or `Contracts/` documents it automatically. Adding a bare string
  vocabulary does not — and the contract test will find it the moment it appears in a response.
* `docs/api/openapi.json` is 221 paths of generated JSON in the repository. It is large and it is
  reviewed by diff, which is the point.
* There is still no `/api/v2`, and the policy for creating one now exists before it is needed.
