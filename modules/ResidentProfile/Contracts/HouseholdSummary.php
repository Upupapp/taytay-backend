<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * The minimum another module may know about a household.
 *
 * Published thin, for the same reason as {@see ResidentSummary}. A module deciding whether a
 * relief distribution has already reached an address needs the household's identity, its
 * barangay and how many people are in it. It does not need the members, and handing over the
 * Eloquent model would give it them along with every member's identity.
 *
 * `memberCount` is derived at construction from open memberships, never read from a stored
 * column — there is no such column, on purpose (ADR 0014 §2).
 */
final readonly class HouseholdSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public ?int $barangayId,
        public int $memberCount,
        public string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
