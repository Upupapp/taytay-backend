<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (push transport for the Flutter app)
    |--------------------------------------------------------------------------
    |
    | FCM is a TRANSPORT, not an authority (ADR 0004). Laravel decides that a
    | notification is warranted, who may receive it and what it may contain; FCM only
    | carries it to the device. Firebase Auth, Firestore, Realtime Database and Firebase
    | Storage are NOT used — adding any of them as a parallel authority or store
    | requires its own ADR.
    |
    | Laravel calls FCM HTTP v1 with short-lived OAuth credentials derived from a service
    | account. Only the PATH to that service-account file lives here; its contents are
    | secret, are never committed, never logged and never echoed (CLAUDE.md Article 5.6).
    |
    | Staging and production use separate Firebase projects and separate credentials.
    |
    | Message payloads must carry identifiers and a notification type only — never a case
    | narrative, government identifier, address or any other personal data, because a
    | push payload traverses a third party and is visible on the device lock screen.
    |
    | Wiring lives in the Notification module when that module is built; this entry is the
    | configuration seam only.
    |
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
    ],

];
