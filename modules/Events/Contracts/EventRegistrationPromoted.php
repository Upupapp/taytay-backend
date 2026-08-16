<?php

declare(strict_types=1);

namespace Modules\Events\Contracts;

/**
 * A waitlisted registration was promoted to a seat (ADR 0031 §5).
 *
 * PUBLISHED AS AN EVENT RATHER THAN A CALL, for the same reason as `CaseStatusChanged`: Events
 * decides that a seat opened, and knows nothing about whether anybody is told. A push provider
 * outage must not be able to roll back a promotion, and a module that publishes events must not
 * depend on the module that delivers messages (ADR 0025 §1).
 *
 * IT CARRIES NO NAME, NO ADDRESS AND NO CONTACT DETAIL. An event payload travels into every queue
 * record and failed-job row a listener touches (Article 8.4). The event title is here because it
 * is already public information printed on a poster — being on the list for it is not, which is
 * why the resident is a UUID.
 */
final readonly class EventRegistrationPromoted
{
    public function __construct(
        public string $registrationUuid,
        public string $eventUuid,
        public string $residentUuid,
        /** Public information — this is what is on the poster. */
        public string $eventTitle,
        /** ISO-8601 UTC, so a listener never has to guess a timezone. */
        public string $startsAtIso,
    ) {}
}
