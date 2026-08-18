<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OpenAPI annotations
|--------------------------------------------------------------------------
|
| The prose half of the API document, keyed by route name (ADR 0038 §2).
|
| EVERYTHING THAT CAN COME FROM THE CODE DOES — paths, methods, path parameters, auth, audience,
| enums, error responses. This file holds only what code cannot know: what an endpoint is *for*,
| and what a client should expect back.
|
| Kept small on purpose. A large annotation file is a second description of the system with the
| same drift problem as a hand-written spec; a small one whose entries are all about *intent* stays
| true even when a projection changes shape.
|
| An endpoint with no entry here still appears in the document, with an inferred summary. That is
| deliberate: a missing annotation degrades the document rather than omitting the endpoint, so
| nothing can be undocumented by being forgotten.
|
| The examples use FICTIONAL Taytay data, per the master command. No real resident, no real case,
| no real number.
|
*/

return [

    // ── account and session ───────────────────────────────────────────────────────────
    'v1.me.show' => [
        'summary' => 'The signed-in account',
        'description' => 'Who the caller is, and what the server has resolved them to be allowed to do. '
            ."The `permissions` and `roles` here are the server's own answer — mirror them to decide "
            .'what to render, and never send them back as a claim (Article 3.4).',
        'returns' => 'The account, its verification state, and the effective permission list.',
        'permission' => null,
    ],
    'v1.auth.tokens.store' => [
        'summary' => 'Sign in with a password (staff)',
        'description' => 'Answers identically for a wrong password and an account that does not exist. '
            .'A difference would turn this into a directory of who is registered with this LGU.',
        'status' => '200',
        'returns' => 'A token, or an MFA challenge when a second factor is configured.',
    ],
    'v1.auth.otp.request' => [
        'summary' => 'Request a sign-in code (citizen)',
        'description' => 'Always answers the same way, whether or not the number is registered.',
        'status' => '202',
    ],

    // ── resident and household ────────────────────────────────────────────────────────
    'v1.me.profile.show' => [
        'summary' => 'My resident record',
        'returns' => 'The caller’s own resident record, resolved from the token. There is no identifier in this contract.',
    ],
    'v1.me.household.show' => [
        'summary' => 'My household',
        'returns' => 'The household this resident belongs to, and its members as a safe summary — names and relationships, never other members’ documents or case history.',
    ],
    'v1.admin.residents.show' => [
        'summary' => 'A resident record',
        'description' => 'Scoped by barangay as well as by permission. A resident outside the caller’s scope answers `404`, not `403` — a `403` would confirm the identifier names a real person.',
        'permission' => 'resident.view',
    ],

    // ── assistance and cases ──────────────────────────────────────────────────────────
    'v1.me.cases.index' => [
        'summary' => 'My requests',
        'paginated' => true,
        'returns' => 'The caller’s own welfare cases, with the citizen-facing status message — never the caseworker’s internal reason.',
    ],
    'v1.me.cases.show' => [
        'summary' => 'One of my requests',
        'returns' => 'The case as the applicant sees it: status, timeline of material movements, and requirements outstanding.',
    ],
    'v1.me.assistance.drafts.submit' => [
        'summary' => 'Submit a draft request',
        'description' => 'Accepts `Idempotency-Key`. A retry on a weak connection must not open a second case for one household.',
        'returns' => 'The intake record, and the case it opened.',
    ],
    'v1.admin.assistance-requests.transitions' => [
        'summary' => 'Move a case through its lifecycle',
        'description' => 'The permission is resolved from the **target** state, so endorse and approve can be held by different people (ADR 0016 §6). An illegal transition answers `409` before authorization is consulted.',
        'permission' => 'from the target state',
    ],

    // ── programmes and catalogue ──────────────────────────────────────────────────────
    'v1.programs.index' => [
        'summary' => 'Assistance programmes',
        'paginated' => true,
        'description' => 'Public. Narrows to published-and-citizen-visible for a caller without `program.view` — filtered at the query, so an internal referral programme is absent from the rows **and** from the pagination total.',
    ],
    'v1.services.index' => [
        'summary' => 'Service catalogue',
        'description' => 'Public. Unpublished entries are excluded by the permission check inside the query, not by the route being public.',
    ],

    // ── files ─────────────────────────────────────────────────────────────────────────
    'v1.documents.download' => [
        'summary' => 'Open a document',
        'description' => 'A single-use, short-lived grant redeemed for the bytes. There is no durable URL to a private object anywhere in this API.',
        'returns' => 'The file stream, with `Content-Disposition` and `nosniff`.',
    ],
    'v1.me.cases.requirements.documents.store' => [
        'summary' => 'Upload a document against a requirement',
        'description' => 'The type is read from the file’s leading bytes; the declared `Content-Type` and the extension are both caller-supplied and both look correct on a file that is neither. Size limits are per classification.',
    ],

    // ── newsfeed and comments ─────────────────────────────────────────────────────────
    'v1.newsfeed.index' => [
        'summary' => 'The municipal feed',
        'paginated' => true,
        'description' => 'Published posts matching the reader’s barangay. A draft is **absent**, not filtered — the lookup runs against a query that already excludes it.',
    ],
    'v1.newsfeed.comments.index' => [
        'summary' => 'A post’s comments',
        'paginated' => true,
        'description' => 'Visible comments only. Hidden and removed ones are absent, and **no moderation field appears at all** — a count including hidden comments would be a moderation log by arithmetic.',
    ],
    'v1.newsfeed.comments.store' => [
        'summary' => 'Comment on a post',
        'description' => '`is_official` is set by the **server** from the author’s permission and is never accepted from the request. One level of reply.',
    ],

    // ── events and registration ───────────────────────────────────────────────────────
    'v1.events.index' => [
        'summary' => 'Upcoming events',
        'paginated' => true,
        'description' => 'Public. Cancelled and completed events stay listed — somebody arranged their day around them. Only draft and archived are invisible.',
    ],
    'v1.events.show' => [
        'summary' => 'An event',
        'description' => 'Accepts a slug or a UUID; the slug is what is printed on a poster. `registration.availability` is **computed on every read** and is never a stored column, so it cannot disagree with the clock.',
        'params' => ['event' => 'The slug from the poster, or the UUID.'],
    ],
    'v1.events.register' => [
        'summary' => 'Take a place at an event',
        'description' => 'Decided under a row lock against committed rows. `201` for a new place, `200` for one already held — so a client can tell whether its retry was the attempt that landed. `409` carries the capacity state in `details`.',
        'status' => '201',
    ],
    'v1.me.event-registrations.index' => [
        'summary' => 'My registrations',
        'paginated' => true,
    ],

    // ── notifications ─────────────────────────────────────────────────────────────────
    'v1.me.notifications.index' => [
        'summary' => 'My notifications',
        'paginated' => true,
        'description' => 'The rendered title and body, read over an authenticated connection. What reaches a push provider is routing information only (Article 8.4).',
    ],
    'v1.me.notification-preferences.index' => [
        'summary' => 'My notification preferences',
        'description' => 'Mandatory service notices ignore these. Somebody must not miss a payout date because of a toggle they set months earlier.',
    ],

    // ── privacy ───────────────────────────────────────────────────────────────────────
    'v1.privacy.notice' => [
        'summary' => 'The privacy notice in force',
        'description' => 'Public, because a person cannot be asked to consult it *before* creating an account otherwise. Publishes the legal basis per purpose: most of what this office does is not something anybody was asked to agree to.',
    ],
    'v1.me.privacy.consents.store' => [
        'summary' => 'Give consent',
        'description' => 'Refused with `422` for any purpose whose legal basis is not consent. Consent implies a right to withdraw, and offering that for statutory processing is a promise the office cannot keep (ADR 0034 §4).',
    ],

    // ── operations ────────────────────────────────────────────────────────────────────
    'v1.health' => [
        'summary' => 'Liveness',
        'description' => 'Public, and says nothing about dependencies. Publishing "postgres: down" to the internet is free reconnaissance.',
    ],
    'v1.app.bootstrap' => [
        'summary' => 'App bootstrap',
        'description' => 'What a client needs before it can sign in: API version, server time, minimum supported client version, feature flags and header conventions. Carries nothing worth protecting.',
    ],
];
