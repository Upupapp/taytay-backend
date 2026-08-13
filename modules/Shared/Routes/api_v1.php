<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shared\Http\Controllers\HealthController;

/*
 * Shared platform routes. Registered under the /api/v1 prefix and the `api` middleware
 * group by routes/api.php.
 */

// PUBLIC BY DESIGN: liveness probe for load balancers and uptime monitoring. Exposes no
// environment, dependency or configuration detail.
Route::get('health', HealthController::class)->name('v1.health');
