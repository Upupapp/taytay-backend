<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

/**
 * Whether a human has accepted a presented document.
 *
 * Separate from {@see ScanStatus} and never conflated with it. The scanner
 * answers "is this file dangerous"; a reviewer answers "is this the document we asked for, and
 * does it say what it needs to say". A clean scan is not a verification, and the two have
 * different owners, different timings and different consequences.
 */
enum VerificationStatus: string
{
    /** Presented, not yet looked at. */
    case Pending = 'pending';

    case Verified = 'verified';

    /**
     * Looked at and not accepted — wrong document, illegible, expired on presentation.
     *
     * The version stays. A rejected document is evidence that something was presented and found
     * wanting, which is exactly what an applicant disputing a refusal needs to point at.
     */
    case Rejected = 'rejected';

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether this version satisfies the requirement it was presented against.
     */
    public function satisfiesRequirement(): bool
    {
        return $this === self::Verified;
    }

    /**
     * A decision must say why when it refuses.
     *
     * Accepting needs no explanation; refusing does, because the applicant has to be told what
     * to bring instead, and because "rejected" with no reason is indistinguishable from a
     * mistake or from somebody's bad afternoon.
     */
    public function requiresNote(): bool
    {
        return $this === self::Rejected;
    }
}
