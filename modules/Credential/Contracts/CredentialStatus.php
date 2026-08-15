<?php

declare(strict_types=1);

namespace Modules\Credential\Contracts;

/**
 * Digital ID lifecycle. An explicit enumerated state with recorded transitions
 * (CLAUDE.md Article 6).
 */
enum CredentialStatus: string
{
    case Issued = 'issued';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /** Revocation is permanent: a revoked card is never reinstated, a new one is issued. */
    public function isTerminal(): bool
    {
        return $this === self::Revoked || $this === self::Expired;
    }
}
