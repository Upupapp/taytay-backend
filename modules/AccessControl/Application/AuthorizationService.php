<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\AuthorizationDeniedException;

/**
 * The single server-side authorization decision point (ADR 0002).
 *
 * Every module asks this service; no module reimplements a check. Because the decision
 * lives in one place, later requirements (office/jurisdiction scoping, delegated
 * guardianship, break-glass auditing) extend authorization everywhere at once.
 *
 * Deny by default: anything not explicitly granted is refused, including permission names
 * outside the catalog.
 */
final class AuthorizationService
{
    public function allows(ActorContext $actor, Permission|string $permission): bool
    {
        $name = $permission instanceof Permission ? $permission->value : $permission;

        // An unrecognised permission name is refused rather than ignored: a typo in a
        // call site must fail closed, never open.
        if (Permission::tryFromName($name) === null) {
            return false;
        }

        return $actor->hasPermission($name);
    }

    public function denies(ActorContext $actor, Permission|string $permission): bool
    {
        return ! $this->allows($actor, $permission);
    }

    /**
     * @throws AuthorizationDeniedException
     */
    public function authorize(ActorContext $actor, Permission|string $permission): void
    {
        if ($this->denies($actor, $permission)) {
            throw AuthorizationDeniedException::forPermission(
                $permission instanceof Permission ? $permission->value : $permission,
            );
        }
    }
}
