<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * How firmly a case requirement is owed. Mirrors the console's `RequirementObligation`.
 */
enum RequirementObligation: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Conditional = 'conditional';

    /**
     * Whether this requirement is still outstanding, given its applicability and whether a
     * verified document sits in its slot.
     *
     * A CONDITIONAL REQUIREMENT NOBODY HAS RULED ON IS OUTSTANDING. It is not "not required
     * yet" — it is a decision somebody owes, and treating it as satisfied is how an undecided
     * safeguard becomes a waived one by inaction. It surfaces as work, which is what it is.
     */
    public function isOutstanding(RequirementApplicability $applicability, bool $isSatisfied): bool
    {
        if ($isSatisfied) {
            return false;
        }

        return match ($this) {
            self::Required => true,
            self::Optional => false,
            self::Conditional => $applicability !== RequirementApplicability::DoesNotApply,
        };
    }
}
