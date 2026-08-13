<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

/**
 * Who is acting, as resolved by the server.
 *
 * This is the ONLY sanctioned way to carry actor identity into an application service
 * (ADR 0002). It is always built server-side by AccessControl's ActorContextFactory from
 * authenticated state plus persisted role assignments.
 *
 * That factory is referred to by name rather than by an `{@see}` tag on purpose: Pint's
 * fully_qualified_strict_types fixer promotes such tags to real imports, which would make
 * the shared kernel depend on a domain module (CLAUDE.md Article 2.3).
 *
 * Nothing here may ever be populated from a client-supplied role, permission list or
 * `is_admin` flag. A client cannot construct one of these.
 */
final readonly class ActorContext
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    private function __construct(
        public ?string $subjectId,
        public array $roles,
        public array $permissions,
        public ClientChannel $channel,
    ) {}

    public static function guest(ClientChannel $channel = ClientChannel::Unknown): self
    {
        return new self(null, [], [], $channel);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public static function authenticated(
        string $subjectId,
        array $roles = [],
        array $permissions = [],
        ClientChannel $channel = ClientChannel::Unknown,
    ): self {
        return new self(
            $subjectId,
            array_values(array_unique($roles)),
            array_values(array_unique($permissions)),
            $channel,
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->subjectId !== null;
    }

    public function isGuest(): bool
    {
        return $this->subjectId === null;
    }

    /**
     * Deny by default: an unknown permission is simply absent.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Ownership check for object-level authorization (OWASP API1). Callers must use this
     * rather than comparing identifiers ad hoc.
     */
    public function isSubject(?string $subjectId): bool
    {
        return $subjectId !== null
            && $this->subjectId !== null
            && hash_equals($this->subjectId, $subjectId);
    }

    /**
     * Safe for audit logs: identifiers and authority only, never personal data.
     *
     * @return array{subject_id: string|null, roles: list<string>, channel: string}
     */
    public function forAudit(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'roles' => $this->roles,
            'channel' => $this->channel->value,
        ];
    }
}
