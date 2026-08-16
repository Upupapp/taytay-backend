<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * How closely a case note is held.
 *
 * TWO TIERS, AND THE SECOND IS NARROW ON PURPOSE. A protected tier that catches half the record
 * protects nothing — it just means the ordinary running record is unreadable to the people doing
 * the work, and they stop writing it down.
 */
enum NoteSensitivity: string
{
    /**
     * The ordinary running record — a home visit, a phone call, a document received.
     *
     * Anyone who may open the case may read it. That is the point: a caseworker covering for a
     * colleague needs the file to be usable.
     */
    case Routine = 'routine';

    /**
     * Safety planning for a VAWC survivor (RA 9262), anything identifying a child in conflict
     * with the law (RA 9344), a third party's disclosure given in confidence, or clinical detail.
     */
    case Protected = 'protected';

    public function requiredPermission(): Permission
    {
        return $this === self::Protected
            ? Permission::CaseNoteViewProtected
            : Permission::RequestView;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
