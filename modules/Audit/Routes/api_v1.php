<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\V1\AuditController;
use Modules\Audit\Http\Controllers\V1\GovernanceController;

/*
 * Audit and privacy governance. Mounted under /api/v1 by routes/api.php.
 *
 * THE PRIVACY NOTICE IS PUBLIC, and that is an affirmative choice recorded here as Article 3.5
 * requires. A notice that required an account to read would be one a person could not consult
 * before deciding whether to create an account — which is the exact moment they need it.
 */

Route::get('privacy/notice', [GovernanceController::class, 'currentNotice'])
    ->defaults('cache', 'public')
    ->name('v1.privacy.notice');

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     * ── the resident's own governance ─────────────────────────────────────────────────
     *
     * Every one of these is scoped at the query to the token's subject. There is no identifier
     * anywhere in these contracts, so "a citizen manages only their own consent" is held by there
     * being nothing to tamper with.
     */
    Route::post('me/privacy/acknowledgement', [GovernanceController::class, 'acknowledge'])
        ->name('v1.me.privacy.acknowledge');
    Route::get('me/privacy/consents', [GovernanceController::class, 'myConsents'])
        ->name('v1.me.privacy.consents.index');
    Route::post('me/privacy/consents', [GovernanceController::class, 'grantConsent'])
        ->name('v1.me.privacy.consents.store');
    Route::delete('me/privacy/consents/{purpose}', [GovernanceController::class, 'withdrawConsent'])
        ->name('v1.me.privacy.consents.withdraw');

    /*
     * ── the trail ─────────────────────────────────────────────────────────────────────
     *
     * `audit.view` is held by nobody by default. The trail is more concentrated than any single
     * record it describes — a search for `safeguarding.opened` names which residents have
     * protection cases without opening one — and READING IT IS ITSELF AUDITED (ADR 0034 §7).
     */
    Route::get('admin/audit-entries', [AuditController::class, 'index'])->name('v1.admin.audit.index');
    // Declared before `{entry}` so the literal segments are not swallowed by the wildcard.
    Route::get('admin/audit-entries/vocabulary', [AuditController::class, 'vocabulary'])
        ->name('v1.admin.audit.vocabulary');
    Route::get('admin/audit-entries/for-entity', [AuditController::class, 'forEntity'])
        ->name('v1.admin.audit.for-entity');
    Route::get('admin/audit-entries/{entry}', [AuditController::class, 'show'])->name('v1.admin.audit.show');

    /*
     * ── the DPO ───────────────────────────────────────────────────────────────────────
     */
    Route::post('admin/privacy/notices', [GovernanceController::class, 'publishNotice'])
        ->name('v1.admin.privacy.notices.store');
    Route::get('admin/privacy/retention', [GovernanceController::class, 'retentionSchedule'])
        ->name('v1.admin.privacy.retention');
    Route::get('admin/privacy/legal-holds', [GovernanceController::class, 'holds'])
        ->name('v1.admin.privacy.holds.index');
    Route::post('admin/privacy/legal-holds', [GovernanceController::class, 'placeHold'])
        ->name('v1.admin.privacy.holds.store');
    Route::post('admin/privacy/legal-holds/{hold}/lift', [GovernanceController::class, 'liftHold'])
        ->name('v1.admin.privacy.holds.lift');
});
