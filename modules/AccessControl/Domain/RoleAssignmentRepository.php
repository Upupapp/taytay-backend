<?php

declare(strict_types=1);

namespace Modules\AccessControl\Domain;

/**
 * Resolves the roles assigned to an authenticated subject.
 *
 * Subjects are referenced by identifier only — AccessControl never joins to Identity's
 * tables (CLAUDE.md Article 2.2).
 */
interface RoleAssignmentRepository
{
    /**
     * @return list<string> role names; an unknown subject yields [] (deny by default)
     */
    public function rolesFor(string $subjectId): array;
}
