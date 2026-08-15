<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Modules\AccessControl\Domain\RoleAssignmentRepository;
use Modules\Shared\Application\DataScope;

/**
 * Turns persisted assignments and grants into the scope an actor actually has (ADR 0012).
 *
 * This is the class TAB 06 was missing. `role_assignments.scope_type` existed and nothing
 * read it, so a barangay-scoped clerk could read every resident in the municipality.
 *
 * Two rules decide everything here:
 *
 * 1. **The widest live assignment wins.** Someone who is both a barangay clerk and a
 *    municipal auditor is a municipal auditor — otherwise adding a narrow role would
 *    silently *remove* access they legitimately hold, and the fix would look like
 *    "give them another role", which ratchets permissions upward.
 * 2. **Deny by default.** No assignment, an expired one, or a scope value the catalog no
 *    longer recognises resolves to {@see DataScope::none()} — which reaches nothing, not
 *    everything.
 */
final class ScopeResolver
{
    public function __construct(private readonly RoleAssignmentRepository $assignments) {}

    public function forSubject(string $subjectId): DataScope
    {
        $assignments = $this->assignments->assignmentsFor($subjectId);

        if ($assignments === []) {
            return DataScope::none();
        }

        $types = array_column($assignments, 'scope_type');

        // Widest first. `all-barangays` ignores barangay ids entirely, so there is nothing
        // to collect.
        if (in_array(DataScope::ALL_BARANGAYS, $types, true)) {
            return DataScope::municipality();
        }

        /*
         * Own barangays plus explicit grants.
         *
         * The grant is what makes "cannot read other barangays *without explicit grant*"
         * satisfiable: coverage is widened by a recorded, reasoned, time-bounded decision
         * rather than by changing someone's job.
         */
        $barangayIds = array_values(array_filter(
            array_column($assignments, 'barangay_id'),
            static fn (?int $id): bool => $id !== null,
        ));

        $barangayIds = array_merge($barangayIds, $this->assignments->grantedBarangayIdsFor($subjectId));

        if (in_array(DataScope::OWN_BARANGAY, $types, true)) {
            return DataScope::barangays($barangayIds);
        }

        if (in_array(DataScope::ASSIGNED_CASES, $types, true)) {
            // The narrowest scope: the barangay bound *and* ownership of the record.
            return DataScope::assignedCases($barangayIds);
        }

        // A scope_type the catalog does not recognise. Fail closed rather than guessing.
        return DataScope::none();
    }
}
