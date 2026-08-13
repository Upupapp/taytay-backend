<?php

declare(strict_types=1);

namespace Modules\AccessControl\Contracts;

/**
 * The permission catalog — the complete vocabulary of things an actor may be allowed to
 * do. AccessControl is the canonical owner (docs/architecture/domain-boundary-map.md).
 *
 * Published under Contracts/ rather than Domain/ precisely because other modules must be
 * able to name a permission: it is the one part of AccessControl they are allowed to
 * depend on directly (CLAUDE.md Article 2.1).
 *
 * Permissions are fine-grained verbs, never roles. Code asks "may this actor do X?",
 * never "is this actor an admin?", so that role composition can change without touching
 * call sites.
 */
enum Permission: string
{
    /** See catalog entries that are not published to citizens (drafts, retired). */
    case ServicesViewUnpublished = 'services.view_unpublished';

    /** Create, edit and publish catalog entries. */
    case ServicesManage = 'services.manage';

    public static function tryFromName(string $permission): ?self
    {
        return self::tryFrom($permission);
    }
}
