# Frontend Contract Audit (TAB 02)

Produced by auditing the **Angular staff console**
(`Desktop\Taytay_Rizal_Social_Welfare_Angular`, read-only) and the two Flutter
citizen clients against this backend, before any casework endpoint is built.

| Document | Answers |
| --- | --- |
| [`frontend-endpoint-matrix.md`](frontend-endpoint-matrix.md) | For every screen, which endpoint serves it — with version, auth, permission, scope, request, response and build status. |
| [`client-visibility-matrix.md`](client-visibility-matrix.md) | For every field, which channel may see it. The internal-field exclusion list is here. |
| [`frontend-backend-gap-list.md`](frontend-backend-gap-list.md) | Where the frontend mocks and the backend disagree, and what has no persistence yet. |

Decisions taken while auditing:
[ADR 0006](../adr/0006-admin-console-authentication.md) (admin authentication),
[ADR 0007](../adr/0007-canonical-assistance-lifecycle.md) (one lifecycle, per-channel
projection).

## Why this exists

Three clients are being built against a backend that does not have these endpoints yet.
Left alone, each would invent its own contract — and two of them already have, in
incompatible ways (gap list G-01 and G-05). Writing the contract down first is cheaper
than reconciling three implementations later.

## The rule the whole audit turns on

> Clients differ in **what they are allowed to see**, never in the envelope, the
> lifecycle, or where a rule lives.

Citizen web and citizen mobile are two deliveries of the *same* services. There is no
"mobile endpoint" and no "web business rule" — a behaviour that appears in only one place
is still one service, authorized per actor (CLAUDE.md Article 3.1, ADR 0002).

## Status vocabulary

Every row in the endpoint matrix carries one:

| Status | Meaning |
| --- | --- |
| `implemented` | The route exists in this repository today and is under test. |
| `planned` | Contract agreed here; the endpoint is built in a later TAB. |
| `mock-only` | Deliberately never a backend endpoint. The reason is stated inline. |

`tests/Architecture/ContractMatrixTest.php` keeps this honest: every `implemented` row
must resolve to a registered route, every registered `/api/v1` route must appear in the
matrix, and every admin screen must have a row or an explicit `mock-only` exception.
