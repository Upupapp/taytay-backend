<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use Modules\Shared\Application\RequestContext;
use Modules\Shared\Console\ReadinessCommand;

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

    /**
     * Applied at boot, once configuration is available.
     *
     * `TrustProxies` reads this static per request, so setting it here takes effect for
     * every request while still being driven by config. Doing the same thing in
     * bootstrap/app.php does NOT work: that closure runs before the .env file is loaded,
     * so the setting silently evaporates and the API keeps treating the load balancer as
     * the client.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReadinessCommand::class]);
        }

        $proxies = trim((string) config('api.trusted_proxies', ''));

        if ($proxies === '') {
            return; // Deny by default — trust nothing.
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
    }
}
