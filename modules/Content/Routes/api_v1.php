<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Content\Http\Controllers\V1\EngagementController;
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

/*
 * ── engagement ────────────────────────────────────────────────────────────────────────
 *
 * Every citizen write is bound to the token's subject and to a LIVE post. There is no field
 * anywhere that names an author, a reactor or a sharer, which is how "a citizen can modify only
 * their own engagement" is held — there is nothing to tamper with (ADR 0029 §1).
 *
 * RATE LIMITED, per account rather than per IP: a household behind one connection is several
 * legitimate residents, and a comment box on a municipal feed is the cheapest way to flood a
 * system with text somebody then has to read.
 *
 * The share endpoint accepts NO destination. The master command forbids tracking external
 * destinations or personal contacts, and a field that existed would eventually be filled.
 */
Route::middleware(['auth:sanctum', 'throttle:engagement'])->group(function (): void {
    Route::post('newsfeed/{post}/reaction', [EngagementController::class, 'react'])->name('v1.newsfeed.react');
    Route::delete('newsfeed/{post}/reaction', [EngagementController::class, 'unreact'])->name('v1.newsfeed.unreact');
    Route::post('newsfeed/{post}/comments', [EngagementController::class, 'comment'])->name('v1.newsfeed.comments.store');
    Route::post('newsfeed/{post}/share', [EngagementController::class, 'share'])->name('v1.newsfeed.share');

    Route::patch('newsfeed-comments/{comment}', [EngagementController::class, 'editComment'])->name('v1.newsfeed.comments.update');
    Route::delete('newsfeed-comments/{comment}', [EngagementController::class, 'deleteComment'])->name('v1.newsfeed.comments.destroy');

    /*
     * REPORTING — required by both app stores for user-generated content (F26).
     *
     * A resident surface, not the staff one. `admin/newsfeed-comments/{comment}/moderation` acts
     * on a comment; this only says that somebody objected. Throttled with the rest of engagement,
     * because a report costs staff attention and an unthrottled one is a denial-of-service attack
     * on a person rather than on a server.
     */
    Route::post('newsfeed-comments/{comment}/reports', [EngagementController::class, 'reportComment'])->name('v1.newsfeed.comments.report');
});

/*
 * Reading a thread is not throttled beyond the global API limit: a reader refreshing a feed is
 * ordinary use, and rate-limiting it would break the app for a household on one slow connection.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('newsfeed/{post}/comments', [EngagementController::class, 'comments'])->name('v1.newsfeed.comments.index');

    Route::get('admin/newsfeed-comments', [EngagementController::class, 'moderationQueue'])->name('v1.admin.newsfeed.comments.index');
    Route::post('admin/newsfeed-comments/{comment}/moderation', [EngagementController::class, 'moderate'])->name('v1.admin.newsfeed.comments.moderate');
});
