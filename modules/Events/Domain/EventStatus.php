<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * Where an event stands.
 *
 * `Cancelled` IS VISIBLE, and that is the one people get wrong. An event that was published and
 * then called off stays on the public list with its cancellation showing — people arranged their
 * day around it, and removing it silently means somebody travels to a covered court to find
 * nobody there.
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /** Called off. Still visible if it was ever published. */
    case Cancelled = 'cancelled';

    /** It happened. Still visible, because "was there one last August?" is a real question. */
    case Completed = 'completed';

    /** Off the public list. The record survives for reporting. */
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Cancelled, self::Completed, self::Archived],
            // A cancelled event is not un-cancelled: people were told it was off, and telling them
            // it is back on is a new announcement, not a status change.
            self::Cancelled => [self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Whether a citizen may see it at all.
     *
     * Archived is the only public-invisible state besides draft. Cancelled and completed both stay
     * on the list, for different reasons that both come down to somebody planning their week
     * around what the office said.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published || $this === self::Cancelled || $this === self::Completed;
    }

    /** Whether anybody may still register. */
    public function acceptsRegistrations(): bool
    {
        return $this === self::Published;
    }

    public function requiresReason(): bool
    {
        return $this === self::Cancelled;
    }

    public function requiredPermission(): Permission
    {
        return match ($this) {
            self::Published, self::Cancelled => Permission::EventPublish,
            default => Permission::EventManage,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
