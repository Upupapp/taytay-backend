<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shared\Http\Controllers\AppBootstrapController;
use Modules\Shared\Http\Controllers\HealthController;

/*
 * Shared platform routes. Registered under the /api/v1 prefix and the `api` middleware
 * group by routes/api.php.
 */

// PUBLIC BY DESIGN: liveness probe for load balancers and uptime monitoring. Exposes no
// environment, dependency or configuration detail.
Route::get('health', HealthController::class)->name('v1.health');

/*
 * PUBLIC BY DESIGN, and it must be. An app that cannot start cannot sign in to be told that it
 * should update — a minimum-version gate behind authentication opens only for the clients that
 * did not need it (ADR 0032 §2).
 *
 * So it carries nothing worth protecting: the API version, a support number an LGU prints on a
 * poster, and rendering hints that grant nothing. Every feature flag it reports is enforced
 * server-side by the module that owns it.
 */
Route::get('app/bootstrap', AppBootstrapController::class)
    ->defaults('cache', 'public')
    ->name('v1.app.bootstrap');
