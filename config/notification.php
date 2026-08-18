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
    | Transactional delivery
    |--------------------------------------------------------------------------
    |
    | How a one-time code reaches a person. Nothing on this path is persisted: the text is a
    | credential, and a credential in a notifications table is a secret in an inbox.
    |
    | `null` is the default and the current truth — this platform has no SMS provider, so a
    | sign-in code is issued, recorded and skipped, and that shows in the audit trail rather
    | than in the API response, which must not become an account-existence oracle.
    |
    | `log` writes the code to the log so sign-in can be exercised end to end. It refuses to
    | construct outside local and testing.
    |
    */

    'transactional' => [
        'sender' => env('TRANSACTIONAL_SENDER', 'null'),
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
