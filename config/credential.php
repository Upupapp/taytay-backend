<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Digital ID
    |--------------------------------------------------------------------------
    |
    | OFF BY DEFAULT (ADR 0011). A digital ID is optional to the service: every resident
    | must be able to receive assistance without one, so the routes exist but report
    | 404 until an LGU decision turns them on. Shipping it dark also means the schema and
    | contracts can be reviewed before anybody depends on them.
    |
    */

    'digital_id' => [
        'enabled' => (bool) env('DIGITAL_ID_ENABLED', false),
        'validity_days' => (int) env('DIGITAL_ID_VALIDITY_DAYS', 365 * 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | QR payloads
    |--------------------------------------------------------------------------
    |
    | Short-lived and single-use. A QR code is photographed, screenshotted and left on
    | counters, so it carries a handle rather than data, dies on its own within seconds,
    | and its nonce can be spent only once.
    |
    | 90 seconds is long enough for a person to hold up a phone at a counter and short
    | enough that a photograph taken over their shoulder is worthless before it can be used.
    |
    | KEYS ARE SECRETS. They come from the environment and are never committed, logged or
    | returned. `keys` is a map of id => secret so a key can be rotated: new payloads use
    | `active_key_id`, and payloads sealed by an older id keep verifying until they expire.
    |
    */

    'qr' => [
        'ttl_seconds' => (int) env('DIGITAL_ID_QR_TTL', 90),
        'active_key_id' => env('DIGITAL_ID_QR_KEY_ID', 'local'),
        'keys' => [
            // Local development only. A deployed environment supplies its own.
            'local' => env('DIGITAL_ID_QR_KEY', 'local-development-qr-signing-key-not-a-secret'),
        ],
    ],

];
