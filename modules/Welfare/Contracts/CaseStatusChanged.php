<?php

declare(strict_types=1);

namespace Modules\Welfare\Contracts;

/**
 * A welfare case moved to a new status.
 *
 * Published so Notification can tell the applicant without Welfare knowing that notifications
 * exist — the same inversion `ResidentMerged`, `ReferralBecameOverdue` and `VisitFollowUpDue` use.
 *
 * CARRIES THE PROJECTED CITIZEN MESSAGE, NOT THE INTERNAL REASON. The case timeline holds a
 * caseworker's justification for a decision; this event holds the sentence the office is willing
 * to say to the person it is about. A listener that had the internal reason could put it in an
 * email, and the wording that survives an appeal is not the wording written for a colleague
 * (ADR 0016 §5).
 *
 * It also carries no name, no address and no case narrative: an event payload travels into every
 * queue and failed-job record a listener touches (Article 8.4).
 */
final class CaseStatusChanged
{
    public function __construct(
        public readonly string $caseUuid,
        public readonly string $residentUuid,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        /** The projected sentence, from `CaseStatus::citizenMessage()`. */
        public readonly string $citizenMessage,
        /**
         * Whether this is a service notice the applicant must receive regardless of preference.
         *
         * A scheduled release date is one: somebody who switched notifications off must still be
         * told when and where to collect their money.
         */
        public readonly bool $isMandatoryNotice = false,
    ) {}

    /**
     * Whether the applicant should hear about this at all.
     *
     * NOT EVERY TRANSITION IS WORTH A MESSAGE. A case moving between `assessment` and `endorsed`
     * is the office moving paper between desks; telling somebody each time teaches them the
     * notifications are noise, and then the one that matters arrives among fourteen that did not.
     *
     * The rule lives on the event rather than in the listener so that "which movements a family
     * hears about" is answerable in one place, by reading one method.
     */
    public function isWorthTellingTheApplicant(): bool
    {
        return in_array($this->toStatus, [
            'approved',
            'rejected',
            'scheduled',
            'released',
            'completed',
            // The applicant has to DO something, which is the most important message this system
            // sends: a case waiting on a document nobody asked for is how a family drops out.
            'returned',
        ], true);
    }
}
