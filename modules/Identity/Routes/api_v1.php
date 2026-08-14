<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\V1\AccountController;
use Modules\Identity\Http\Controllers\V1\AuthenticationController;

/*
 * Identity routes. Mounted under /api/v1 by routes/api.php.
 *
 * Every unauthenticated route below is rate limited, because each one accepts a secret
 * and tells the caller whether it was right — which is exactly what an attacker needs a
 * lot of attempts at. Limits are per minute, keyed by IP and by the submitted identifier,
 * so one abusive source cannot lock out an entire office and one targeted account cannot
 * be ground down from many addresses.
 */

// PUBLIC BY DESIGN: these are the sign-in surface. They must be reachable without a token.
Route::middleware('throttle:identity-sign-in')->group(function (): void {
    Route::post('auth/tokens', [AuthenticationController::class, 'store'])->name('v1.auth.tokens.store');
    Route::post('auth/tokens/mfa', [AuthenticationController::class, 'completeMfa'])->name('v1.auth.tokens.mfa');
    Route::post('auth/otp/verify', [AuthenticationController::class, 'verifyCode'])->name('v1.auth.otp.verify');
});

// Tighter limit: issuing a code sends a message to a real person's phone, so abuse here
// costs money and annoys residents, not just CPU.
Route::middleware('throttle:identity-code-request')->group(function (): void {
    Route::post('auth/otp', [AuthenticationController::class, 'requestCode'])->name('v1.auth.otp.request');
    Route::post('auth/password/forgot', [AuthenticationController::class, 'forgotPassword'])->name('v1.auth.password.forgot');
});

Route::middleware('throttle:identity-sign-in')
    ->post('auth/password/reset', [AuthenticationController::class, 'resetPassword'])
    ->name('v1.auth.password.reset');

/*
 * Authenticated: the caller's own account only. The account is resolved from the token,
 * never from a path or body parameter, so there is no identifier to tamper with.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::delete('auth/tokens/current', [AuthenticationController::class, 'destroyCurrent'])->name('v1.auth.tokens.destroy');

    Route::get('me', [AccountController::class, 'show'])->name('v1.me.show');

    Route::get('me/sessions', [AccountController::class, 'listSessions'])->name('v1.me.sessions.index');
    Route::delete('me/sessions/{session}', [AccountController::class, 'revokeSession'])->name('v1.me.sessions.destroy');
    Route::post('me/sessions/revoke-all', [AccountController::class, 'revokeAllSessions'])->name('v1.me.sessions.revoke-all');

    Route::get('me/devices', [AccountController::class, 'listDevices'])->name('v1.me.devices.index');
    Route::post('me/devices', [AccountController::class, 'registerDevice'])->name('v1.me.devices.store');
    Route::delete('me/devices/{device}', [AccountController::class, 'revokeDevice'])->name('v1.me.devices.destroy');

    Route::post('me/contact/verify', [AccountController::class, 'requestContactVerification'])->name('v1.me.contact.request');
    Route::post('me/contact/verify/confirm', [AccountController::class, 'confirmContactVerification'])->name('v1.me.contact.confirm');

    /*
     * MFA is staff-only, enforced in the service rather than by the route: a citizen
     * reaching these gets 403 FORBIDDEN from the authorization decision, not a 404 from
     * routing. The rule lives in one place (MultiFactorService), so it cannot drift
     * between the route file and the service.
     */
    Route::post('me/mfa', [AccountController::class, 'beginMfaEnrolment'])->name('v1.me.mfa.begin');
    Route::post('me/mfa/confirm', [AccountController::class, 'confirmMfaEnrolment'])->name('v1.me.mfa.confirm');
    Route::post('me/mfa/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->name('v1.me.mfa.recovery-codes');
    Route::delete('me/mfa', [AccountController::class, 'disableMfa'])->name('v1.me.mfa.destroy');
});
