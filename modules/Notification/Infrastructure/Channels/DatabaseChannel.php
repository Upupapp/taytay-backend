<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Modules\Notification\Contracts\DeliveryResult;
use Modules\Notification\Contracts\NotificationChannel;
use Modules\Notification\Contracts\OutboundNotification;

/**
 * The in-app list.
 *
 * ALWAYS CONFIGURED AND ALWAYS DELIVERED, because the row already exists by the time a channel is
 * asked to send: writing the notification IS the database delivery. This adapter exists so the
 * dispatch table records it alongside the others rather than leaving the one channel that always
 * works as the one channel with no record.
 *
 * It is also the reason a provider outage never loses a notification: whatever happens to email,
 * SMS and push, the person still has it when they next open the app.
 */
final class DatabaseChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'database';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundNotification $message): DeliveryResult
    {
        return DeliveryResult::sent();
    }
}
