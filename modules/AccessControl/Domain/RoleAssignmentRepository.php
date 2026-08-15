<?php

declare(strict_types=1);

namespace Modules\AccessControl\Domain;

/**
 * Resolves the roles and scope assigned to an authenticated subject.
 *
 * Subjects are referenced by identifier only — AccessControl never joins to Identity's
 * tables (CLAUDE.md Article 2.2).
 *
 * Everything here is read from **current database state on every request**. Authority is
 * never taken from a token claim: a token issued before a role was revoked must not still
 * carry it, and the only way to guarantee that is to look each time (ADR 0012).
 */
interface RoleAssignmentRepository
{
    /**
     * @return list<string> role names; an unknown subject yields [] (deny by default)
     */
    public function rolesFor(string $subjectId): array;

    /**
     * The live assignments, with the scope each one carries.
     *
     * @return list<array{role: string, scope_type: string, barangay_id: int|null}>
     */
    public function assignmentsFor(string $subjectId): array;

    /**
     * Barangays granted to this subject beyond their own, within the grant's validity
     * window.
     *
     * @return list<int>
     */
    public function grantedBarangayIdsFor(string $subjectId): array;
}
