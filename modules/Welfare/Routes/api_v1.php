<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Welfare\Http\Controllers\V1\AssessmentController;
use Modules\Welfare\Http\Controllers\V1\CaseController;
use Modules\Welfare\Http\Controllers\V1\MyAssistanceController;
use Modules\Welfare\Http\Controllers\V1\MyCaseController;

/*
 * Welfare routes. Mounted under /api/v1 by routes/api.php.
 *
 * Two audiences, one lifecycle. The `/me/cases` routes resolve the resident from the
 * authenticated account, so an applicant can only ever reach their own and there is no
 * identifier to tamper with. The `/admin/cases` routes each require an explicit permission,
 * and the transition endpoint resolves its permission from the *target state* rather than
 * from the route (ADR 0007 §2).
 *
 * Staff paths carry the `/admin` prefix used by every other staff surface in this backend.
 * The contract matrix documents them unprefixed because the Angular console calls them that
 * way today — the same deviation recorded for residents and households, tracked as gap G-19.
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // ── the applicant's own requests ──────────────────────────────────────────────────
    Route::get('me/cases', [MyCaseController::class, 'index'])->name('v1.me.cases.index');
    Route::get('me/cases/{case}', [MyCaseController::class, 'show'])->name('v1.me.cases.show');
    Route::post('me/cases/{case}/cancel', [MyCaseController::class, 'cancel'])->name('v1.me.cases.cancel');

    // ── the staff queue and case file ─────────────────────────────────────────────────
    Route::get('admin/cases', [CaseController::class, 'index'])->name('v1.admin.cases.index');
    Route::post('admin/cases', [CaseController::class, 'store'])->name('v1.admin.cases.store');
    Route::get('admin/cases/{case}', [CaseController::class, 'show'])->name('v1.admin.cases.show');
    Route::get('admin/cases/{case}/history', [CaseController::class, 'history'])->name('v1.admin.cases.history');

    /*
     * THE ONE LIFECYCLE ENDPOINT. Nine verbs would be nine places the transition map could be
     * forgotten, and the tenth added in a hurry would be the one that skipped it.
     */
    Route::post('admin/cases/{case}/transitions', [CaseController::class, 'transition'])->name('v1.admin.cases.transitions');

    Route::post('admin/cases/{case}/priority', [CaseController::class, 'changePriority'])->name('v1.admin.cases.priority');
    Route::post('admin/cases/{case}/assignment', [CaseController::class, 'assign'])->name('v1.admin.cases.assign');
    Route::delete('admin/cases/{case}/assignment', [CaseController::class, 'unassign'])->name('v1.admin.cases.unassign');
    Route::post('admin/cases/{case}/archive', [CaseController::class, 'archive'])->name('v1.admin.cases.archive');

    /*
     * ── the applicant's own drafts and submission ─────────────────────────────────────
     *
     * The source is derived from the client channel, never accepted from the body: a client
     * claiming `walk-in` would be manufacturing evidence that a clerk saw the person.
     *
     * Submission carries `Idempotency-Key`. A weak connection is the normal case here, not an
     * edge one, and an unprotected retry opens a second case for one person.
     */
    Route::get('me/assistance/drafts', [MyAssistanceController::class, 'listDrafts'])->name('v1.me.assistance.drafts.index');
    Route::post('me/assistance/drafts', [MyAssistanceController::class, 'storeDraft'])->name('v1.me.assistance.drafts.store');
    Route::patch('me/assistance/drafts/{draft}', [MyAssistanceController::class, 'updateDraft'])->name('v1.me.assistance.drafts.update');
    Route::delete('me/assistance/drafts/{draft}', [MyAssistanceController::class, 'discardDraft'])->name('v1.me.assistance.drafts.destroy');
    Route::post('me/assistance/drafts/{draft}/submit', [MyAssistanceController::class, 'submitDraft'])->name('v1.me.assistance.drafts.submit');

    /*
     * ── staff intake and assessment ───────────────────────────────────────────────────
     *
     * `admin/assessment-templates` is declared before the `{case}` routes so the literal
     * segment is not swallowed by a wildcard.
     */
    Route::get('admin/assessment-templates', [AssessmentController::class, 'templates'])->name('v1.admin.assessment-templates.index');
    Route::post('admin/assistance-intakes', [AssessmentController::class, 'storeIntake'])->name('v1.admin.assistance-intakes.store');

    Route::get('admin/cases/{case}/assessment', [AssessmentController::class, 'show'])->name('v1.admin.cases.assessment.show');
    Route::post('admin/cases/{case}/assessment', [AssessmentController::class, 'open'])->name('v1.admin.cases.assessment.open');
    Route::patch('admin/cases/{case}/assessment', [AssessmentController::class, 'answer'])->name('v1.admin.cases.assessment.answer');
    Route::post('admin/cases/{case}/assessment/complete', [AssessmentController::class, 'complete'])->name('v1.admin.cases.assessment.complete');
    Route::get('admin/cases/{case}/prior-cases', [AssessmentController::class, 'history'])->name('v1.admin.cases.prior-cases');
});
