<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * What kind of account this is — which decides which authentication flows apply, not what
 * the holder may do. Authority comes from AccessControl (ADR 0002).
 */
enum AccountType: string
{
    /** A resident acting for themselves. Authenticates with a one-time code. */
    case Citizen = 'citizen';

    /** LGU staff. Authenticates with a password, and must complete MFA. */
    case Staff = 'staff';

    /**
     * Staff handle other people's welfare records, so a stolen password must not be
     * enough on its own. Citizens authenticate with a one-time code to their mobile,
     * which is already a second factor in practice — requiring TOTP as well would push
     * them toward not using the service at all.
     */
    public function requiresMultiFactor(): bool
    {
        return $this === self::Staff;
    }
}
