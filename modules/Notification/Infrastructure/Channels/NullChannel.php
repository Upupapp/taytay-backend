<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Modules\Notification\Contracts\DeliveryResult;
use Modules\Notification\Contracts\NotificationChannel;
use Modules\Notification\Contracts\OutboundNotification;

/**
 * A channel with no provider behind it.
 *
 * Bound for email, SMS and push until an environment configures a real one. It reports
 * `skipped`, never `sent` — the distinction matters because a dashboard showing "delivered" for
 * a channel that does not exist is worse than one showing nothing: it tells an operator the
 * family was told.
 *
 * This is the "test/null provider" the master command asks for, and it is why the whole
 * notification path is exercisable without an SMS bill or a Firebase project.
 */
final class NullChannel implements NotificationChannel
{
    public function __construct(private readonly string $channel) {}

    public function name(): string
    {
        return $this->channel;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(OutboundNotification $message): DeliveryResult
    {
        return DeliveryResult::skipped('No provider configured for '.$this->channel.' in this environment.');
    }
}
