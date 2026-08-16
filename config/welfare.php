<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Draft retention
    |--------------------------------------------------------------------------
    |
    | How long an unsubmitted assistance draft is kept before it expires (ADR 0017 §3).
    |
    | A draft holds a narrative the applicant has not chosen to submit. Under RA 10173's
    | storage-limitation principle it has no lawful basis to persist indefinitely: nobody has
    | acted on it and no decision rests on it. Expiry is enforced, not decorative — an expired
    | draft is refused rather than silently resurrected, because a clock that resets whenever
    | somebody returns is not a retention policy.
    |
    | Thirty days is a placeholder. The master command forbids hardcoding retention periods the
    | LGU has not approved; the DPO sets the real figure. See gap G-21.
    |
    */
    'drafts' => [
        'retention_days' => (int) env('WELFARE_DRAFT_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Returned-case expiry
    |--------------------------------------------------------------------------
    |
    | How long a case may sit in `returned` awaiting the applicant's documents before it is
    | eligible to be aged out to `expired`.
    |
    | Nothing reads this yet — the scheduled job that ages cases out arrives in TAB 31. The
    | value lives here now so that job has a configured policy to read rather than inventing
    | one, and so the number is reviewable before it is enforced against anybody.
    |
    */
    'cases' => [
        'returned_expiry_days' => (int) env('WELFARE_RETURNED_EXPIRY_DAYS', 60),
    ],
];
