<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\V1\MyNotificationController;

/*
 * Notification routes. Mounted under /api/v1 by routes/api.php.
 *
 * EVERY ROUTE IS `me`, scoped to the account behind the bearer token, with no field anywhere that
 * accepts an account identifier.
 *
 * THERE ARE NO DEVICE ROUTES HERE. Identity already owns `me/devices` and its `devices` table
 * already carries `push_token` — a device is an authentication concept (fingerprint, trust,
 * revocation) before it is a delivery one. A second registration surface was written for this TAB
 * and removed: it silently shadowed Identity's routes and would have drifted the moment somebody
 * revoked a device in one place and kept receiving push from the other (Article 6, ADR 0025 §5).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me/notifications', [MyNotificationController::class, 'index'])->name('v1.me.notifications.index');
    Route::post('me/notifications/read-all', [MyNotificationController::class, 'markAllRead'])->name('v1.me.notifications.read-all');
    Route::post('me/notifications/{notification}/read', [MyNotificationController::class, 'markRead'])->name('v1.me.notifications.read');

    /*
     * `database` is deliberately not a switchable channel: opting out of email means "stop
     * emailing me", not "stop keeping a record of what you told me".
     */
    Route::get('me/notification-preferences', [MyNotificationController::class, 'preferences'])->name('v1.me.notification-preferences.index');
    Route::put('me/notification-preferences', [MyNotificationController::class, 'updatePreference'])->name('v1.me.notification-preferences.update');
});
