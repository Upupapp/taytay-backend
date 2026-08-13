<?php

declare(strict_types=1);

namespace Modules\AccessControl\Infrastructure;

use Illuminate\Contracts\Config\Repository as Config;
use Modules\AccessControl\Domain\Role;
use Modules\AccessControl\Domain\RoleAssignmentRepository;

/**
 * PROVISIONAL role assignment store, backed by config/access_control.php.
 *
 * TAB 01 delivers the architecture, not the Identity domain. Role assignment will become
 * a persisted, audited AccessControl table once Identity owns accounts (see
 * docs/architecture/domain-boundary-map.md). The seam — {@see RoleAssignmentRepository} —
 * is what matters now: swapping this implementation for a database-backed one changes no
 * call site.
 *
 * Because it is config-backed, assignments are deployment-time only: there is no runtime
 * privilege escalation path through this class.
 */
final class ConfigRoleAssignmentRepository implements RoleAssignmentRepository
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return list<string>
     */
    public function rolesFor(string $subjectId): array
    {
        /** @var array<string, list<string>> $assignments */
        $assignments = $this->config->get('access_control.assignments', []);

        $roles = $assignments[$subjectId] ?? [];

        // Deny by default: unknown role names are dropped rather than passed through, so
        // a typo in configuration cannot silently widen access.
        return array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && Role::tryFrom($role) !== null,
        ));
    }
}
