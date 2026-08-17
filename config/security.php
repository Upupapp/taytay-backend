<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | OFF BY DEFAULT, and this is the one security header where that is the safe direction.
    |
    | HSTS is hard to take back. A browser that has seen it refuses plain HTTP to the domain for
    | the whole `max-age`, so sending it from a host whose certificate is not yet right locks
    | people out of the API with no server-side fix — and `includeSubDomains` from a staging box
    | on a shared parent domain poisons production along with it.
    |
    | The master command says "HSTS only after domain readiness". Turning it on is therefore a
    | deployment decision made once the custom domains and certificates are confirmed, and the
    | middleware additionally refuses to send it over a non-HTTPS request (ADR 0035 §3).
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', false),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | ONE TABLE, because a limit nobody can find is a limit nobody tunes. Before this these
    | numbers lived in three provider files and a literal, and the three surfaces the master
    | command names most explicitly — KYC submission, search and exports — had no limit at all.
    |
    | Every limit is keyed by ACCOUNT where there is one, falling back to IP only for the
    | unauthenticated surfaces. A household behind one connection is several legitimate residents,
    | and a barangay hall's wifi is dozens; keying by IP alone would throttle a queue of people at
    | a counter as though they were one abuser (ADR 0035 §2).
    |
    | Values are per minute unless the name says otherwise.
    |
    */

    'rate_limits' => [

        /*
         | ── unauthenticated surfaces ─────────────────────────────────────────────────
         |
         | Keyed by IP *and* by a hash of the identifier being tried, so neither dimension alone
         | is enough: an attacker rotating IPs still hits the per-identifier limit, and one
         | hammering a single account from one address hits both.
         */

        // Sign-in and code verification. Deliberately tight — this is the credential-stuffing
        // surface, and a legitimate person does not sign in ten times a minute.
        'sign_in' => (int) env('RATE_LIMIT_SIGN_IN', 10),

        // Requesting an OTP or a reset link. Tighter still: each one costs the LGU an SMS, and an
        // unthrottled endpoint is both an account-enumeration oracle and a way to run up a bill.
        'code_request' => (int) env('RATE_LIMIT_CODE_REQUEST', 5),

        // Account registration.
        'registration' => (int) env('RATE_LIMIT_REGISTRATION', 5),

        /*
         | ── authenticated surfaces ───────────────────────────────────────────────────
         */

        /*
         | KYC submission. Named by the master command and previously unlimited.
         |
         | Low on purpose: each submission puts a case in front of a human reviewer, so an
         | unthrottled endpoint is a denial-of-service attack on the office's attention rather
         | than on the server.
         */
        'kyc_submission' => (int) env('RATE_LIMIT_KYC', 5),

        // Comments, reactions and shares. A comment box on a municipal feed is the cheapest way
        // to flood a system with text somebody then has to read.
        'engagement' => (int) env('RATE_LIMIT_ENGAGEMENT', 20),

        // Event registration and withdrawal. A register/withdraw loop is the cheapest way to
        // churn a waitlist and make the promotion job announce a seat to a different person
        // every few seconds.
        'event_registration' => (int) env('RATE_LIMIT_EVENT_REGISTRATION', 20),

        /*
         | Search. Named by the master command and previously unlimited.
         |
         | Search is the endpoint an attacker uses to enumerate: it is designed to answer partial
         | questions about many records at once, which is exactly what an enumeration attempt
         | wants. Every searcher is already scoped and authorized, so this limits the *rate* of
         | legitimate-looking questions rather than the answers.
         */
        'search' => (int) env('RATE_LIMIT_SEARCH', 30),

        /*
         | Export requests. Named by the master command and previously unlimited.
         |
         | The tightest authenticated limit in the table, per HOUR rather than per minute. An
         | export is a copy of the database leaving this application's control (ADR 0026 §3); ten
         | an hour is generous for a person doing their job and useless to somebody exfiltrating.
         */
        'export_per_hour' => (int) env('RATE_LIMIT_EXPORT_PER_HOUR', 10),

        /*
         | The global backstop for everything else. Generous, because it exists to stop a runaway
         | client rather than to shape legitimate use — the specific limits above are where the
         | real decisions are.
         */
        'default' => (int) env('RATE_LIMIT_DEFAULT', 120),
    ],

];
