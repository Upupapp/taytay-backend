<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * Account lifecycle. An explicit enumerated state, never a pair of booleans
 * (CLAUDE.md Article 6).
 */
enum AccountStatus: string
{
    /** Created, contact not yet proven. May not authenticate. */
    case Pending = 'pending';

    case Active = 'active';

    /** Blocked by staff action. May not authenticate; may be restored. */
    case Suspended = 'suspended';

    /** Closed by the holder or the LGU. Retained for audit; never reused. */
    case Deactivated = 'deactivated';

    /**
     * Deny by default: only an explicitly active account may hold a session.
     *
     * Note what this does NOT decide — whether the actor may see any particular record.
     * That is an authorization question answered per object (ADR 0002).
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
