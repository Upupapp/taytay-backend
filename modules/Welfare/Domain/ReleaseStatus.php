<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * Where a release stands.
 *
 * The master command's shape: Ready → Released → Completed, with Failed, Deferred and Cancelled
 * as secondary operational outcomes.
 *
 * `Released` AND `Completed` ARE BOTH NEEDED, and the difference is not bureaucratic. `Released`
 * means the office handed it over. `Completed` means the handover is acknowledged and the record
 * is closed. Between them sits the real case: a cheque handed to a relative who has not yet
 * confirmed, a bank transfer sent but not landed. Collapsing them would make "we paid them" and
 * "they have it" the same claim, and only one of those is ever true first.
 */
enum ReleaseStatus: string
{
    /** Approved and scheduled. Nothing has been handed over. */
    case Ready = 'ready';

    /** Handed over by a releasing officer. */
    case Released = 'released';

    /** Acknowledged and closed. */
    case Completed = 'completed';

    /**
     * Attempted and did not happen — the beneficiary did not come, the transfer bounced.
     *
     * Distinct from `Cancelled`: a failure is the office's problem to retry, a cancellation is a
     * decision that it should not happen at all.
     */
    case Failed = 'failed';

    /** Postponed. Still intended. */
    case Deferred = 'deferred';

    /** Called off. Not to be attempted. */
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Ready => [self::Released, self::Failed, self::Deferred, self::Cancelled],
            // Money has moved. It cannot be un-moved by a status change: a release sent in error
            // is completed and then corrected by a new record, never rewound.
            self::Released => [self::Completed, self::Failed],
            self::Completed => [],
            // A failed or deferred release returns to the queue, because the family is still owed
            // what was approved for them.
            self::Failed => [self::Ready, self::Cancelled],
            self::Deferred => [self::Ready, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** Whether the office has handed anything over. */
    public function hasBeenReleased(): bool
    {
        return $this === self::Released || $this === self::Completed;
    }

    /**
     * Which permission moves a release into this state.
     *
     * **`Released` is the only one that needs `request.release`**, and that is the whole
     * segregation-of-duties design: creating, deferring and cancelling are scheduling decisions a
     * caseworker makes; handing money over is not.
     */
    public function requiredPermission(): Permission
    {
        return match ($this) {
            self::Released, self::Completed => Permission::RequestRelease,
            default => Permission::RequestSchedule,
        };
    }

    /**
     * Every outcome that is not the happy path must say why.
     *
     * A failed release with no reason is indistinguishable from one nobody attempted, and the
     * family is owed an answer either way.
     */
    public function requiresReason(): bool
    {
        return $this === self::Failed || $this === self::Deferred || $this === self::Cancelled;
    }

    /**
     * What the beneficiary is told.
     *
     * `Ready` deliberately projects as "scheduled" rather than "approved": an applicant reading
     * "approved" assumes the money is theirs now, and the gap between approval and a payout day
     * is where most complaints are born.
     */
    public function citizenStatus(): string
    {
        return match ($this) {
            self::Ready, self::Deferred => 'scheduled',
            self::Released => 'released',
            self::Completed => 'received',
            self::Failed => 'action-needed',
            self::Cancelled => 'cancelled',
        };
    }

    public function citizenMessage(): string
    {
        return match ($this) {
            self::Ready => 'Your assistance is scheduled for release. We will tell you when and where.',
            self::Deferred => 'Release has been postponed. We will contact you with a new date.',
            self::Released => 'Your assistance has been released.',
            self::Completed => 'Your assistance has been received.',
            self::Failed => 'We could not complete the release. Please contact the office.',
            self::Cancelled => 'This release was cancelled. Please contact the office if you still need help.',
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
