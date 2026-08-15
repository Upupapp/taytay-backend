<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Credential\Http\Controllers\V1\CredentialController;

/*
 * Credential routes — the digital ID.
 *
 * Registered unconditionally but gated inside the controller by
 * `credential.digital_id.enabled`, which is OFF by default. Registering them either way
 * keeps the route list and the contract matrix honest about what exists; the flag decides
 * whether they answer. A disabled feature returns 404, so it looks absent rather than
 * forbidden (ADR 0011).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // Holder's own card. Resolved from the account, never from a supplied identifier.
    Route::get('me/credential', [CredentialController::class, 'showOwn'])->name('v1.me.credential.show');
    Route::post('me/credential/qr', [CredentialController::class, 'mintQr'])->name('v1.me.credential.qr');

    /*
     * Verification. Authenticated so scans are attributable, but permission-free: a
     * verifier device is not staff, and the answer is deliberately too thin to be worth
     * attacking for.
     */
    Route::post('credential-verifications', [CredentialController::class, 'verify'])->name('v1.credential.verify');

    // Staff issuance and revocation, both gated on credential.manage.
    Route::post('admin/credentials', [CredentialController::class, 'issue'])->name('v1.admin.credentials.issue');
    Route::post('admin/credentials/{credential}/revoke', [CredentialController::class, 'revoke'])->name('v1.admin.credentials.revoke');
});
