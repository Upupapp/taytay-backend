<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * The lifecycle of a resident's request to have their own record corrected (ADR 0013 §4).
 *
 * An explicit enumerated state rather than a pair of booleans, for the same reason every
 * other lifecycle here is: "not yet approved" and "refused" are different facts with
 * different consequences, and a `is_approved` flag cannot tell them apart.
 */
enum CorrectionStatus: string
{
    /** Filed by the resident, awaiting a reviewer. */
    case Pending = 'pending';

    /** A reviewer accepted it and the canonical record has been updated. */
    case Approved = 'approved';

    /** A reviewer refused it. The record is unchanged and the resident is told. */
    case Rejected = 'rejected';

    /** The resident withdrew it before anybody ruled. */
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Only a pending request may be ruled on.
     *
     * Without this, a double-tapped "approve" applies the same correction twice and writes
     * two history rows claiming two different previous values — the second of which is a
     * lie, because the first approval already moved the field.
     */
    public function canBeDecided(): bool
    {
        return $this === self::Pending;
    }
}
