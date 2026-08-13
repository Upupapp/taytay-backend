<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         | World-readable by URL. Nothing derived from a citizen's record — no document,
         | no photo, no ID artifact — may ever be written here. Use `object-storage`.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         | Akamai (Linode) Object Storage — the S3-compatible production store (ADR 0004).
         |
         | Citizen documents, ID card artifacts and case attachments live here. The disk
         | is PRIVATE: nothing it holds may ever be world-readable by URL. Objects are
         | delivered either by an authorization-gated streaming endpoint or by a
         | short-lived signed URL issued after a server-side authorization decision —
         | never by handing a client a permanent link (CLAUDE.md Article 5.3).
         |
         | `throw` is on: a silent write failure on a citizen's uploaded document would
         | otherwise surface much later as missing evidence in a welfare case.
         */
        'object-storage' => [
            'driver' => 's3',
            'key' => env('OBJECT_STORAGE_KEY'),
            'secret' => env('OBJECT_STORAGE_SECRET'),
            'region' => env('OBJECT_STORAGE_REGION'),
            'bucket' => env('OBJECT_STORAGE_BUCKET'),
            'endpoint' => env('OBJECT_STORAGE_ENDPOINT'),
            'use_path_style_endpoint' => env('OBJECT_STORAGE_PATH_STYLE', false),
            // No 'url': a public base URL is what turns a private store into a leak.
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
