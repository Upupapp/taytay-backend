<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * The citizen onboarding / KYC lifecycle (ADR 0010 §2).
 *
 * An explicit state machine with a transition table, per CLAUDE.md Article 6 and ADR 0007.
 * No status is ever assigned directly; every move is validated here and recorded as an
 * append-only transition row.
 *
 * The shape that matters: there is **no path from `submitted` to `approved` that does not
 * pass through a human**. `screening` only produces candidates; `manual-review` is where a
 * person decides. That is what keeps registration from silently minting a duplicate
 * verified resident.
 */
enum KycStatus: string
{
    /** Started, not yet submitted. The applicant can still edit it. */
    case Draft = 'draft';

    /** Filed by the applicant. Awaiting automatic screening. */
    case Submitted = 'submitted';

    /** Deterministic matching is running or has run; candidates are attached. */
    case Screening = 'screening';

    /** A human must decide: link to an existing resident, or create a new one. */
    case ManualReview = 'manual-review';

    /** Returned to the applicant for a better document or a corrected detail. */
    case NeedsMoreInformation = 'needs-more-information';

    /** A reviewer accepted it and a canonical resident is now linked. Terminal. */
    case Approved = 'approved';

    /** A reviewer refused it. Terminal, and the reason is recorded. */
    case Rejected = 'rejected';

    /** The applicant withdrew. Terminal. */
    case Withdrawn = 'withdrawn';

    /** Left unattended past its window. Terminal; the applicant may start again. */
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn, self::Expired],
            self::Submitted => [self::Screening, self::Withdrawn, self::Expired],

            // Screening never reaches Approved. It can only hand the case to a person.
            self::Screening => [self::ManualReview, self::NeedsMoreInformation, self::Rejected],

            self::ManualReview => [self::Approved, self::Rejected, self::NeedsMoreInformation],
            self::NeedsMoreInformation => [self::Submitted, self::Withdrawn, self::Expired],

            self::Approved, self::Rejected, self::Withdrawn, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the applicant may still edit and resubmit their claims.
     */
    public function isEditableByApplicant(): bool
    {
        return $this === self::Draft || $this === self::NeedsMoreInformation;
    }

    /**
     * Open cases block a second registration for the same account, which is the first
     * line of defence against duplicate residents.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => ! $status->isTerminal()),
        ));
    }
}
