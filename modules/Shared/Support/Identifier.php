<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

use Illuminate\Support\Str;

/**
 * Telling one kind of public identifier from another, before it reaches a typed column.
 *
 * ## The defect this exists for
 *
 * Several records are reachable by **either** a UUID or a human identifier — an event by the slug
 * printed on a poster, a registration by the reference a resident was given. The natural way to
 * write that is one query with both:
 *
 *     ->where('uuid', $identifier)->orWhere('slug', $identifier)
 *
 * On SQLite that works, because SQLite compares a `uuid` column as text. **On PostgreSQL the whole
 * statement fails** with `SQLSTATE[22P02] invalid input syntax for type uuid` — the comparison is
 * type-checked even though the other branch would have matched. So an event reached by its slug
 * returned a 500, and the suite was green throughout because it only ever ran on SQLite.
 *
 * The fix is not to stop offering both. It is to ask **which one this is** before comparing, so the
 * uuid branch is only built when the value could be a uuid.
 */
final class Identifier
{
    /**
     * Whether this could be a UUID, and therefore whether it is safe to compare against one.
     *
     * `Str::isUuid` rather than a hand-written pattern: it is the same check Laravel's own route
     * binding uses, and a second regex is a second definition of "looks like a UUID" to drift.
     */
    public static function isUuid(string $value): bool
    {
        return Str::isUuid($value);
    }
}
