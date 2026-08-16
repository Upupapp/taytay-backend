<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * Where a referral stands with the receiving office.
 *
 * Taken from the admin console's `REFERRAL_STATUS_CATALOG` and its transition map, which are
 * authoritative for this vocabulary.
 *
 * THE STATES AFTER `Sent` ARE REPORTS, NOT FACTS THIS OFFICE ESTABLISHES. The MSWDO does not know
 * that a hospital has started work; it knows that somebody there said so. That distinction is why
 * every transition past `Sent` records who recorded it and when, and why none of them is inferred
 * from elapsed time.
 */
enum ReferralStatus: string
{
    /** Prepared but not yet transmitted. Nothing has left the building. */
    case Draft = 'draft';

    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in-progress';

    /**
     * The receiving office is waiting for something from the client.
     *
     * Part of the universal status vocabulary every module stays compatible with, and the state
     * an applicant most needs told apart from "in progress": one means wait, the other means
     * bring something.
     */
    case WaitingRequirements = 'waiting-requirements';

    case Served = 'served';
    case Declined = 'declined';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Closed],
            self::Sent => [self::Acknowledged, self::WaitingRequirements, self::Declined, self::Closed],
            self::Acknowledged => [self::InProgress, self::WaitingRequirements, self::Declined, self::Closed],
            self::InProgress => [self::WaitingRequirements, self::Served, self::Declined, self::Closed],
            // The one loop in the lifecycle, and it exists because families routinely come back
            // with the missing paper.
            self::WaitingRequirements => [self::InProgress, self::Served, self::Declined, self::Closed],
            self::Served => [self::Closed],
            self::Declined => [self::Closed],
            self::Closed => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Still with the receiving office, and still this office's to chase.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed && $this !== self::Declined;
    }

    /**
     * Whether anything has actually left the building yet.
     *
     * The line that matters for editing: a draft can be revised freely, and a sent referral
     * cannot — the other office already has what it has.
     */
    public function hasLeftTheOffice(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * A refusal and a closure both need a reason recorded.
     *
     * `Declined` because the client has to be told what to do instead, and `Closed` because a
     * referral that simply stops is indistinguishable from one everybody forgot.
     */
    public function requiresOutcome(): bool
    {
        return $this === self::Declined || $this === self::Served || $this === self::Closed;
    }

    /**
     * Sending is separately permissioned; everything after it is recording what was reported.
     */
    public function requiredPermission(): Permission
    {
        return $this === self::Sent ? Permission::ReferralSend : Permission::ReferralManage;
    }

    /**
     * What the applicant is told, in a vocabulary that promises nothing this office controls.
     *
     * The MSWDO cannot make another agency act, so a citizen-facing status must not read as a
     * commitment. `acknowledged` and `in-progress` both project as "being handled" — telling
     * somebody the exact desk their file sits on would identify the handling worker there, and
     * would invite them to chase an office that has no relationship with them.
     */
    public function citizenStatus(): string
    {
        return match ($this) {
            // A draft has not left the building, so from the applicant's point of view nothing
            // has happened yet and saying otherwise would be untrue.
            self::Draft => 'preparing',
            self::Sent, self::Acknowledged, self::InProgress => 'referred',
            self::WaitingRequirements => 'action-needed',
            self::Served => 'completed',
            self::Declined, self::Closed => 'closed',
        };
    }

    public function citizenMessage(): string
    {
        return match ($this) {
            self::Draft => 'We are preparing a referral for you.',
            self::Sent, self::Acknowledged, self::InProgress => 'We have referred you to another office. They will be in touch.',
            self::WaitingRequirements => 'The office you were referred to needs something from you. Please contact us.',
            self::Served => 'The office you were referred to has assisted you.',
            self::Declined, self::Closed => 'This referral has been closed. Please contact us if you still need help.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
