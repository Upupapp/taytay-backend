<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\AccessControl\Domain\Role;
use Modules\AccessControl\Domain\RoleAssignmentRepository;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\RequestContext;

/**
 * Builds the ActorContext for the current request — entirely from server-side state.
 *
 * The ONLY inputs are (a) the authenticated subject resolved by the auth guard and
 * (b) role assignments read from AccessControl's own store. Nothing a client sends about
 * its own authority is read here, which is what makes ADR 0002's guarantee testable: a
 * request cannot inject a role, and an unauthenticated request always yields a guest with
 * zero permissions.
 */
final class ActorContextFactory
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly RoleAssignmentRepository $roleAssignments,
        private readonly RequestContext $requestContext,
        private readonly string $guard,
    ) {}

    public function forCurrentRequest(): ActorContext
    {
        // The API guard is named explicitly rather than taken from the default guard,
        // because public routes carry no auth middleware to set one. A published service
        // list must still know it is being read by an LGU admin — authority follows the
        // actor, not the route (ADR 0002).
        $user = $this->auth->guard($this->guard)->user();

        if ($user === null) {
            return ActorContext::guest($this->requestContext->channel());
        }

        return $this->forSubject((string) $user->getAuthIdentifier());
    }

    public function forSubject(string $subjectId): ActorContext
    {
        $roles = $this->roleAssignments->rolesFor($subjectId);

        return ActorContext::authenticated(
            subjectId: $subjectId,
            roles: $roles,
            // Permissions are always derived server-side from the role catalog; they are
            // never stored per account and never accepted from a client.
            permissions: Role::permissionsFor($roles),
            channel: $this->requestContext->channel(),
        );
    }
}
