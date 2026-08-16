<?php

declare(strict_types=1);

namespace Modules\Tasks\Domain;

/**
 * Whether a task is still owed.
 */
enum TaskStatus: string
{
    /** Still owed. Counts towards the case being on time. */
    case Open = 'open';

    /** Completed, with the outcome recorded against a name. */
    case Done = 'done';

    /** No longer required. The reason is kept. */
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * Closing a task always records something.
     *
     * `done` needs an outcome because "what happened" is the only thing a completed task leaves
     * behind; `cancelled` needs a reason because a task that silently disappears is
     * indistinguishable from one nobody did.
     */
    public function requiresOutcome(): bool
    {
        return $this !== self::Open;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
