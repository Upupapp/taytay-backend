<?php

declare(strict_types=1);

namespace Modules\AccessControl\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\AccessControl\Domain\Role;
use Modules\AccessControl\Domain\RoleAssignmentRepository;

/**
 * Reads role assignments from the canonical `role_assignments` table (gap G-09 closed).
 *
 * Replaces the provisional config map now that Identity's accounts exist and
 * `subject_id` has something real to mean. The interface is unchanged, so no call site
 * moved — which is the whole point of having had the seam since TAB 01.
 *
 * The query joins nothing. `subject_id` is an account UUID held by identifier only, and
 * AccessControl must not reach into Identity's tables (CLAUDE.md Article 2.2).
 */
final class DatabaseRoleAssignmentRepository implements RoleAssignmentRepository
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @return list<string>
     */
    public function rolesFor(string $subjectId): array
    {
        $now = now();

        $roles = $this->connection->table('role_assignments')
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            // Effective dating (ADR 0008 §11): an assignment that has not started, or has
            // ended, grants nothing. "Was this person allowed to do that in March" stays
            // answerable because the row survives its own expiry.
            ->where('valid_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->pluck('role')
            ->all();

        // Deny by default: a role that is no longer in the catalog grants nothing, so
        // removing a role from the enum revokes it everywhere without a data migration.
        return array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && Role::tryFrom($role) !== null,
        ));
    }
}
