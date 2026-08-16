<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where uploaded documents are stored
    |--------------------------------------------------------------------------
    |
    | Private object storage everywhere except a developer's machine (Article 8.5).
    |
    | Read from configuration rather than branched on the environment name, so a staging box
    | cannot silently behave like a laptop. There is no value of this setting that writes to
    | the `public` disk, and there must not be: nothing citizen-derived may be written there.
    |
    */

    'disk' => env('FILES_DISK', 'object-storage'),

    /*
    |--------------------------------------------------------------------------
    | Where PUBLISHED media renditions are stored
    |--------------------------------------------------------------------------
    |
    | A SEPARATE BUCKET WITH SEPARATE CREDENTIALS (ADR 0033 §4). Not a public prefix inside the
    | private bucket, which is the arrangement that looks equivalent and is not: one misapplied
    | policy on a shared bucket exposes everything in it, and the blast radius of a mistake is
    | the whole store rather than the derived images.
    |
    | Nothing is ever WRITTEN here except a re-encoded rendition produced by `MediaPublisher`
    | after the owning module publishes its content. No uploaded file reaches this disk — not
    | copied, not moved, not temporarily. `NoSensitiveMediaInPublicBucketTest` enforces it.
    |
    */

    'public_disk' => env('FILES_PUBLIC_DISK', 'public-media'),

    /*
    |--------------------------------------------------------------------------
    | Malware scanner
    |--------------------------------------------------------------------------
    |
    | The class resolved to scan an uploaded file, exposing `scan(string $path): bool`.
    |
    | Null means no scanner is configured, and uploads settle at `skipped` — which is
    | deliberately NOT `clean`. An unscanned file is served to staff, who already carry the risk
    | of the upload they accepted, and is refused for any outward share, which would pass that
    | risk to somebody else.
    |
    | Turning scanning on is a configuration change: the state machine, the queue, the failure
    | path and the download consequences are all already wired (gap G-25).
    |
    */

    'scanner' => env('FILES_SCANNER'),

];
