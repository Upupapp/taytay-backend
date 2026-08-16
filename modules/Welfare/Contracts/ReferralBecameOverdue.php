<?php

declare(strict_types=1);

namespace Modules\Welfare\Contracts;

/**
 * A referral this office undertook to chase has passed its follow-up date with no response.
 *
 * THE SEAM THE ACCEPTANCE CRITERION ASKS FOR: "overdue referrals can feed Tasks/Notifications".
 * Tasks arrive in TAB 19 and Notifications in TAB 20, and neither exists yet — so this is
 * published now and listened to later, the same inversion `ResidentMerged` uses.
 *
 * Announcing rather than calling is what lets Welfare stay ignorant of who cares. It also means
 * the follow-up work appears the moment those modules land, without editing the sweep.
 *
 * CARRIES IDENTIFIERS AND A COUNT OF DAYS, NOT THE REFERRAL. A listener that needs the client's
 * name asks for it and is authorised to; an event payload carrying the reason a family was
 * referred would put that reason into every queue, log and failed-job record the listener touches
 * (Article 8.4).
 */
final class ReferralBecameOverdue
{
    public function __construct(
        public readonly string $referralUuid,
        public readonly string $referenceNumber,
        /** Who is responsible for chasing — the staff account that referred. */
        public readonly ?string $referredBySubjectId,
        public readonly ?string $caseUuid,
        public readonly string $urgency,
        public readonly int $daysOverdue,
    ) {}
}
