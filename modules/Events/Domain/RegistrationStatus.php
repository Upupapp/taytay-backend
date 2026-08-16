<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

/**
 * Where one person's registration stands.
 *
 * THREE STATES, AND `cancelled` IS A STATE RATHER THAN A DELETED ROW. "Did she register and pull
 * out, or never register at all?" is the question asked when somebody arrives insisting they were
 * on the list, and a hard delete makes both answers indistinguishable — while also erasing the
 * evidence that the seat was freed and given to the next person on the waitlist.
 */
enum RegistrationStatus: string
{
    /** Holds a seat. Counts against capacity. */
    case Registered = 'registered';

    /** In the queue. Counts against nothing. */
    case Waitlisted = 'waitlisted';

    case Cancelled = 'cancelled';

    /**
     * Whether this registration still belongs to the person.
     *
     * The predicate `active_key` is derived from: live registrations carry the resident id and
     * collide, cancelled ones carry NULL and do not.
     */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Whether this registration consumes a seat. */
    public function consumesCapacity(): bool
    {
        return $this === self::Registered;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
