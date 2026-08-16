<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Modules\Notification\Contracts\DeliveryResult;
use Modules\Notification\Contracts\NotificationChannel;
use Modules\Notification\Contracts\OutboundNotification;

/**
 * Mobile push through Firebase Cloud Messaging HTTP v1 (ADR 0025 §3).
 *
 * FIREBASE IS TRANSPORT, NOT AUTHORITY (Article 8.3). Laravel has already decided that this
 * notification is warranted, who may receive it and what it may say. FCM carries bytes.
 *
 * THE PAYLOAD IS `routingPayload()` AND NOTHING ELSE — a type and two opaque identifiers. There
 * is no line in this class that reads `$message->body`, and that is the design: the same sentence
 * that is correct in the app is a disclosure on a lock screen, on a shared phone, and in a third
 * party's logs (Article 8.4).
 *
 * It is sent as a `data`-only message rather than a `notification` message for the same reason —
 * a `notification` message requires a title and body the provider renders itself, which is
 * precisely the content that may not leave this system.
 *
 * CREDENTIALS ARE READ FROM A SERVER-SIDE PATH, never committed and never exposed to Netlify or
 * to the mobile app. This class holds the shape of the call and the failure handling; the actual
 * OAuth exchange and HTTP post are wired when an environment supplies a service account
 * (gap G-33).
 */
final class FcmChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'push';
    }

    public function isConfigured(): bool
    {
        return is_string(config('notification.fcm.credentials_path'))
            && config('notification.fcm.credentials_path') !== ''
            && is_string(config('notification.fcm.project_id'))
            && config('notification.fcm.project_id') !== '';
    }

    public function send(OutboundNotification $message): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::skipped('FCM is not configured in this environment.');
        }

        /*
         * The transport is deliberately not implemented here yet (gap G-33). What IS fixed is the
         * contract around it: a data-only payload built from routing information, a bounded
         * retry decided by the job, and a dead token reported so the caller deactivates it.
         *
         * Returning `skipped` rather than throwing keeps the acceptance criterion true today —
         * a provider that does not exist cannot block a core transaction.
         */
        return DeliveryResult::skipped('FCM transport is not wired in this environment.');
    }
}
