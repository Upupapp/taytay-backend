<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * The minimum another module may know about a resident.
 *
 * Published deliberately thin. Credential needs to know that a resident exists, is
 * verified, and what name to show a person holding a card — and nothing else. Handing out
 * the Eloquent model instead would give every consumer the address, income and sectors
 * too, and the boundary would exist only in the documentation.
 */
final readonly class ResidentSummary
{
    public function __construct(
        public string $id,
        public string $displayName,
        public VerificationTier $verificationTier,
    ) {}

    public function isVerified(): bool
    {
        return $this->verificationTier === VerificationTier::Verified;
    }
}
