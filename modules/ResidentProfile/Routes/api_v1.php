<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ResidentProfile\Http\Controllers\V1\KycController;
use Modules\ResidentProfile\Http\Controllers\V1\MyProfileController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentCorrectionController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentDuplicateController;

/*
 * ResidentProfile routes. Mounted under /api/v1 by routes/api.php.
 *
 * Two audiences, one lifecycle. The `/me/...` routes resolve the case or resident from the
 * authenticated account, so a citizen can only ever reach their own and there is no
 * identifier in the path to tamper with. The `/admin/...` routes each require an explicit
 * permission — the routing prefix confers nothing (ADR 0002).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // ── the applicant's own onboarding ────────────────────────────────────────────────
    // Nothing here writes to the canonical resident registry.
    Route::post('me/kyc', [KycController::class, 'register'])->name('v1.me.kyc.register');
    Route::get('me/kyc', [KycController::class, 'showOwn'])->name('v1.me.kyc.show');
    Route::post('me/kyc/submit', [KycController::class, 'submitOwn'])->name('v1.me.kyc.submit');

    // ── the resident's own canonical profile ──────────────────────────────────────────
    Route::get('me/profile', [MyProfileController::class, 'show'])->name('v1.me.profile.show');
    Route::post('me/profile/corrections', [MyProfileController::class, 'requestCorrection'])->name('v1.me.profile.corrections.store');
    Route::get('me/profile/corrections', [MyProfileController::class, 'listCorrections'])->name('v1.me.profile.corrections.index');
    Route::delete('me/profile/corrections/{correction}', [MyProfileController::class, 'withdrawCorrection'])->name('v1.me.profile.corrections.withdraw');

    // ── KYC reviewer queue ────────────────────────────────────────────────────────────
    Route::get('admin/kyc-cases', [KycController::class, 'index'])->name('v1.admin.kyc.index');
    Route::get('admin/kyc-cases/{case}', [KycController::class, 'show'])->name('v1.admin.kyc.show');
    Route::post('admin/kyc-cases/{case}/rescreen', [KycController::class, 'rescreen'])->name('v1.admin.kyc.rescreen');
    Route::post('admin/kyc-cases/{case}/candidates/{candidate}', [KycController::class, 'decideCandidate'])->name('v1.admin.kyc.candidate');
    Route::post('admin/kyc-cases/{case}/approve', [KycController::class, 'approve'])->name('v1.admin.kyc.approve');
    Route::post('admin/kyc-cases/{case}/reject', [KycController::class, 'reject'])->name('v1.admin.kyc.reject');
    Route::post('admin/kyc-cases/{case}/request-information', [KycController::class, 'requestMoreInformation'])->name('v1.admin.kyc.request-information');

    /*
     * ── the canonical registry ────────────────────────────────────────────────────────
     *
     * Ordered deliberately: the duplicate and correction collections are declared BEFORE
     * `admin/residents/{resident}`, because a wildcard segment declared first would swallow
     * its own siblings and the shadowed route would 404 with no obvious cause.
     */
    Route::get('admin/resident-duplicates', [ResidentDuplicateController::class, 'index'])->name('v1.admin.resident-duplicates.index');
    Route::post('admin/resident-duplicates/detect', [ResidentDuplicateController::class, 'detect'])->name('v1.admin.resident-duplicates.detect');
    Route::post('admin/resident-duplicates/{pair}/decide', [ResidentDuplicateController::class, 'decide'])->name('v1.admin.resident-duplicates.decide');
    Route::post('admin/resident-duplicates/{pair}/preview', [ResidentDuplicateController::class, 'preview'])->name('v1.admin.resident-duplicates.preview');
    Route::post('admin/resident-duplicates/{pair}/merge', [ResidentDuplicateController::class, 'merge'])->name('v1.admin.resident-duplicates.merge');

    Route::get('admin/resident-corrections', [ResidentCorrectionController::class, 'index'])->name('v1.admin.resident-corrections.index');
    Route::get('admin/resident-corrections/{correction}', [ResidentCorrectionController::class, 'show'])->name('v1.admin.resident-corrections.show');
    Route::post('admin/resident-corrections/{correction}/approve', [ResidentCorrectionController::class, 'approve'])->name('v1.admin.resident-corrections.approve');
    Route::post('admin/resident-corrections/{correction}/reject', [ResidentCorrectionController::class, 'reject'])->name('v1.admin.resident-corrections.reject');

    Route::get('admin/residents', [ResidentController::class, 'index'])->name('v1.admin.residents.index');
    Route::post('admin/residents', [ResidentController::class, 'store'])->name('v1.admin.residents.store');
    Route::get('admin/residents/{resident}', [ResidentController::class, 'show'])->name('v1.admin.residents.show');
    Route::patch('admin/residents/{resident}', [ResidentController::class, 'update'])->name('v1.admin.residents.update');
    Route::get('admin/residents/{resident}/history', [ResidentController::class, 'history'])->name('v1.admin.residents.history');
    Route::post('admin/residents/{resident}/verification', [ResidentController::class, 'changeVerification'])->name('v1.admin.residents.verification');
    Route::post('admin/residents/{resident}/activation', [ResidentController::class, 'changeActivation'])->name('v1.admin.residents.activation');

    Route::get('admin/residents/{resident}/account-links', [ResidentController::class, 'listLinks'])->name('v1.admin.residents.links.index');
    Route::post('admin/residents/{resident}/account-links', [ResidentController::class, 'storeLink'])->name('v1.admin.residents.links.store');
    Route::delete('admin/residents/{resident}/account-links/{link}', [ResidentController::class, 'revokeLink'])->name('v1.admin.residents.links.destroy');
});
