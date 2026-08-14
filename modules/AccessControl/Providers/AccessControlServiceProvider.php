<?php

declare(strict_types=1);

namespace Modules\AccessControl\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\AccessControl\Application\ActorContextFactory;
use Modules\AccessControl\Domain\RoleAssignmentRepository;
use Modules\AccessControl\Infrastructure\DatabaseRoleAssignmentRepository;
use Modules\Shared\Application\ActorContext;

final class AccessControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Canonical since TAB 05: role assignments live in the database now that
        // Identity owns accounts. The config-backed implementation is gone; the
        // interface it was bound to has not changed (gap G-09).
        $this->app->bind(RoleAssignmentRepository::class, DatabaseRoleAssignmentRepository::class);

        $this->app->when(ActorContextFactory::class)
            ->needs('$guard')
            ->give(static fn ($app): string => (string) $app['config']->get('api.actor_guard', 'sanctum'));

        // Resolved once per request and memoised on the Request, never on the container.
        //
        // A container-lifetime binding would survive between requests on a long-lived
        // worker and hand the previous caller's roles to the next one — a critical
        // authorization defect (CLAUDE.md Article 5.3). Keying on the Request object
        // means a new request always resolves a new actor.
        $this->app->bind(ActorContext::class, static function ($app): ActorContext {
            $request = $app['request'];

            if (! $request->attributes->has('access_control.actor')) {
                $request->attributes->set(
                    'access_control.actor',
                    $app->make(ActorContextFactory::class)->forCurrentRequest(),
                );
            }

            return $request->attributes->get('access_control.actor');
        });
    }
}
