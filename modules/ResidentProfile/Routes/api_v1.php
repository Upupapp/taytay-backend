<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ResidentProfile\Http\Controllers\V1\KycController;

/*
 * ResidentProfile routes. Mounted under /api/v1 by routes/api.php.
 *
 * Two audiences, one lifecycle. The `/me/kyc` routes resolve the case from the
 * authenticated account, so a citizen can only ever reach their own. The `/admin/kyc`
 * routes each require an explicit permission — routing prefix confers nothing (ADR 0002).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // Applicant's own onboarding. Nothing here writes to the canonical resident registry.
    Route::post('me/kyc', [KycController::class, 'register'])->name('v1.me.kyc.register');
    Route::get('me/kyc', [KycController::class, 'showOwn'])->name('v1.me.kyc.show');
    Route::post('me/kyc/submit', [KycController::class, 'submitOwn'])->name('v1.me.kyc.submit');

    // Reviewer queue.
    Route::get('admin/kyc-cases', [KycController::class, 'index'])->name('v1.admin.kyc.index');
    Route::get('admin/kyc-cases/{case}', [KycController::class, 'show'])->name('v1.admin.kyc.show');
    Route::post('admin/kyc-cases/{case}/rescreen', [KycController::class, 'rescreen'])->name('v1.admin.kyc.rescreen');
    Route::post('admin/kyc-cases/{case}/candidates/{candidate}', [KycController::class, 'decideCandidate'])->name('v1.admin.kyc.candidate');
    Route::post('admin/kyc-cases/{case}/approve', [KycController::class, 'approve'])->name('v1.admin.kyc.approve');
    Route::post('admin/kyc-cases/{case}/reject', [KycController::class, 'reject'])->name('v1.admin.kyc.reject');
    Route::post('admin/kyc-cases/{case}/request-information', [KycController::class, 'requestMoreInformation'])->name('v1.admin.kyc.request-information');
});
