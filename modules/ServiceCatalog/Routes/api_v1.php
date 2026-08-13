<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceCatalog\Http\Controllers\V1\ServiceCatalogController;

/*
 * ServiceCatalog routes. Mounted under /api/v1 by routes/api.php.
 *
 * Both routes below point at the SAME controller and the SAME application service. The
 * only difference is authentication; what a caller may see is decided by their
 * server-resolved permissions, never by which URL they used (ADR 0002).
 */

// PUBLIC BY DESIGN: the catalog of published services is public information, and citizens
// must be able to browse it before registering. Unpublished entries are excluded by the
// permission check inside ListServicesQuery, not by this route being public.
Route::get('services', [ServiceCatalogController::class, 'index'])
    ->name('v1.services.index');

/*
 * The `admin` prefix is routing convenience for the admin console. It grants nothing:
 * an authenticated resident reaching this URL gets exactly the published catalog, and an
 * LGU admin reaching the citizen URL above sees drafts. Asserted by
 * tests/Feature/Api/V1/ClientChannelIsNotAuthorityTest.php.
 */
Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
    Route::get('services', [ServiceCatalogController::class, 'index'])
        ->name('v1.admin.services.index');
});
