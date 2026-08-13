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
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | The API runs behind Nginx, and behind a NodeBalancer once there is more than one
    | node (ADR 0004). Without trusted proxies Laravel reads the proxy's address as the
    | client IP and believes every request is plain HTTP, which silently breaks rate
    | limiting (every caller shares one key), signed URLs (wrong scheme) and audit trails
    | (every action attributed to the load balancer).
    |
    | Deny by default: empty means no proxy is trusted, so a misconfigured deployment
    | cannot let a caller spoof X-Forwarded-For. Set it per environment to the
    | private-network CIDR or NodeBalancer address; prefer that over "*".
    |
    | This lives in config rather than bootstrap/app.php because the middleware closure
    | there runs before the .env file is loaded, where env() reads null.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

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
