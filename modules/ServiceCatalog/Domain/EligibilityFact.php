<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

/**
 * The facts an eligibility criterion may read (ADR 0018 §3).
 *
 * A CLOSED SET, AND DELIBERATELY A SHORT ONE. Every entry is something a clerk can look up,
 * point at, and explain to the person in front of them: how old they are, where they live, how
 * many people are in the household. If a criterion cannot be explained at a counter, it should
 * not be deciding who gets help.
 *
 * WHAT IS ABSENT MATTERS MORE THAN WHAT IS PRESENT.
 *
 * There is no `vulnerability_score` fact, and there will not be one without an ADR. That score
 * is unapproved placeholder weighting (gap G-20); it declares `decision_support_only: true` in
 * its own payload; and safeguarding factors contribute nothing to it by design, precisely so
 * that it can be shown to ordinary staff. Wiring it into eligibility would make an unapproved
 * ordering consequential, and would do it one layer removed from anybody who could see it
 * happening — a caseworker reading "likely ineligible" has no way to know a placeholder weight
 * put it there.
 *
 * There is also no free-text or arbitrary-expression fact. A rule language would let somebody
 * encode policy nobody reviewed, in a syntax nobody at the MSWDO reads, and the resulting
 * refusals would be unexplainable — which is exactly the opaque denial system the master
 * command forbids.
 */
enum EligibilityFact: string
{
    /** The applicant's age in years, from the canonical resident record. */
    case Age = 'age';

    /**
     * Which barangay the resident's record places them in, as the **code** the public directory
     * publishes — `brgy-san-juan`, never the auto-increment key.
     *
     * A criterion is authored by a person and read back by a person, so the value has to be one
     * they can recognise. It is also the only form that survives being carried between
     * environments: surrogate keys are assigned by insertion order, so `2` means one barangay in
     * staging and another in production. Authoring is validated against the directory, so a
     * criterion cannot name a barangay that does not exist.
     */
    case Barangay = 'barangay';

    /** A sectoral tag on the resident (senior-citizen, pwd, solo-parent, …). */
    case Sector = 'sector';

    /** How many people currently live in the applicant's household. */
    case HouseholdSize = 'household-size';

    /** Whether the resident's identity has been verified by a reviewer. */
    case VerificationTier = 'verification-tier';

    /** Declared monthly household income, in centavos. */
    case MonthlyIncome = 'monthly-income';

    /**
     * How this fact is compared.
     *
     * @return list<string>
     */
    public function supportedComparators(): array
    {
        return match ($this) {
            self::Age, self::HouseholdSize, self::MonthlyIncome => ['at-least', 'at-most', 'between'],
            self::Barangay, self::Sector, self::VerificationTier => ['is', 'is-one-of'],
        };
    }

    /**
     * Whether an absent value should read as `unknown` rather than `not-met`.
     *
     * ALWAYS TRUE, and it is a deliberate design position rather than a shortcut.
     *
     * A missing income figure means nobody has asked yet — not that the applicant earns too
     * much. Treating absence as failure would turn every incomplete record into a refusal, and
     * incomplete records belong overwhelmingly to the people least able to complete them: those
     * without documents, without an address history, without anyone to help them fill a form.
     * `unknown` sends the case to a human, which is the correct destination for "we do not
     * know".
     */
    public function absenceIsUnknown(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $fact): string => $fact->value, self::cases());
    }
}
