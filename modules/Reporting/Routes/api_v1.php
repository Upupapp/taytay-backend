<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\V1\ReportController;

/*
 * Reporting routes. Mounted under /api/v1 by routes/api.php.
 *
 * AGGREGATE-FIRST. One dashboard endpoint returning counts, and an export lifecycle where the
 * permission is decided by the report rather than the route — a person-level export costs
 * `report.export.person-level`, an aggregate costs `report.view` (ADR 0026 §3).
 *
 * There is no per-caseworker report and no grouping by caseworker anywhere. Filtering to one
 * named worker is permitted; a leaderboard is not (ADR 0026 §4).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/dashboard', [ReportController::class, 'dashboard'])->name('v1.admin.dashboard');

    Route::get('admin/exports', [ReportController::class, 'listExports'])->name('v1.admin.exports.index');
    /*
     * THE TIGHTEST AUTHENTICATED LIMIT IN THE SYSTEM, and it is per HOUR.
     *
     * An export is a copy of the database leaving this application's control (ADR 0026 §3).
     * Ten an hour is generous for somebody doing their job and useless to somebody
     * exfiltrating a caseload.
     */
    Route::post('admin/exports', [ReportController::class, 'requestExport'])
        ->middleware('throttle:export')
        ->name('v1.admin.exports.store');

    /*
     * Re-authorized at download, not trusted from the request: an export queued on Friday and
     * fetched on Monday belongs to whoever the requester is on Monday.
     */
    Route::get('admin/exports/{export}/download', [ReportController::class, 'download'])->name('v1.admin.exports.download');
});
