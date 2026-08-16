<?php

declare(strict_types=1);

namespace Modules\Welfare\Contracts;

/**
 * A field visit concluded with a next action and a date.
 *
 * The follow-up seam TAB 17 owes TAB 19's tasks — published now, listened to later, the same
 * inversion `ResidentMerged` and `ReferralBecameOverdue` use.
 *
 * CARRIES THE ACTION, NOT THE OBSERVATIONS. What somebody must *do* — "return with the barangay
 * certificate", "arrange a medical referral" — is safe to put in a task queue and a notification.
 * What a family said, what a worker judged, and why the visit was made are not: a payload holding
 * those would put them into every queue, log and failed-job record a listener touches
 * (Article 8.4).
 */
final class VisitFollowUpDue
{
    public function __construct(
        public readonly string $visitUuid,
        public readonly string $referenceNumber,
        /** Whose work this is. The visit's assignee, not whoever concluded it. */
        public readonly ?string $assignedToSubjectId,
        public readonly string $dueOn,
        public readonly string $nextAction,
    ) {}
}
