<?php

declare(strict_types=1);

namespace Modules\Notification\Contracts;

/**
 * What a channel is asked to deliver.
 *
 * TWO SHAPES IN ONE OBJECT, AND CHANNELS TAKE DIFFERENT PARTS. `title` and `body` are rendered
 * text for channels that reach an authenticated surface. `type` and the subject pointer are the
 * routing information — the ONLY thing a push provider may be given (Article 8.4).
 *
 * A push adapter that reached for `body` would put "Your AICS assistance of ₱5,000 is ready at the
 * barangay hall" onto a lock screen, into a shared phone's notification shade and into a
 * third-party's logs. The separation is here rather than in each adapter so that reaching for the
 * wrong field is a visible choice in a reviewable file.
 */
final class OutboundNotification
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $recipientSubjectId,
        public readonly string $type,
        /** Rendered. For authenticated surfaces only. */
        public readonly string $title,
        /** Rendered. For authenticated surfaces only. */
        public readonly string $body,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly string $priority,
    ) {}

    /**
     * The only payload a third-party push provider may be handed.
     *
     * A type, an opaque notification id and an opaque subject pointer. The client opens the app,
     * authenticates, and fetches the detail over the API where authorization is rechecked — which
     * is what the master command means by "notification payloads contain only the minimum safe
     * routing information".
     *
     * @return array<string, string>
     */
    public function routingPayload(): array
    {
        return array_filter([
            'type' => $this->type,
            'notification_id' => $this->notificationId,
            'subject_type' => (string) $this->subjectType,
            'subject_id' => (string) $this->subjectId,
        ], static fn (string $value): bool => $value !== '');
    }
}
