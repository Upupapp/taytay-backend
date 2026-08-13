<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API identity
    |--------------------------------------------------------------------------
    |
    | Surfaced by GET /api/v1/health. Deliberately free of environment names,
    | dependency versions and configuration: a public health endpoint must not become
    | a reconnaissance surface (docs/api/conventions.md §9).
    |
    */

    'service_name' => 'taytay-lguids-backend',

    'version' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Actor guard
    |--------------------------------------------------------------------------
    |
    | The auth guard AccessControl reads when building the ActorContext. It is named
    | explicitly because public routes carry no auth middleware to set a default guard,
    | yet still need to know who is asking (ADR 0002).
    |
    | Token-based for every channel — mobile, verifier devices and the admin console all
    | present a bearer token. Whether the citizen web portal instead uses Sanctum's
    | cookie/session mode is an Identity decision, deferred to that module's TAB.
    |
    */

    'actor_guard' => 'sanctum',

    /*
    |--------------------------------------------------------------------------
    | Pagination limits
    |--------------------------------------------------------------------------
    |
    | Documented in docs/api/conventions.md §5. Out-of-range client values are clamped
    | to this range rather than rejected.
    |
    */

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

];
