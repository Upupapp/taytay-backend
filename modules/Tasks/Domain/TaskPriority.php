<?php

declare(strict_types=1);

namespace Modules\Tasks\Domain;

/**
 * How far up a queue a task sits.
 *
 * Ordering only. It confers nothing on the case behind the task — a task marked urgent does not
 * make an application more likely to be approved, and nothing in this system reads a task
 * priority when deciding anything about a family.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $priority): string => $priority->value, self::cases());
    }
}
