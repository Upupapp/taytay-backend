<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Search\Http\Controllers\V1\SearchController;

/*
 * Search routes. Mounted under /api/v1 by routes/api.php.
 *
 * THERE IS NO CITIZEN SEARCH ENDPOINT, deliberately. A citizen's own records are reachable through
 * `me/*`, which resolves the resident from the token and has no identifier to tamper with. A
 * citizen search that "only returned their own records" would be a resident-enumeration endpoint
 * one authorization bug away — and the bug would be invisible, because the endpoint would still
 * look like it was working. The absence is the control (ADR 0027 §5).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/search', [SearchController::class, 'search'])->name('v1.admin.search');

    Route::get('admin/saved-views', [SearchController::class, 'index'])->name('v1.admin.saved-views.index');
    Route::post('admin/saved-views', [SearchController::class, 'store'])->name('v1.admin.saved-views.store');
    Route::delete('admin/saved-views/{view}', [SearchController::class, 'destroy'])->name('v1.admin.saved-views.destroy');
});
