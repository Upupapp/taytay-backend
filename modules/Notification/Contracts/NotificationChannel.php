<?php

declare(strict_types=1);

namespace Modules\Notification\Contracts;

/**
 * A way of reaching somebody.
 *
 * Behind an interface so an environment can use a null provider — the master command asks for it,
 * and it is what makes the whole notification path testable without an SMS bill or a Firebase
 * project.
 */
interface NotificationChannel
{
    /**
     * The channel's stable name, stored on every dispatch row.
     */
    public function name(): string;

    /**
     * Whether this environment has what this channel needs to run.
     *
     * A channel with no credentials reports false and its dispatches are recorded `skipped`
     * rather than `failed`. The difference matters on a dashboard: "we have no SMS provider
     * configured" is a deployment fact, and "the SMS provider rejected this" is an incident.
     */
    public function isConfigured(): bool;

    /**
     * Attempts delivery.
     *
     * @return DeliveryResult never throws for a provider-side failure. A notification is a
     *                        side effect of something that already happened — a family's
     *                        assistance was approved whether or not the text message lands, and
     *                        an exception escaping here would let a provider outage roll back
     *                        welfare work that was already committed.
     */
    public function send(OutboundNotification $message): DeliveryResult;
}
