<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * A resident's civil status.
 *
 * **EXTRACTED IN TAB 33, AND THE REASON IS THE FINDING RATHER THAN THE TIDINESS.** This vocabulary
 * existed as a bare `in:single,married,widowed,separated,annulled,cohabiting` string, written out
 * twice — in `MyProfileController` and in `ResidentController`. Two lists of the same thing drift,
 * and the drift here would be silent: a value one endpoint accepts and the other refuses, on the
 * same field of the same record.
 *
 * It was also invisible to clients. `ApiContractTest` caught it by finding `single` in a real
 * response and in no documented enum — a value a frontend developer would have had to discover by
 * reading backend code, which is precisely what TAB 33 exists to make unnecessary.
 *
 * `annulled` and `cohabiting` are both here on purpose. Philippine law has no divorce, so
 * `annulled` is a distinct legal state rather than a synonym for separated; and `cohabiting`
 * describes a household arrangement that determines who is counted in a family-based distribution
 * whether or not it is a marriage.
 */
enum CivilStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';

    /** Separated in fact, without a legal decree. */
    case Separated = 'separated';

    /** A marriage declared void. Distinct from `separated`: there is no divorce in the Philippines. */
    case Annulled = 'annulled';

    /**
     * Living as partners without marriage.
     *
     * Recorded because it decides who is in the household for a family-based distribution, not as
     * a judgement about the arrangement.
     */
    case Cohabiting = 'cohabiting';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * The validation rule, so the vocabulary is written once.
     */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
