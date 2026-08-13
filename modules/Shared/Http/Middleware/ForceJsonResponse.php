<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This backend answers JSON regardless of the Accept header.
 *
 * Without this, a mobile client that omits `Accept: application/json` receives an HTML
 * error page it cannot parse — and, with debug enabled, one that leaks internals.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
