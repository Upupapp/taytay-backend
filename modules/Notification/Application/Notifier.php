<?php

declare(strict_types=1);

namespace Modules\Notification\Application;

use Illuminate\Support\Facades\DB;
use Modules\Notification\Infrastructure\Eloquent\Notification;
use Modules\Notification\Infrastructure\Eloquent\NotificationPreference;
use Modules\Notification\Jobs\DeliverNotification;

/**
 * Deciding that somebody should be told something (ADR 0025).
 *
 * THE ACCEPTANCE CRITERION THIS CLASS HOLDS: notifications are not sent before the transaction
 * that caused them commits. Every dispatch is queued with `afterCommit()`, so a case approval that
 * rolls back cannot leave a family already told they were approved — a message that cannot be
 * unsent, about a decision that never happened.
 *
 * AND THE ONE IT HOLDS BY OMISSION: a provider outage cannot block a core transaction. Nothing
 * here calls a channel. The row is written, a job is queued, and the request returns; the
 * assistance was approved whether or not the text message lands.
 */
final class Notifier
{
    /**
     * Records a notification and queues its delivery.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $channels
     */
    public function notify(
        string $recipientSubjectId,
        string $type,
        array $attributes,
        array $channels = ['database'],
    ): Notification {
        /** @var Notification $notification */
        $notification = Notification::query()->create([
            'recipient_subject_id' => $recipientSubjectId,
            'type' => $type,
            'category' => $attributes['category'] ?? 'optional',
            /*
             * Rendered here, and held because this row is read back over an authenticated API.
             * It is not what reaches a push provider — see OutboundNotification::routingPayload().
             */
            'title' => (string) $attributes['title'],
            'body' => (string) $attributes['body'],
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'priority' => $attributes['priority'] ?? 'normal',
        ]);

        $wanted = $this->channelsFor($recipientSubjectId, $type, $channels, (string) $notification->category);

        /*
         * AFTER COMMIT. The single most important line in this class.
         *
         * Queued inside the transaction, a worker can pick the job up before the row exists — or
         * worse, after a rollback, telling somebody about a decision that was undone. A family
         * told their assistance was approved cannot be un-told.
         */
        DeliverNotification::dispatch((string) $notification->uuid, $wanted)->afterCommit();

        return $notification;
    }

    /**
     * Which channels this notification may actually use.
     *
     * MANDATORY NOTICES IGNORE PREFERENCES. A scheduled release date and a security alert on an
     * account are things the office must be able to send; letting them be switched off would mean
     * somebody misses a payout because of a toggle they set months earlier.
     *
     * Everything else is opt-out, and the absence of a preference row means "on" — so a
     * notification type added next year reaches people rather than silently reaching nobody
     * because no row exists for it yet.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function channelsFor(string $subjectId, string $type, array $channels, string $category): array
    {
        if ($category === 'mandatory') {
            return $channels;
        }

        $disabled = NotificationPreference::query()
            ->where('subject_id', $subjectId)
            ->where('enabled', false)
            ->whereIn('notification_type', [$type, '*'])
            ->pluck('channel')
            ->all();

        $allowed = array_values(array_diff($channels, $disabled));

        /*
         * The in-app record always survives a preference.
         *
         * Switching off email means "stop emailing me", not "stop keeping a record of what you
         * told me" — and a person who opted out of everything and then asks why they were never
         * informed deserves a list to be shown.
         */
        return in_array('database', $allowed, true) ? $allowed : array_merge(['database'], $allowed);
    }

    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    public function markAllRead(string $subjectId): int
    {
        return DB::table('notifications')
            ->where('recipient_subject_id', $subjectId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
