<?php

declare(strict_types=1);

namespace Modules\Credential\Contracts;

/**
 * What a scan resolved to.
 *
 * Every value is recorded, including the failures — a forged or replayed scan is exactly
 * the event an investigation later asks about.
 *
 * The verifier is told which of these happened, because "expired" and "revoked" call for
 * genuinely different responses at a counter. None of them reveal anything about the
 * holder.
 */
enum VerificationOutcome: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
    case SignatureInvalid = 'signature-invalid';
    case Replayed = 'replayed';
    case Malformed = 'malformed';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}
