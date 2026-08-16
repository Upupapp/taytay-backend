<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * How a field visit ended.
 *
 * `NotFound` and `Refused` are held apart, and both apart from `Cancelled`. "Nobody was home",
 * "the family asked us to leave" and "the office called it off" are three different facts about a
 * household, and only one of them should ever colour how the next visit is planned. Collapsing
 * them into "unsuccessful" is how a family that was out at work acquires a reputation for being
 * uncooperative.
 */
enum VisitStatus: string
{
    case Scheduled = 'scheduled';

    /** The worker reached the household and recorded what they found. */
    case Completed = 'completed';

    /** The worker attended and found nobody. The household did nothing. */
    case NotFound = 'not-found';

    /** The household declined. Their reason is recorded if they gave one. */
    case Refused = 'refused';

    /** Called off by the office before it was made. */
    case Cancelled = 'cancelled';

    /**
     * Every outcome is terminal.
     *
     * A visit that happened, happened. A second attempt is a second visit, so "how many times did
     * we go?" keeps exactly one answer — and a household is never shown as having been visited
     * once when a worker travelled three times.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return $this === self::Scheduled
            ? [self::Completed, self::NotFound, self::Refused, self::Cancelled]
            : [];
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Scheduled;
    }

    /** The worker attended, whatever the household did when they arrived. */
    public function wasAttended(): bool
    {
        return $this === self::Completed || $this === self::NotFound || $this === self::Refused;
    }

    /** Only a completed visit records what was found; the others record why it did not happen. */
    public function requiresOutcome(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
