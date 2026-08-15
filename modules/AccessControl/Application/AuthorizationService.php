<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Illuminate\Database\Eloquent\Builder;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\AuthorizationDeniedException;
use Modules\Shared\Exceptions\ResourceNotFoundException;

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

    /**
     * Whether the actor's scope reaches a record belonging to a barangay (ADR 0012).
     *
     * A permission answers "may you do this kind of thing"; a scope answers "to whose
     * records". Both are required — holding `resident.view` does not make every resident
     * in the municipality yours to read.
     */
    public function allowsBarangay(ActorContext $actor, ?int $barangayId): bool
    {
        return $actor->scope->coversBarangay($barangayId);
    }

    /**
     * Enforces scope on a single record.
     *
     * Throws **NOT FOUND, not FORBIDDEN**, on purpose. A barangay clerk who probes an id
     * from another barangay must not be able to tell "this record exists but is not yours"
     * from "no such record" — the difference is enough to enumerate residents across the
     * municipality one id at a time (conventions §4, OWASP API1).
     *
     * @throws ResourceNotFoundException
     */
    public function authorizeBarangay(ActorContext $actor, ?int $barangayId, string $message = 'That record was not found.'): void
    {
        if (! $this->allowsBarangay($actor, $barangayId)) {
            throw ResourceNotFoundException::make($message);
        }
    }

    /**
     * Constrains a listing query to what the actor may reach.
     *
     * Applied at the query, not after fetching: filtering in PHP still pulls other
     * barangays' rows out of the database, and the first person to add pagination or a
     * count gets a number that includes records the caller may not see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeToBarangays(ActorContext $actor, Builder $query, string $column = 'barangay_id'): Builder
    {
        if ($actor->scope->isUnrestricted()) {
            return $query;
        }

        if ($actor->scope->isNone() || $actor->scope->barangayIds === []) {
            // Deny by default: an unscoped actor lists nothing rather than everything.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $actor->scope->barangayIds);
    }
}
