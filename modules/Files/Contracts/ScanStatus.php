<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

/**
 * Where an uploaded file stands with the malware scanner.
 *
 * The master command asks for a "malware-scan status hook", and a hook is exactly what this is:
 * the column, the state machine and the queued job exist and are wired end to end; the scanner
 * itself is deployment configuration this repository must not assume (gap G-25).
 *
 * PENDING IS NOT CLEAN. That distinction is the reason this is an enum and not a boolean. A
 * two-state `is_clean` flag has to default to something, and whichever it defaults to is wrong:
 * default true and every unscanned file is treated as safe, default false and every file is
 * quarantined before the scanner has said anything. `Pending` says the honest thing, and the
 * download path decides what to do about it — which is to serve to staff and refuse to share
 * outward, because a caseworker must be able to work a file the scanner has not reached yet.
 */
enum ScanStatus: string
{
    /** Stored, queued, not yet examined. */
    case Pending = 'pending';

    case Clean = 'clean';

    /** Quarantined. Never served to anybody, by any route. */
    case Infected = 'infected';

    /**
     * No scanner is configured in this environment.
     *
     * Distinct from `Pending` so that "nobody is going to scan this" cannot be mistaken for
     * "the scan has not finished". A queue full of permanently pending files looks like a
     * backlog; it is a missing scanner.
     */
    case Skipped = 'skipped';

    /**
     * Whether the bytes may be handed to anybody at all.
     */
    public function mayBeServed(): bool
    {
        return $this !== self::Infected;
    }

    /**
     * Whether a copy may leave the office — an outward share or an export.
     *
     * Stricter than {@see mayBeServed()}. A caseworker opening an unscanned attachment on a
     * managed workstation is a risk the office already carries by accepting the upload; passing
     * that same unscanned file to a partner agency is a risk it would be passing to somebody
     * else.
     */
    public function mayLeaveTheOffice(): bool
    {
        return $this === self::Clean || $this === self::Skipped;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
