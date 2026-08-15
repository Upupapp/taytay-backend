<?php

declare(strict_types=1);

namespace Modules\AccessControl\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\AccessControl\Domain\Role;
use Modules\AccessControl\Domain\RoleAssignmentRepository;

/**
 * Reads role assignments and barangay grants from the canonical tables.
 *
 * Every query joins nothing across a module boundary: `subject_id` is an account UUID held
 * by identifier only (CLAUDE.md Article 2.2).
 *
 * All three methods filter on the validity window, so an assignment that has not started
 * or has ended grants nothing while the row survives for the audit question "was this
 * person allowed to do that in March" (ADR 0008 §11).
 */
final class DatabaseRoleAssignmentRepository implements RoleAssignmentRepository
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @return list<string>
     */
    public function rolesFor(string $subjectId): array
    {
        return array_values(array_map(
            static fn (array $assignment): string => $assignment['role'],
            $this->assignmentsFor($subjectId),
        ));
    }

    /**
     * @return list<array{role: string, scope_type: string, barangay_id: int|null}>
     */
    public function assignmentsFor(string $subjectId): array
    {
        $rows = $this->liveAssignments($subjectId)
            ->get(['role', 'scope_type', 'barangay_id']);

        $assignments = [];

        foreach ($rows as $row) {
            // Deny by default: a role no longer in the catalog grants nothing, so removing
            // one from the enum revokes it everywhere without a data migration.
            if (! is_string($row->role) || Role::tryFrom($row->role) === null) {
                continue;
            }

            $assignments[] = [
                'role' => $row->role,
                'scope_type' => (string) $row->scope_type,
                'barangay_id' => $row->barangay_id === null ? null : (int) $row->barangay_id,
            ];
        }

        return $assignments;
    }

    /**
     * @return list<int>
     */
    public function grantedBarangayIdsFor(string $subjectId): array
    {
        $now = now();

        return array_values(array_map('intval', $this->connection->table('staff_barangay_grants')
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            ->where('valid_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->pluck('barangay_id')
            ->all()));
    }

    private function liveAssignments(string $subjectId): Builder
    {
        $now = now();

        return $this->connection->table('role_assignments')
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            ->where('valid_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now));
    }
}
