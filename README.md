# Taytay Rizal LGU IDS — Backend

Backend-only REST/JSON API for the Taytay, Rizal LGU Identity & Services platform.

**Read [`CLAUDE.md`](CLAUDE.md) first** — it is the constitution for this repository and
outranks any other instruction here.

## What this is (and is not)

This repository is the system of record and the only place business logic lives. It serves
four independent client applications, each in its own repository:

| Channel (`X-Client-Channel`) | Client |
| --- | --- |
| `citizen-web` | Citizen web portal |
| `citizen-mobile` | Flutter app (`lgu_ids_taytay`) |
| `admin-console` | LGU staff/admin console |
| `verifier-device` | QR/credential verification device |

**No frontend code lives here** — no Blade, no bundler, no `package.json`. This is
enforced by `tests/Architecture/NoFrontendCodeTest.php`, not by convention.

## Requirements

* PHP 8.3+ (with `pdo_sqlite` for the test suite)
* Composer 2
* PostgreSQL or MySQL for real environments; SQLite is used for local dev and tests

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Verify the boot:

```bash
curl http://127.0.0.1:8000/api/v1/health
```

## Checks

```bash
composer test    # php artisan test  (Architecture + Unit + Feature)
composer lint    # vendor/bin/pint --test
composer check   # lint, then test
```

The `Architecture` suite runs first: if a module boundary or the backend-only rule is
broken, the rest of the result is noise.

## Layout

```
app/                 framework wiring only — no business logic
modules/<Module>/    domain modules, autoloaded as Modules\<Module>\
  Domain/            entities, value objects, rules        (module-private)
  Application/       use cases — the entry point for others (public)
  Contracts/         published vocabulary                   (public)
  Infrastructure/    persistence and adapters              (module-private)
  Http/              thin controllers and resources        (module-private)
  Providers/         bindings                              (module-private)
  Routes/api_v1.php  mounted under /api/v1 by routes/api.php
config/modules.php   the module registry — unlisted modules do not load
```

## Documentation

| Document | Purpose |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | The constitution — highest authority |
| [`docs/architecture/domain-boundary-map.md`](docs/architecture/domain-boundary-map.md) | Who owns which fact, and allowed dependencies |
| [`docs/architecture/deployment-topology.md`](docs/architecture/deployment-topology.md) | Netlify / Firebase / Linode responsibilities and environments |
| [`docs/api/conventions.md`](docs/api/conventions.md) | Envelope, errors, pagination, types |
| [`docs/contracts/`](docs/contracts/README.md) | Frontend endpoint matrix, client visibility matrix, gap list |
| [`docs/adr/`](docs/adr/README.md) | Architecture decision records |

## Three rules worth repeating

1. **Authorization is server-side, always.** A role, permission list or `is_admin` flag
   arriving from a client is untrusted input. The `/api/v1/admin/...` prefix grants
   nothing. A hidden button is not access control.
2. **The client channel is telemetry, never authority.** `X-Client-Channel` is recorded
   for audit and may set a default page size. It can never widen what a caller may see.
3. **This backend is the only authority.** Netlify delivers the browser portals and
   Firebase carries push; neither is a backend. No secrets on Netlify, no Firebase Auth or
   Firestore, no personal data in a push or analytics payload.

All three are enforced by tests, not by review — see `tests/Feature/Api/V1/` and
`tests/Architecture/`.

## Deployed environments

Set these per environment or the deployment is subtly wrong (details in
[`deployment-topology.md`](docs/architecture/deployment-topology.md)):
`TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, `DB_CONNECTION=pgsql` + `DB_SSLMODE=require`,
`OBJECT_STORAGE_*`, `FCM_*`, `APP_DEBUG=false`.
