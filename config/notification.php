<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | Firebase is TRANSPORT, NOT AUTHORITY (Article 8.3). Laravel decides that a notification is
    | warranted, who may receive it and what it may say; FCM carries bytes.
    |
    | The service-account JSON is referenced by a server-side PATH and never committed, never
    | placed in a Netlify build variable, and never shipped to the mobile app. Separate Firebase
    | projects per environment, so a staging device token cannot receive production notices.
    |
    | Absent configuration is not an error: the push channel reports `skipped` and everything else
    | still works, which is what keeps "provider outages do not block core transactions" true even
    | when the provider does not exist yet.
    |
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels attempted by default
    |--------------------------------------------------------------------------
    |
    | `database` is always included by the Notifier regardless of preference: switching off email
    | means "stop emailing me", not "stop keeping a record of what you told me".
    |
    */

    'default_channels' => ['database', 'push'],

];
