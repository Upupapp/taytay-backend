<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * The closed set of resident fields that may be changed, and by whom (ADR 0013 §4).
 *
 * THE POINT OF THIS ENUM is that "which fields may a citizen edit" is answered in exactly
 * one place. The alternative — a validation array in the citizen controller and another in
 * the admin controller — drifts, and the direction it drifts is always toward the citizen
 * controller accidentally accepting a field it should not (CLAUDE.md Article 3.4).
 *
 * Two tiers:
 *
 *  * **Self-service.** Contact details and address. A resident who moves house must be
 *    able to say so without an appointment; getting this wrong means the LGU cannot reach
 *    people it is trying to help.
 *  * **Request-only.** Name, birth date, sex, civil status. These are the fields a
 *    reviewer checked against documents, and they are precisely what a fraudulent claim
 *    would alter. A resident may *propose* a change; only a reviewer may apply one.
 *
 * Absent from both tiers, deliberately: `verification_tier`, `is_active`,
 * `identity_fingerprint`, `philsys_last_four` and `monthly_income_centavos`. Tier and
 * active state are outcomes of a review, not inputs to it. Income is means-testing
 * evidence — a self-declared change to it is an assistance decision, not a profile edit,
 * and it belongs to the assistance workflow in a later TAB.
 */
enum CorrectableField: string
{
    case FirstName = 'first_name';
    case MiddleName = 'middle_name';
    case LastName = 'last_name';
    case Suffix = 'suffix';
    case BirthDate = 'birth_date';
    case Sex = 'sex';
    case CivilStatus = 'civil_status';
    case BarangayId = 'barangay_id';
    case StreetAddress = 'street_address';
    case PurokOrSitio = 'purok_or_sitio';
    case MobileNumber = 'mobile_number';
    case Email = 'email';

    /**
     * Whether a resident may change this field on their own record without review.
     *
     * Note that `barangay_id` is NOT self-service even though it is part of an address:
     * barangay drives staff scope, so letting a resident move themselves between barangays
     * would let them choose which office can see their file (ADR 0012).
     */
    public function isSelfService(): bool
    {
        return match ($this) {
            self::StreetAddress, self::PurokOrSitio, self::MobileNumber, self::Email => true,
            default => false,
        };
    }

    /**
     * Whether this field forms part of the identity a reviewer verified.
     *
     * Changing one of these invalidates the matching fingerprint and must re-key it, or the
     * registry stops finding the person's own duplicates.
     */
    public function isIdentityField(): bool
    {
        return match ($this) {
            self::FirstName, self::LastName, self::BirthDate => true,
            default => false,
        };
    }

    /**
     * Fields a resident may propose a change to, self-service or otherwise.
     *
     * @return list<string>
     */
    public static function requestableValues(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function selfServiceValues(): array
    {
        return array_values(array_map(
            static fn (self $field): string => $field->value,
            array_filter(self::cases(), static fn (self $field): bool => $field->isSelfService()),
        ));
    }
}
