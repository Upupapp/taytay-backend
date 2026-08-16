<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Content\Http\Controllers\V1\NewsfeedController;

/*
 * Newsfeed routes. Mounted under /api/v1 by routes/api.php.
 *
 * THE READER ROUTES ARE OUTSIDE THE AUTH GROUP, and refuse anonymous callers in the controller
 * unless the LGU has switched public access on. That is deliberate rather than lazy: putting them
 * behind `auth:sanctum` would make enabling public access a routing change, and routing changes
 * are the ones nobody reviews as a policy decision.
 *
 * Reading goes through `publicQuery()`, which narrows to published-and-arrived-and-audience-matched
 * at the query. A draft is ABSENT from a public lookup, not filtered out of one — which is what
 * makes "draft content cannot leak via a guessed ID" survive the next endpoint somebody adds
 * (ADR 0028 §2).
 */

Route::get('newsfeed', [NewsfeedController::class, 'feed'])->name('v1.newsfeed.index');
Route::get('newsfeed/{post}', [NewsfeedController::class, 'read'])->name('v1.newsfeed.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/newsfeed', [NewsfeedController::class, 'index'])->name('v1.admin.newsfeed.index');
    Route::post('admin/newsfeed', [NewsfeedController::class, 'store'])->name('v1.admin.newsfeed.store');
    // Declared before `{post}` so the literal segment is not swallowed by the wildcard.
    Route::get('admin/newsfeed-metrics', [NewsfeedController::class, 'metrics'])->name('v1.admin.newsfeed.metrics');
    Route::get('admin/newsfeed/{post}', [NewsfeedController::class, 'show'])->name('v1.admin.newsfeed.show');
    Route::patch('admin/newsfeed/{post}', [NewsfeedController::class, 'update'])->name('v1.admin.newsfeed.update');

    // Schedule / publish / draft / archive. The permission comes from the TARGET state.
    Route::post('admin/newsfeed/{post}/status', [NewsfeedController::class, 'transition'])->name('v1.admin.newsfeed.status');
    Route::post('admin/newsfeed/{post}/pin', [NewsfeedController::class, 'setPinned'])->name('v1.admin.newsfeed.pin');
    Route::post('admin/newsfeed/{post}/media', [NewsfeedController::class, 'attachMedia'])->name('v1.admin.newsfeed.media');
});
