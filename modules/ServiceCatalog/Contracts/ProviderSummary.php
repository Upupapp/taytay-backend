<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Contracts;

/**
 * What another module may know about a service provider.
 *
 * Carries the contact details deliberately: Welfare needs them to snapshot onto a referral at the
 * moment it is sent, because a referral must still say where it actually went after the directory
 * entry is renamed or retired (ADR 0021 §2).
 */
final class ProviderSummary
{
    /**
     * @param  list<string>  $servicesOffered
     * @param  list<string>  $channels
     * @param  list<string>  $problems  Empty when this entry can actually be sent to.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $destinationType,
        public readonly string $status,
        public readonly array $servicesOffered,
        public readonly array $channels,
        public readonly ?string $contactPerson,
        public readonly ?string $contactPhone,
        public readonly ?string $contactEmail,
        public readonly ?string $address,
        public readonly ?int $usualResponseDays,
        public readonly array $problems,
    ) {}

    public function isAcceptingReferrals(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The best single contact string to snapshot onto a referral.
     *
     * A named person where there is one, because "call Mrs Reyes on 8123-4567" is what makes a
     * follow-up call possible three weeks later, and an office switchboard is not.
     */
    public function contactLine(): ?string
    {
        $parts = array_filter([$this->contactPerson, $this->contactPhone ?? $this->contactEmail]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
