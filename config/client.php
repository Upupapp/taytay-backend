<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | App bootstrap (ADR 0032)
    |--------------------------------------------------------------------------
    |
    | What `GET /api/v1/app/bootstrap` tells a client before it knows anything else.
    |
    | THIS FILE MUST NEVER HOLD A SECRET. Everything here is served to an unauthenticated
    | caller — that is the point of the endpoint, since an app that cannot start cannot
    | sign in to be told it should update. Anything added here is public forever.
    | `NoBrowserSecretsTest` enforces it.
    |
    */

    'bootstrap' => [

        /*
        |----------------------------------------------------------------------
        | Minimum supported app versions
        |----------------------------------------------------------------------
        |
        | The version below which a client must update before it may be trusted to
        | render this API's responses correctly.
        |
        | THE SERVER DECIDES, NOT THE APP. A client that decides for itself whether it is
        | too old is exactly the client that will not — a build with a broken update
        | check cannot fix its own update check. Holding it here means an LGU can force
        | an upgrade for a data-handling bug without shipping anything.
        |
        | Compared with a plain version-tuple comparison, so `1.2.10` is newer than
        | `1.2.9`. Empty means "no minimum" — never a hard block by accident.
        |
        */
        'minimum_versions' => [
            'citizen-mobile' => env('MIN_APP_VERSION_CITIZEN_MOBILE', ''),
            'citizen-web' => env('MIN_APP_VERSION_CITIZEN_WEB', ''),
            'admin-console' => env('MIN_APP_VERSION_ADMIN_CONSOLE', ''),
            'verifier-device' => env('MIN_APP_VERSION_VERIFIER_DEVICE', ''),
        ],

        /*
        |----------------------------------------------------------------------
        | Feature flags
        |----------------------------------------------------------------------
        |
        | READ-ONLY HINTS FOR RENDERING, NEVER AUTHORIZATION (Article 3.4). A flag here
        | tells a client whether to draw a tab; it never decides whether the endpoint
        | behind that tab will answer. Every one of these is enforced server-side by the
        | module that owns it, and a client that ignored all of them would gain nothing.
        |
        | Each is derived from the config the owning module already reads, so there is
        | one source per flag and this endpoint cannot claim a feature is on while the
        | module refuses it.
        |
        */
        'features' => [
            'digital_id' => 'credential.enabled',
            'newsfeed_public' => 'newsfeed.public_access',
            'newsfeed_comments' => 'newsfeed.comments_enabled',
            'push_notifications' => 'notification.fcm.enabled',
        ],

        /*
        |----------------------------------------------------------------------
        | Support contact
        |----------------------------------------------------------------------
        |
        | Shown by a client that cannot reach anything else. A citizen holding a request
        | id and no way to quote it to anybody has been given a correlation id and no
        | correlation.
        |
        */
        'support' => [
            'email' => env('SUPPORT_EMAIL', ''),
            'phone' => env('SUPPORT_PHONE', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response cache directives (ADR 0032 §4)
    |--------------------------------------------------------------------------
    |
    | How long a PUBLIC read may be cached by a browser or a CDN.
    |
    | Authenticated responses are never cached — that is not configurable, because a
    | shared cache holding one resident's welfare file and serving it to the next caller
    | is the failure this whole system exists to avoid, and a number in a config file is
    | a number somebody can raise.
    |
    */

    'public_cache_seconds' => (int) env('PUBLIC_CACHE_SECONDS', 60),

];
