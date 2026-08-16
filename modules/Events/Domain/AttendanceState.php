<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

/**
 * Whether somebody who held a seat actually turned up.
 *
 * `not-checked-in` IS THE DEFAULT AND IT IS NOT `no-show`. Before the event, and for anybody the
 * door never got to, "we have not marked this person" is the truth. Defaulting to `no-show` would
 * mean every registrant at an event nobody bothered to check in is recorded as having failed to
 * attend — and a no-show record is the kind of thing that quietly shapes who gets a seat next
 * time.
 *
 * Marking is therefore always an act somebody performed, and it is audited as one.
 */
enum AttendanceState: string
{
    case NotCheckedIn = 'not-checked-in';
    case Attended = 'attended';
    case NoShow = 'no-show';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
