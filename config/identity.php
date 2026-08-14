<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Token lifetimes
    |--------------------------------------------------------------------------
    |
    | Minutes. Staff tokens are deliberately short: ADR 0006 holds the admin console's
    | token in memory only, so a reload re-authenticates anyway, and a stolen token has a
    | small window. Mobile tokens are long because a citizen re-authenticating every day
    | on a phone is how people stop using a public service — the mitigation there is
    | revocation (a lost phone is revoked from another device), not expiry.
    |
    */

    'token_ttl_minutes' => [
        'staff' => (int) env('IDENTITY_STAFF_TOKEN_TTL', 12 * 60),
        'citizen' => (int) env('IDENTITY_CITIZEN_TOKEN_TTL', 30 * 24 * 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time codes
    |--------------------------------------------------------------------------
    |
    | Six digits is the usable length for an SMS code. Its weakness is guessing, which is
    | answered by a short expiry, a hard attempt cap that burns the code, and per-account
    | and per-IP rate limits — not by making the code longer than a person will type.
    |
    */

    'one_time_code' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    |
    | A high-entropy URL token, single use, short lived. Successful reset revokes every
    | existing token: if the reset was the attacker, the real holder's sessions die; if it
    | was the holder, an attacker's stolen session dies. Either way the ambiguity ends.
    |
    */

    'password_reset' => [
        'ttl_minutes' => 30,
        'token_bytes' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lockout
    |--------------------------------------------------------------------------
    |
    | Applied per account after repeated failures. Deliberately a lockout with a timer
    | rather than a permanent block, because a permanent block is a denial-of-service
    | primitive against any staff member whose email is known.
    |
    */

    'lockout' => [
        'max_failed_attempts' => 8,
        'minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (attempts per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'sign_in' => 10,
        'code_request' => 3,
        'code_verify' => 10,
        'password_reset_request' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-factor authentication
    |--------------------------------------------------------------------------
    |
    | TOTP per RFC 6238. One time step of leeway either side absorbs clock drift; more
    | would widen the replay window for no usability gain. Accepted codes record their
    | time step so the same code cannot be used twice inside its own validity window.
    |
    */

    'mfa' => [
        'issuer' => env('IDENTITY_MFA_ISSUER', 'Taytay LGU IDS'),
        'window' => 1,
        'challenge_ttl_minutes' => 5,
        'recovery_code_count' => 8,
    ],

];
