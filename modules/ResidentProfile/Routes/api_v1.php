<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ResidentProfile\Http\Controllers\V1\HouseholdController;
use Modules\ResidentProfile\Http\Controllers\V1\KycController;
use Modules\ResidentProfile\Http\Controllers\V1\MyProfileController;
use Modules\ResidentProfile\Http\Controllers\V1\RelationshipController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentCorrectionController;
use Modules\ResidentProfile\Http\Controllers\V1\ResidentDuplicateController;
use Modules\ResidentProfile\Http\Controllers\V1\VulnerabilityController;

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
    /*
     * RATE LIMITED (ADR 0035 §2). Each submission puts a case in front of a human reviewer,
     * so an unthrottled endpoint is a denial-of-service attack on the office's attention
     * rather than on the server.
     */
    Route::post('me/kyc/submit', [KycController::class, 'submitOwn'])
        ->middleware('throttle:kyc-submission')
        ->name('v1.me.kyc.submit');

    // ── the resident's own canonical profile ──────────────────────────────────────────
    Route::get('me/profile', [MyProfileController::class, 'show'])->name('v1.me.profile.show');
    Route::post('me/profile/corrections', [MyProfileController::class, 'requestCorrection'])->name('v1.me.profile.corrections.store');
    Route::get('me/profile/corrections', [MyProfileController::class, 'listCorrections'])->name('v1.me.profile.corrections.index');
    Route::delete('me/profile/corrections/{correction}', [MyProfileController::class, 'withdrawCorrection'])->name('v1.me.profile.corrections.withdraw');

    // The resident's own household, privacy-minimised. Sharing a roof is not consent to be
    // looked up, so co-members appear by name and relationship only (ADR 0014 §5).
    Route::get('me/household', [MyProfileController::class, 'showHousehold'])->name('v1.me.household.show');

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

    // A resident's residence history and their kinship. Both hang off the resident because
    // that is the record staff have open when they need them.
    Route::get('admin/residents/{resident}/households', [HouseholdController::class, 'memberHistory'])->name('v1.admin.residents.households.index');
    Route::get('admin/residents/{resident}/relationships', [RelationshipController::class, 'index'])->name('v1.admin.residents.relationships.index');
    Route::post('admin/residents/{resident}/relationships', [RelationshipController::class, 'store'])->name('v1.admin.residents.relationships.store');
    Route::delete('admin/residents/{resident}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->name('v1.admin.residents.relationships.destroy');

    /*
     * ── households and families ───────────────────────────────────────────────────────
     *
     * `admin/families/...` is declared before `admin/households/{household}` for the same
     * reason the duplicate routes are: a wildcard segment declared first swallows its
     * siblings, and the shadowed route 404s with no obvious cause.
     */
    Route::post('admin/families/{family}/members', [HouseholdController::class, 'addFamilyMember'])->name('v1.admin.families.members.store');
    Route::delete('admin/families/{family}/members/{resident}', [HouseholdController::class, 'removeFamilyMember'])->name('v1.admin.families.members.destroy');
    Route::post('admin/families/{family}/head', [HouseholdController::class, 'changeFamilyHead'])->name('v1.admin.families.head');

    Route::get('admin/households', [HouseholdController::class, 'index'])->name('v1.admin.households.index');
    Route::post('admin/households', [HouseholdController::class, 'store'])->name('v1.admin.households.store');
    Route::get('admin/households/{household}', [HouseholdController::class, 'show'])->name('v1.admin.households.show');
    Route::patch('admin/households/{household}', [HouseholdController::class, 'update'])->name('v1.admin.households.update');
    Route::post('admin/households/{household}/head', [HouseholdController::class, 'changeHead'])->name('v1.admin.households.head');
    Route::post('admin/households/{household}/verification', [HouseholdController::class, 'changeVerification'])->name('v1.admin.households.verification');
    Route::post('admin/households/{household}/status', [HouseholdController::class, 'changeStatus'])->name('v1.admin.households.status');

    Route::post('admin/households/{household}/members', [HouseholdController::class, 'addMember'])->name('v1.admin.households.members.store');
    Route::delete('admin/households/{household}/members/{resident}', [HouseholdController::class, 'removeMember'])->name('v1.admin.households.members.destroy');
    // Transfer is one call because it must be one transaction: a client that could only
    // remove-then-add could leave a real person belonging to no household at all.
    Route::post('admin/households/{household}/transfers', [HouseholdController::class, 'transferMember'])->name('v1.admin.households.transfers');
    Route::post('admin/households/{household}/families', [HouseholdController::class, 'storeFamily'])->name('v1.admin.households.families.store');

    /*
     * ── vulnerability ─────────────────────────────────────────────────────────────────
     *
     * Staff only, and deliberately so. There is no citizen route here: a resident's right to
     * see their own data is served by the data-access workflow in TAB 29, where a person asks
     * and a human answers. A live score endpoint would invite gaming, present a placeholder
     * ordering as a verdict, and — for someone whose device is monitored by the person they
     * are protected from — hand a disclosure channel to the abuser (ADR 0015 §5).
     *
     * `admin/vulnerability/ruleset` is declared before the `{resident}` routes so the literal
     * segment is not swallowed by a wildcard.
     */
    Route::get('admin/vulnerability/ruleset', [VulnerabilityController::class, 'ruleset'])->name('v1.admin.vulnerability.ruleset');

    Route::get('admin/residents/{resident}/vulnerability', [VulnerabilityController::class, 'showResident'])->name('v1.admin.residents.vulnerability.show');
    Route::post('admin/residents/{resident}/vulnerability-factors', [VulnerabilityController::class, 'storeResidentFactor'])->name('v1.admin.residents.vulnerability.store');
    Route::post('admin/residents/{resident}/vulnerability-factors/{factor}/review', [VulnerabilityController::class, 'reviewResidentFactor'])->name('v1.admin.residents.vulnerability.review');
    Route::delete('admin/residents/{resident}/vulnerability-factors/{factor}', [VulnerabilityController::class, 'endResidentFactor'])->name('v1.admin.residents.vulnerability.destroy');

    Route::get('admin/households/{household}/vulnerability', [VulnerabilityController::class, 'showHousehold'])->name('v1.admin.households.vulnerability.show');
    Route::post('admin/households/{household}/vulnerability-factors', [VulnerabilityController::class, 'storeHouseholdFactor'])->name('v1.admin.households.vulnerability.store');
    Route::delete('admin/households/{household}/vulnerability-factors/{factor}', [VulnerabilityController::class, 'endHouseholdFactor'])->name('v1.admin.households.vulnerability.destroy');
});
