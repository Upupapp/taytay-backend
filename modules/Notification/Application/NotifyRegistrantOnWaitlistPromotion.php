<?php

declare(strict_types=1);

namespace Modules\Notification\Application;

use Modules\Events\Contracts\EventRegistrationPromoted;
use Modules\Identity\Application\AccountDirectory;

/**
 * Tells somebody a seat opened up for them (ADR 0031 §5).
 *
 * THE ONE NOTIFICATION IN THIS MODULE THAT IS TIME-CRITICAL IN BOTH DIRECTIONS. Late, and the
 * person misses an event they were waiting for. Duplicated, and somebody is told twice that they
 * got in and starts wondering whether they have two seats. The de-duplication is not here — it is
 * the conditional update in `EventRegistrationService::promoteFromWaitlist()`, which announces a
 * promotion only when it was the write that performed it.
 *
 * NOT MANDATORY. A promotion is good news about something optional; somebody who switched event
 * notifications off has said they do not want them, and overriding that is reserved for things
 * like a payout date they would be harmed by missing (ADR 0025 §4).
 *
 * THE BODY NAMES THE EVENT AND NOTHING ELSE. An event title is public — it is printed on a poster.
 * What is not public is that this person is on the list for it, which is why nothing here reaches
 * a push payload beyond routing (Article 8.4, `OutboundNotification::routingPayload()`).
 */
final class NotifyRegistrantOnWaitlistPromotion
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly AccountDirectory $accounts,
    ) {}

    public function handle(EventRegistrationPromoted $event): void
    {
        $accounts = $this->accounts->accountIdsForResident($event->residentUuid);

        /*
         * A registrant with no account cannot be reached this way, and that is not an error — an
         * assisted registration made at the counter is followed up at the counter. The seat is
         * theirs either way; the promotion already happened.
         */
        foreach ($accounts as $account) {
            $this->notifier->notify(
                $account,
                'event.waitlist-promoted',
                [
                    'title' => 'A place opened up',
                    'body' => sprintf('You now have a place at %s.', $event->eventTitle),
                    'subject_type' => 'events.registration',
                    'subject_id' => $event->registrationUuid,
                    'category' => 'optional',
                    // Above the ordinary, below a service notice. Somebody deciding what to do
                    // with their Tuesday morning benefits from seeing this before the digest.
                    'priority' => 'high',
                ],
                config('notification.default_channels', ['database']),
            );
        }
    }
}
