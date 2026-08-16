<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

use Modules\Welfare\Application\CaseService;

/**
 * The canonical social welfare case lifecycle (ADR 0007, implemented by ADR 0016).
 *
 * THIRTEEN STATES, ONE MACHINE. ADR 0007 settled this during TAB 02 by reading the Angular
 * staff console's `domain/assistance/assistance-request.ts` — the lifecycle the office
 * actually operates, with transition rules and a separation-of-duties constraint behind it.
 * The citizen app's 17-state list was rejected because it encodes *routing*
 * (`forSocialWorker`, `forHealthOfficeReview`) as state, which multiplies states by offices
 * and has no transition rules at all.
 *
 * TAB 01 §E names a slightly different vocabulary ("Pending Review", "Under Verification",
 * "Ready for Release", "Archived"). That is a paraphrase of the same lifecycle, not a
 * competing one; ADR 0016 §1 carries the mapping. Implementing it literally would have
 * abandoned an accepted ADR derived from the real frontend contract, and broken the
 * console this backend exists to serve.
 *
 * ROUTING IS ASSIGNMENT, NOT STATE. A case in `Assessment` assigned to the health office is
 * one state and one assignee, not a state called "for health office review".
 *
 * Nothing assigns a status directly. Every move goes through
 * {@see CaseService::transition()}, which validates against
 * {@see canTransitionTo()} *before* checking permission — so a probe cannot use the error
 * to map who holds what.
 */
enum CaseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case IntakeReview = 'intake-review';

    /** Returned to the applicant for missing documents. Re-enters at intake review. */
    case Returned = 'returned';

    case Assessment = 'assessment';

    /** A social worker has recommended. Distinct from approval on purpose — see below. */
    case Endorsed = 'endorsed';

    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Approved and booked for a payout or distribution date. */
    case Scheduled = 'scheduled';

    case Released = 'released';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Left in `Returned` past the configured window without the applicant responding. */
    case Expired = 'expired';

    /**
     * The transition map. The single source of truth for what may follow what.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::IntakeReview, self::Rejected, self::Cancelled],
            self::IntakeReview => [self::Assessment, self::Returned, self::Rejected, self::Cancelled],
            // Re-enters at intake review, never straight to assessment: whatever the
            // applicant brought back has to be checked before it is assessed.
            self::Returned => [self::IntakeReview, self::Cancelled, self::Expired],
            self::Assessment => [self::Endorsed, self::Returned, self::Rejected, self::Cancelled],
            self::Endorsed => [self::Approved, self::Rejected, self::Returned, self::Cancelled],
            self::Approved => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Released, self::Cancelled],
            // Released is a money event. There is no route back from it — a mistaken release
            // is corrected by a new record, never by rewriting the one that paid out.
            self::Released => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Terminal states hold no further work.
     */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Whether reaching this state requires a recorded reason.
     *
     * Every one of these is a decision a person will later be asked to justify: a refusal,
     * an abandonment, a demand for more documents, or the closing of a file. An unexplained
     * rejection is indistinguishable after the fact from an arbitrary one, and it is the
     * applicant who bears that.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled, self::Returned, self::Completed, self::Expired => true,
            default => false,
        };
    }

    /**
     * The permission that authorises reaching this state (contract matrix §5).
     *
     * Resolved from the *target*, so the state machine and the authorization table stay in
     * one place. `Cancelled` is null because it has two legitimate callers — an applicant
     * withdrawing their own draft, or staff closing a file — and the choice between them is
     * an ownership question the service answers, not a permission lookup.
     */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::Submitted => 'request.create',
            self::IntakeReview, self::Returned => 'request.intake',
            self::Assessment => 'request.assess',
            self::Endorsed => 'request.endorse',
            self::Approved => 'request.approve',
            self::Rejected => 'request.reject',
            self::Scheduled => 'request.schedule',
            self::Released => 'request.release',
            self::Completed, self::Expired => 'request.close',
            self::Draft, self::Cancelled => null,
        };
    }

    /**
     * The citizen-facing vocabulary (ADR 0007 projection table).
     *
     * `Assessment` and `Endorsed` both project to `under-review` deliberately: an applicant
     * does not need to know which desk holds their file, and publishing it would let them
     * infer the handling social worker.
     */
    public function citizenStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Submitted => 'submitted',
            self::IntakeReview, self::Assessment, self::Endorsed => 'under-review',
            self::Returned => 'needs-more-documents',
            self::Approved => 'approved',
            self::Scheduled => 'scheduled-for-release',
            self::Released => 'released',
            self::Completed => 'completed',
            self::Rejected => 'rejected',
            self::Cancelled => 'cancelled',
            self::Expired => 'expired',
        };
    }

    /**
     * Plain language for the applicant.
     *
     * Deliberately vague where the internal state is specific. "Being reviewed by our social
     * welfare team" covers three internal states, because the difference between them is
     * staff routing and is none of the applicant's business — and telling them would identify
     * the officer holding the file.
     */
    public function citizenMessage(): string
    {
        return match ($this) {
            self::Draft => 'Your request has not been submitted yet.',
            self::Submitted => 'We have received your request and it is queued for review.',
            self::IntakeReview, self::Assessment, self::Endorsed => 'Your request is being reviewed by our social welfare team.',
            self::Returned => 'We need more documents before we can continue. Please check the list of requirements.',
            self::Approved => 'Your request has been approved. We will contact you about release details.',
            self::Scheduled => 'Your assistance has been scheduled for release.',
            self::Released => 'Your assistance has been released.',
            self::Completed => 'This request is complete.',
            self::Rejected => 'This request was not approved.',
            self::Cancelled => 'This request was cancelled.',
            self::Expired => 'This request expired because the requested documents were not received in time.',
        };
    }

    /**
     * Whether an applicant may cancel from here.
     *
     * The DEFAULT rule only. The service also requires ownership and re-checks at execution
     * time — this exists so the projection and the rule cannot drift, not so a client can
     * decide (ADR 0007 §4).
     *
     * Cancellation stops at `Assessment`: once a social worker is spending time on a file,
     * withdrawing it is a conversation with the office, not a button.
     */
    public function citizenMayCancel(): bool
    {
        return match ($this) {
            self::Draft, self::Submitted, self::IntakeReview, self::Returned => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
