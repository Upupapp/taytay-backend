<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\AccessControl\Contracts\Permission;
use Modules\AccessControl\Domain\Role;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\DataScope;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\AuthorizationDeniedException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Granting, changing and revoking staff authority (ADR 0012).
 *
 * Provisioning is the highest-privilege operation in the system: whoever can grant a role
 * can grant themselves anything, unless something stops them. Three rules do:
 *
 * 1. **No escalation.** You cannot grant a role whose permissions exceed your own. A
 *    barangay clerk with `staff.manage` cannot mint an `lgu_admin`, which is the obvious
 *    attack and the one people forget.
 * 2. **No self-service.** You cannot change your own roles or grants at all. Separation of
 *    duties is not a preference here — an administrator who can widen their own scope has
 *    no scope.
 * 3. **No scope laundering.** You cannot grant a barangay you cannot reach yourself.
 *
 * Every change is audited and every write is transactional and idempotent.
 */
final class StaffProvisioningService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuthorizationService $authorization,
        private readonly AccessControlAudit $audit,
    ) {}

    /**
     * Assigns a role, or updates the scope of one already held.
     *
     * Idempotent by (subject, role) — the table's unique key — so a retried request
     * updates rather than failing or duplicating.
     */
    public function assignRole(
        ActorContext $actor,
        string $subjectId,
        Role $role,
        string $scopeType,
        ?int $barangayId,
    ): void {
        $this->authorization->authorize($actor, Permission::StaffManage);
        $this->assertNotSelf($actor, $subjectId, 'assign a role to');
        $this->assertMayGrantRole($actor, $role);
        $this->assertScopeIsGrantable($actor, $scopeType, $barangayId);

        $this->connection->transaction(function () use ($actor, $subjectId, $role, $scopeType, $barangayId): void {
            $existing = $this->connection->table('role_assignments')
                ->where('subject_id', $subjectId)
                ->where('role', $role->value)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'scope_type' => $scopeType,
                'barangay_id' => $barangayId,
                'granted_by' => $actor->subjectId,
                'valid_from' => now(),
                // Re-assigning revives a previously revoked role rather than leaving a
                // tombstone that silently keeps it inactive.
                'valid_until' => null,
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($existing !== null) {
                $this->connection->table('role_assignments')->where('id', $existing->id)->update($attributes);
            } else {
                $this->connection->table('role_assignments')->insert($attributes + [
                    'uuid' => (string) Str::uuid7(),
                    'subject_id' => $subjectId,
                    'role' => $role->value,
                    'created_at' => now(),
                ]);
            }
        });

        $this->audit->record($actor, 'access.role-assigned', "Role {$role->value} assigned ({$scopeType})", $subjectId);
    }

    /**
     * Revokes a role by ending its validity rather than deleting the row.
     *
     * The row is the answer to "was this person allowed to approve that in March", and a
     * deleted row answers it wrongly.
     */
    public function revokeRole(ActorContext $actor, string $subjectId, Role $role): void
    {
        $this->authorization->authorize($actor, Permission::StaffManage);
        $this->assertNotSelf($actor, $subjectId, 'revoke a role from');
        $this->assertMayGrantRole($actor, $role);

        $this->connection->table('role_assignments')
            ->where('subject_id', $subjectId)
            ->where('role', $role->value)
            ->whereNull('deleted_at')
            ->update(['valid_until' => now(), 'updated_at' => now()]);

        $this->audit->record($actor, 'access.role-revoked', "Role {$role->value} revoked", $subjectId);
    }

    /**
     * Grants one extra barangay, with a reason and an end date.
     *
     * This is the "explicit grant" that lets a barangay-scoped clerk cover a second
     * barangay. Everything about it is deliberate friction: a reason is required, the
     * granter must be able to reach that barangay themselves, and it is recorded.
     */
    public function grantBarangay(
        ActorContext $actor,
        string $subjectId,
        int $barangayId,
        string $reason,
        ?string $validUntil,
    ): void {
        $this->authorization->authorize($actor, Permission::StaffManage);
        $this->assertNotSelf($actor, $subjectId, 'grant a barangay to');

        // No scope laundering: an actor cannot hand out reach they do not have.
        if (! $this->authorization->allowsBarangay($actor, $barangayId)) {
            throw AuthorizationDeniedException::forPermission(Permission::StaffManage->value);
        }

        $this->connection->transaction(function () use ($actor, $subjectId, $barangayId, $reason, $validUntil): void {
            $existing = $this->connection->table('staff_barangay_grants')
                ->where('subject_id', $subjectId)
                ->where('barangay_id', $barangayId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'reason' => $reason,
                'granted_by' => (string) $actor->subjectId,
                'valid_from' => now(),
                'valid_until' => $validUntil,
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($existing !== null) {
                $this->connection->table('staff_barangay_grants')->where('id', $existing->id)->update($attributes);
            } else {
                $this->connection->table('staff_barangay_grants')->insert($attributes + [
                    'uuid' => (string) Str::uuid7(),
                    'subject_id' => $subjectId,
                    'barangay_id' => $barangayId,
                    'created_at' => now(),
                ]);
            }
        });

        $this->audit->record($actor, 'access.barangay-granted', "Barangay access granted: {$reason}", $subjectId);
    }

    public function revokeBarangayGrant(ActorContext $actor, string $subjectId, int $barangayId): void
    {
        $this->authorization->authorize($actor, Permission::StaffManage);
        $this->assertNotSelf($actor, $subjectId, 'revoke a barangay grant from');

        $this->connection->table('staff_barangay_grants')
            ->where('subject_id', $subjectId)
            ->where('barangay_id', $barangayId)
            ->whereNull('deleted_at')
            ->update(['valid_until' => now(), 'updated_at' => now()]);

        $this->audit->record($actor, 'access.barangay-grant-revoked', 'Barangay access revoked', $subjectId);
    }

    /**
     * The effective authority of a staff member, as the server sees it.
     *
     * @return array{roles: list<string>, permissions: list<string>, scope: array{type: string, barangay_ids: list<int>}}
     */
    public function describeAuthority(string $subjectId, ScopeResolver $scopes, array $roles): array
    {
        return [
            'roles' => $roles,
            'permissions' => Role::permissionsFor($roles),
            'scope' => $scopes->forSubject($subjectId)->forAudit(),
        ];
    }

    /**
     * A granter may never hand out *administrative* authority they do not hold.
     *
     * The rule is deliberately about administrative permissions rather than all of them.
     * Requiring a provisioner to personally hold every operational permission they grant
     * would mean only a super-admin could staff the office — which is the concentration of
     * power this whole design exists to avoid. A security officer appoints KYC reviewers
     * without being able to approve a case; that is separation of duties, not a hole.
     *
     * What the rule does block is the escalation loop: grant a colleague the ability to
     * provision, have them grant you back whatever you were refused. Because `staff.manage`
     * cannot be handed to anyone by someone who lacks it, and nobody may act on their own
     * account, there is no two-step path from any role to a wider one.
     *
     * Residual risk, stated rather than hidden: a provisioner can create an account, grant
     * it an operational role and then use it if they control its mailbox. That is collusion
     * or impersonation, not an authorization defect — it is caught by the audit trail
     * (every grant names granter and grantee) and by requiring staff MFA, not by refusing
     * provisioners the ability to staff.
     */
    private function assertMayGrantRole(ActorContext $actor, Role $role): void
    {
        $administrative = array_values(array_map(
            static fn (Permission $permission): string => $permission->value,
            array_filter(
                $role->permissions(),
                static fn (Permission $permission): bool => $permission->isAdministrative(),
            ),
        ));

        $exceeds = array_diff($administrative, $actor->permissions);

        if ($exceeds !== []) {
            throw AuthorizationDeniedException::forPermission((string) reset($exceeds));
        }
    }

    private function assertScopeIsGrantable(ActorContext $actor, string $scopeType, ?int $barangayId): void
    {
        if (! in_array($scopeType, [DataScope::ALL_BARANGAYS, DataScope::OWN_BARANGAY, DataScope::ASSIGNED_CASES], true)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'That data scope is not recognised.');
        }

        // Municipality-wide authority can only be handed out by someone who has it.
        if ($scopeType === DataScope::ALL_BARANGAYS && ! $actor->scope->isUnrestricted()) {
            throw AuthorizationDeniedException::forPermission(Permission::StaffManage->value);
        }

        if ($scopeType !== DataScope::ALL_BARANGAYS && $barangayId === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A barangay is required for that data scope.');
        }

        if ($barangayId !== null && ! $this->authorization->allowsBarangay($actor, $barangayId)) {
            throw AuthorizationDeniedException::forPermission(Permission::StaffManage->value);
        }
    }

    /**
     * Nobody provisions themselves.
     *
     * An administrator who can widen their own scope has no scope, and the audit trail
     * cannot tell a legitimate change from an escalation.
     */
    private function assertNotSelf(ActorContext $actor, string $subjectId, string $action): void
    {
        if ($actor->isSubject($subjectId)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                "You cannot {$action} your own account. Ask another administrator.",
            );
        }
    }
}
