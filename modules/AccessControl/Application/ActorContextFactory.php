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
        private readonly ScopeResolver $scopes,
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

        /*
         * An account that is no longer active carries no authority, even holding a valid
         * token (ADR 0012). Suspension has to bite before the token expires, or revoking a
         * staff member's access means waiting up to twelve hours for it to take effect.
         *
         * Guest rather than an exception: the caller is still authenticated, they simply
         * reach nothing. Endpoints then fail with their own 403/404 rather than a special
         * case that every controller would have to know about.
         */
        if (method_exists($user, 'canAuthenticate') && ! $user->canAuthenticate()) {
            return ActorContext::guest($this->requestContext->channel());
        }

        // The subject is the account's public UUID, which is what role_assignments and
        // audit_entries reference. Never the autoincrement id (ADR 0008 §1).
        $subjectId = $user->getAttribute('uuid') ?? $user->getAuthIdentifier();

        return $this->forSubject((string) $subjectId);
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
            // Resolved from current database state on every request, so a revoked
            // assignment stops applying immediately rather than when the token expires.
            scope: $this->scopes->forSubject($subjectId),
        );
    }
}
