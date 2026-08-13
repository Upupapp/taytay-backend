<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Laravel's default is `allowed_origins => ['*']`. That is deliberately overridden
    | here: this API holds Philippine personal data (RA 10173) and serves a known, small
    | set of first-party browser clients, so the origin list is an allow-list rather than
    | a wildcard (CLAUDE.md Article 5.3).
    |
    | Deny by default — with no CORS_ALLOWED_ORIGINS set, no cross-origin browser request
    | is permitted. Set it to the citizen portal and admin console origins, comma
    | separated, per environment:
    |
    |     CORS_ALLOWED_ORIGINS=https://portal.taytayrizal.gov.ph,https://admin.taytayrizal.gov.ph
    |
    | The Flutter app and verifier devices are not browsers and are unaffected by CORS;
    | they authenticate with a bearer token and need nothing here.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Client-Channel',
        'X-Request-Id',
        'X-Requested-With',
    ],

    /*
     | Exposed so a browser client can read the correlation id and show it to a citizen
     | contacting a support desk (docs/api/conventions.md §2).
     */
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    /*
     | Token-based authentication needs no cookies. Enabling this alongside a broad origin
     | list is the classic CORS mistake, so it stays off unless the citizen web portal
     | adopts Sanctum's cookie mode — which is an Identity decision requiring an ADR.
     */
    'supports_credentials' => false,

];
