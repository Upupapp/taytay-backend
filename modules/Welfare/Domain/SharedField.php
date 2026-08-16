<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * A field a referral summary may carry **beyond the minimum**.
 *
 * THE MINIMUM IS THE CLIENT'S NAME, THE REFERENCE NUMBER AND THE REASON — enough for the
 * receiving office to know who is coming and why. Everything in this enum is opt-in, one field at
 * a time, each with a stated need.
 *
 * Named individually because a single "share full profile" switch would be ticked once and
 * forgotten. "Include everything, they can ignore what they don't need" is how a survivor's
 * address reaches a desk that had no reason to hold it.
 *
 * A field chosen but not held is **omitted, not blanked**. A line reading "Address: withheld"
 * tells the reader there is an address worth hiding, which for a protection case is itself the
 * disclosure.
 */
enum SharedField: string
{
    case BirthDate = 'birth-date';
    case Address = 'address';
    case ContactNumber = 'contact-number';
    case HouseholdComposition = 'household-composition';
    case Income = 'income';
    case VulnerabilitySectors = 'vulnerability-sectors';
    case AssistanceHistory = 'assistance-history';

    public function label(): string
    {
        return match ($this) {
            self::BirthDate => 'Date of birth',
            self::Address => 'Home address',
            self::ContactNumber => 'Contact number',
            self::HouseholdComposition => 'Who else is in the household',
            self::Income => 'Household income',
            self::VulnerabilitySectors => 'Sector membership',
            self::AssistanceHistory => 'Previous assistance from this office',
        };
    }

    /**
     * Whether releasing this field can expose something that endangers the client.
     *
     * Sector membership can disclose that somebody is a VAWC survivor or a child in conflict with
     * the law. A home address is the field that matters most in a protection case — it is the
     * one an abuser needs. Assistance history describes a family's circumstances over years.
     *
     * These require `referral.disclose.protected` in addition to the ordinary permission, so
     * releasing them is a second, separately-held decision rather than one more checkbox on a
     * form somebody is working through quickly.
     */
    public function needsExtraCare(): bool
    {
        return match ($this) {
            self::Address, self::VulnerabilitySectors, self::AssistanceHistory => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
