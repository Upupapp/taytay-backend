<?php

declare(strict_types=1);

namespace Modules\Notification\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Application\Notifier;
use Modules\Notification\Infrastructure\Eloquent\Notification;
use Modules\Notification\Infrastructure\Eloquent\NotificationPreference;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * A person's own notifications, devices and preferences (ADR 0025).
 *
 * EVERY ROUTE HERE IS `me`, and every lookup is scoped to the account behind the bearer token.
 * There is no notification id in any contract that reaches another person's record, and — the
 * acceptance criterion — **no field anywhere that accepts an account identifier**.
 *
 * DEVICE REGISTRATION IS NOT HERE. Identity owns `me/devices` and its `devices` table already
 * carries `push_token`. A duplicate surface was written for this TAB and removed: it shadowed
 * Identity's routes and would have drifted the moment a device was revoked in one place and kept
 * receiving push from the other (Article 6, ADR 0025 §5).
 */
final class MyNotificationController
{
    public function __construct(
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $pagination = PaginationParams::fromRequest($request);

        $query = Notification::query()
            ->where('recipient_subject_id', (string) $actor->subjectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Notification $notification): array => $this->projection($notification),
        );
    }

    public function markRead(Request $request, ActorContext $actor, string $notification): JsonResponse
    {
        return ApiResponse::item($this->projection(
            $this->notifier->markRead($this->ownNotificationOrFail($actor, $notification)),
        ));
    }

    public function markAllRead(Request $request, ActorContext $actor): JsonResponse
    {
        return ApiResponse::item([
            'marked' => $this->notifier->markAllRead((string) $actor->subjectId),
        ]);
    }

    // ── preferences ───────────────────────────────────────────────────────────────────

    public function preferences(Request $request, ActorContext $actor): JsonResponse
    {
        return ApiResponse::item([
            'preferences' => NotificationPreference::query()
                ->where('subject_id', (string) $actor->subjectId)
                ->get()
                ->map(static fn (NotificationPreference $preference): array => [
                    'notification_type' => $preference->notification_type,
                    'channel' => $preference->channel,
                    'enabled' => (bool) $preference->enabled,
                ])->all(),
            /*
             * Stated in the payload so a client can explain the switch it is not offering.
             *
             * A person who cannot turn something off is entitled to know that, and to know why —
             * an absent toggle with no explanation reads as a bug.
             */
            'mandatory_notice' => 'Service and security notices cannot be switched off.',
        ]);
    }

    public function updatePreference(Request $request, ActorContext $actor): JsonResponse
    {
        $validated = $request->validate([
            // `*` sets a whole channel.
            'notification_type' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'string', 'in:email,sms,push'],
            'enabled' => ['required', 'boolean'],
        ]);

        /*
         * `database` is absent from the allowed channels on purpose.
         *
         * Switching off email means "stop emailing me", not "stop keeping a record of what you
         * told me" — and a person who opted out of everything and then asks why they were never
         * informed deserves a list to be shown.
         */
        NotificationPreference::query()->updateOrCreate(
            [
                'subject_id' => (string) $actor->subjectId,
                'notification_type' => $validated['notification_type'],
                'channel' => $validated['channel'],
            ],
            ['enabled' => $validated['enabled']],
        );

        return $this->preferences($request, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Notification $notification): array
    {
        return [
            'id' => $notification->uuid,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            // The deep link: a type and an identifier. The client opens the record through its
            // own module's endpoint, where authorization is rechecked.
            'subject_type' => $notification->subject_type,
            'subject_id' => $notification->subject_id,
            'priority' => $notification->priority,
            'category' => $notification->category,
            'read_at' => $notification->read_at?->toIso8601ZuluString(),
            'created_at' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }

    private function ownNotificationOrFail(ActorContext $actor, string $uuid): Notification
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()
            ->where('uuid', $uuid)
            // Ownership is part of the lookup, not a check after it.
            ->where('recipient_subject_id', (string) $actor->subjectId)
            ->first();

        if ($notification === null) {
            throw ResourceNotFoundException::make('That notification was not found.');
        }

        return $notification;
    }
}
