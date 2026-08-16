<?php

declare(strict_types=1);

namespace Modules\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Identity\Application\DeviceService;
use Modules\Notification\Application\ChannelRegistry;
use Modules\Notification\Contracts\OutboundNotification;
use Modules\Notification\Infrastructure\Eloquent\Notification;
use Modules\Notification\Infrastructure\Eloquent\NotificationDispatch;

/**
 * Delivers one notification across its channels (ADR 0025 §2).
 *
 * QUEUED, AND DISPATCHED `afterCommit` BY THE CALLER. Two acceptance criteria meet here:
 * notifications are not sent before the transaction that caused them commits, and a provider
 * outage does not block a core API transaction. The second is held by this job existing at all —
 * the request that approved a case returned long before this runs.
 *
 * A CHANNEL FAILURE IS NOT A JOB FAILURE. Each channel is attempted independently and its verdict
 * recorded; one dead provider does not stop the others, and the whole job does not retry because
 * the SMS gateway was down while the email went out fine. Retrying is per-dispatch and bounded.
 *
 * THE JOB CARRIES A UUID, NOT A MODEL. A serialised model is a copy of the row as it was when
 * queued, and this one is about to be written to.
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Bounded. A notification nobody could deliver in three attempts is not improved by thirty. */
    public int $tries = 3;

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        private readonly string $notificationUuid,
        private readonly array $channels,
    ) {}

    public function handle(ChannelRegistry $registry, DeviceService $devices): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()->where('uuid', $this->notificationUuid)->first();

        // Deleted between queueing and running. Nothing to do, and nothing wrong.
        if ($notification === null) {
            return;
        }

        $message = new OutboundNotification(
            notificationId: (string) $notification->uuid,
            recipientSubjectId: (string) $notification->recipient_subject_id,
            type: (string) $notification->type,
            title: (string) $notification->title,
            body: (string) $notification->body,
            subjectType: $notification->subject_type,
            subjectId: $notification->subject_id,
            priority: (string) $notification->priority,
        );

        foreach ($this->channels as $channelName) {
            $channel = $registry->get($channelName);

            if ($channel === null) {
                continue;
            }

            /** @var NotificationDispatch $dispatch */
            $dispatch = NotificationDispatch::query()->firstOrCreate(
                ['notification_id' => $notification->id, 'channel' => $channelName],
                ['status' => 'pending'],
            );

            // Already delivered on a previous run of this job. A retry must not re-send a message
            // somebody has already received.
            if ($dispatch->status === 'sent') {
                continue;
            }

            $result = $channel->send($message);

            $dispatch->forceFill([
                'status' => $result->status,
                'provider_message_id' => $result->providerMessageId,
                'failure_reason' => $result->failureReason,
                'attempts' => (int) $dispatch->attempts + 1,
                'last_attempted_at' => now(),
                'delivered_at' => $result->wasSent() ? now() : null,
            ])->save();

            /*
             * A dead token is deactivated rather than retried.
             *
             * Without this the same unregistered token is attempted on every notification
             * forever, and a person who changed phones generates a permanent stream of failures
             * indistinguishable from a real outage.
             */
            if ($result->tokenIsDead) {
                $this->deactivateTokensFor($devices, (string) $notification->recipient_subject_id, $result->failureReason);
            }
        }
    }

    /**
     * Clears a dead push token on Identity's device record.
     *
     * IDENTITY OWNS DEVICES — the fingerprint, the trust stamp, the revocation and the push token
     * are one record, and this module does not keep a second one (Article 6). Clearing the token
     * rather than revoking the device is the right granularity: the phone is still a trusted
     * device the person logs in from, it just cannot receive push any more.
     */
    private function deactivateTokensFor(DeviceService $devices, string $subjectId, ?string $reason): void
    {
        $devices->clearDeadPushToken($subjectId, $reason ?? 'Rejected by the push provider.');
    }
}
