# ADR 0001 — Modular monolith on Laravel 13, not microservices

* Status: **Accepted**
* Date: 2026-08-13
* Deciders: backend architecture (TAB 01)

## Context

The Taytay LGU IDS backend must serve four independent client channels (citizen web,
citizen mobile, admin console, verifier device) across domains that are genuinely
distinct — identity, resident profile, ID credential lifecycle, verification, service
delivery, notification, audit — with strict privacy and authorization requirements.

The system is operated by a single LGU IT function. There is one deployment target, one
on-call rotation, and no evidence of independent scaling needs per domain. The dominant
risk is **coupling and accidental data exposure across domains**, not throughput.

## Decision

Build a **modular monolith**: one Laravel 13 application, one deployable, with hard
internal boundaries.

* Domain code lives in `modules/<Module>/`, PSR-4 as `Modules\<Module>\`, registered
  through `config/modules.php`. `app/` holds framework wiring only.
* Each module is layered `Domain / Application / Infrastructure / Http`.
* Cross-module access is only through the owner's `Application/` layer. Reaching into
  another module's `Domain/` or `Infrastructure/` is a build failure
  (`tests/Architecture/ModuleBoundaryTest.php`).
* No cross-module Eloquent relations or joins; reference by identifier.
* Each fact has exactly one owning module and one owning table
  (`docs/architecture/domain-boundary-map.md`).

## Rationale

1. **Boundaries are the valuable part; separate processes are the expensive part.** The
   correctness risk here (a staff account reading a resident it should not) is solved by
   authorization and module boundaries, not by network separation.
2. **A distributed system would add failure modes an LGU IT team should not have to
   operate** — partial failure, distributed transactions, cross-service auth, versioned
   inter-service contracts — for no current benefit.
3. **Reversibility.** Because modules already communicate through application services
   and never share tables, extracting one later (most plausibly `Verification`, which may
   need edge deployment) is a contained change. Choosing microservices now would be far
   harder to reverse.
4. **Laravel-native.** Service providers, container bindings and per-module route
   registration give module isolation without a framework fight or a third-party module
   package.

## Consequences

* Positive: single migration path, single test run, single auth model, atomic
  transactions, straightforward local development.
* Positive: boundary violations are caught by an automated test rather than by review.
* Negative: nothing physically prevents a developer from calling across a boundary — the
  architecture test is the guard, and it must be kept passing and kept honest.
* Negative: all modules scale together. Accepted; revisit only with measured evidence.
* A module extraction, or any change to the boundary map, requires a new ADR.

## Alternatives rejected

* **Default flat Laravel (`app/Models`, `app/Http/Controllers`).** Rejected: with ~9
  domains and strict privacy rules, a flat structure reliably drifts into fat models and
  cross-domain queries, which is exactly the failure this system cannot afford.
* **Microservices per domain.** Rejected: operational cost and distributed-systems risk
  far exceed the benefit at this scale.
* **A third-party module package (e.g. `nwidart/laravel-modules`).** Rejected: adds a
  dependency and its own conventions to do what ~100 lines of service provider and a
  config registry do here, and it makes the boundary rules implicit rather than
  explicit/testable.

## Sources

* Laravel 13 service container, service providers and package/route registration —
  <https://laravel.com/docs/13.x>
* Modular monolith / "don't start with microservices" — Fowler, *MonolithFirst*
  <https://martinfowler.com/bliki/MonolithFirst.html>
