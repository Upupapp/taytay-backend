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
        // Carried so consumers can ask AccessControl whether the caller's scope reaches
        // this resident. Without it, a barangay-scoped clerk could act on a resident from
        // another barangay simply because the consuming module could not tell (ADR 0012).
        public ?int $barangayId = null,
    ) {}

    public function isVerified(): bool
    {
        return $this->verificationTier === VerificationTier::Verified;
    }
}
