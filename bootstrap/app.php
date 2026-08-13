<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Shared\Http\Exceptions\ApiExceptionRenderer;
use Modules\Shared\Http\Middleware\AssignRequestId;
use Modules\Shared\Http\Middleware\ForceJsonResponse;
use Modules\Shared\Http\Middleware\ResolveClientChannel;

/*
 * API-only application (CLAUDE.md Article 0).
 *
 * There is deliberately no `web:` route file and no view layer: this backend serves four
 * separate frontend clients over /api/v1 and renders no UI of its own.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended so that a request is JSON-shaped and correlated before anything —
        // including authentication failures — can produce a response.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            AssignRequestId::class,
            ResolveClientChannel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One renderer for every module, so error shape cannot drift (ADR 0003).
        ApiExceptionRenderer::register($exceptions);
    })->create();
