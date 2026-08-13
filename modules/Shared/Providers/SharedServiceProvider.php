<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Shared\Application\RequestContext;

/**
 * Shared is the only module every other module may depend on, and it may depend on
 * nothing but the framework (CLAUDE.md Article 2.3).
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One correlation context per request, memoised on the Request itself rather than
        // as a container singleton or scoped instance.
        //
        // Container-lifetime bindings survive between requests on any long-lived worker
        // (Octane, and a test making several calls against one application instance),
        // which would let one citizen's request id — and, for the ActorContext bound the
        // same way in AccessControl, one citizen's AUTHORITY — bleed into the next
        // request. Keying on the Request object makes a new request structurally
        // incapable of reusing the previous one's context.
        $this->app->bind(RequestContext::class, static function ($app): RequestContext {
            $request = $app['request'];

            if (! $request->attributes->has('shared.request_context')) {
                $request->attributes->set('shared.request_context', new RequestContext);
            }

            return $request->attributes->get('shared.request_context');
        });
    }
}
