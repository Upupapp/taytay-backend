<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Welfare\Http\Controllers\V1\AssessmentController;
use Modules\Welfare\Http\Controllers\V1\CaseController;
use Modules\Welfare\Http\Controllers\V1\CaseEligibilityController;
use Modules\Welfare\Http\Controllers\V1\CaseRequirementController;
use Modules\Welfare\Http\Controllers\V1\DocumentDownloadController;
use Modules\Welfare\Http\Controllers\V1\EnrollmentController;
use Modules\Welfare\Http\Controllers\V1\MyAssistanceController;
use Modules\Welfare\Http\Controllers\V1\MyCaseController;
use Modules\Welfare\Http\Controllers\V1\MyReferralController;
use Modules\Welfare\Http\Controllers\V1\MyRequirementController;
use Modules\Welfare\Http\Controllers\V1\ReferralController;

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

    // What the applicant has actually received. In-flight cases are absent — those are tracked
    // through me/cases, and listing one here would say somebody was given what they were not.
    Route::get('me/assistance-history', [MyCaseController::class, 'history'])->name('v1.me.assistance-history');

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

    /*
     * ── eligibility guidance against a case ───────────────────────────────────────────
     *
     * Staff only. There is no citizen route, for the same reason there is none for the
     * vulnerability score: "you are likely ineligible" reads as a refusal however it is worded,
     * and it is not one — nobody has decided anything (ADR 0018 §3).
     *
     * Running a check writes an append-only row pinning the guidance version, which is the
     * audit requirement this TAB had to meet.
     */
    Route::get('admin/cases/{case}/eligibility-checks', [CaseEligibilityController::class, 'index'])->name('v1.admin.cases.eligibility.index');
    Route::post('admin/cases/{case}/eligibility-checks', [CaseEligibilityController::class, 'store'])->name('v1.admin.cases.eligibility.store');

    /*
     * ── programme rolls and assistance history ────────────────────────────────────────
     *
     * A beneficiary is a canonical resident on a roll, never a second person row — which is why
     * one resident can hold many enrolments without any duplicate record, and why duplicate
     * detection continues to operate on the resident alone (ADR 0019 §1).
     *
     * Enrolment is a human decision. Nothing here reads guidance, a recommendation or a score.
     */
    Route::get('admin/enrollments', [EnrollmentController::class, 'index'])->name('v1.admin.enrollments.index');
    Route::post('admin/enrollments', [EnrollmentController::class, 'store'])->name('v1.admin.enrollments.store');
    Route::post('admin/enrollments/{enrollment}/status', [EnrollmentController::class, 'changeStatus'])->name('v1.admin.enrollments.status');
    Route::post('admin/enrollments/{enrollment}/exit', [EnrollmentController::class, 'exit'])->name('v1.admin.enrollments.exit');

    Route::get('admin/residents/{resident}/assistance-history', [EnrollmentController::class, 'historyForResident'])->name('v1.admin.residents.assistance-history');

    /*
     * ── requirements, documents and the office's requests ─────────────────────────────
     *
     * The Files module publishes NO routes. It cannot answer "may this caller see this
     * document" — only the module owning the record can, and here that means this case's
     * barangay scope. So every file operation in the system enters through a controller that
     * has already resolved a case (ADR 0020 §5).
     *
     * Recording a document and accepting one are separate permissions: the clerk who took the
     * paper at the counter is not thereby the person who judged it sufficient.
     */
    Route::get('admin/cases/{case}/requirements', [CaseRequirementController::class, 'index'])->name('v1.admin.cases.requirements.index');
    Route::post('admin/cases/{case}/requirements', [CaseRequirementController::class, 'attachTemplate'])->name('v1.admin.cases.requirements.attach');
    Route::post('admin/cases/{case}/requirements/{requirement}/documents', [CaseRequirementController::class, 'recordDocument'])->name('v1.admin.cases.requirements.documents.store');
    Route::get('admin/cases/{case}/requirements/{requirement}/documents', [CaseRequirementController::class, 'history'])->name('v1.admin.cases.requirements.documents.history');
    Route::post('admin/cases/{case}/requirements/{requirement}/verification', [CaseRequirementController::class, 'verify'])->name('v1.admin.cases.requirements.verify');
    Route::post('admin/cases/{case}/requirements/{requirement}/applicability', [CaseRequirementController::class, 'decideApplicability'])->name('v1.admin.cases.requirements.applicability');
    Route::post('admin/cases/{case}/requirements/{requirement}/documents/{version}/access', [CaseRequirementController::class, 'openDocument'])->name('v1.admin.cases.requirements.documents.access');

    Route::get('admin/cases/{case}/document-requests', [CaseRequirementController::class, 'listRequests'])->name('v1.admin.cases.document-requests.index');
    Route::post('admin/cases/{case}/requirements/{requirement}/document-requests', [CaseRequirementController::class, 'requestDocument'])->name('v1.admin.cases.document-requests.store');
    Route::post('admin/cases/{case}/document-requests/{documentRequest}/withdraw', [CaseRequirementController::class, 'withdrawRequest'])->name('v1.admin.cases.document-requests.withdraw');

    /*
     * ── the applicant supplying what their own case needs ─────────────────────────────
     *
     * Ownership is part of every lookup here: the resident comes from the token, the case from
     * that resident, the requirement from that case. There is no identifier in the contract that
     * widens what the caller can reach.
     */
    Route::get('me/cases/{case}/requirements', [MyRequirementController::class, 'index'])->name('v1.me.cases.requirements.index');
    Route::post('me/cases/{case}/requirements/{requirement}/documents', [MyRequirementController::class, 'upload'])->name('v1.me.cases.requirements.documents.store');
    Route::post('me/cases/{case}/requirements/{requirement}/documents/{version}/access', [MyRequirementController::class, 'open'])->name('v1.me.cases.requirements.documents.access');

    /*
     * THE ONE PLACE A DOCUMENT LEAVES THIS SYSTEM.
     *
     * There is no file id in this contract. The only thing that opens a document is a handle
     * issued to this account moments ago for one version, already scope-checked by whichever
     * controller issued it — which is what makes a guessed id worthless.
     */
    Route::get('documents/{handle}', DocumentDownloadController::class)->name('v1.documents.download');

    /*
     * ── referrals ─────────────────────────────────────────────────────────────────────
     *
     * A REFERRAL IS THE ONE RECORD THAT LEAVES THE BUILDING, so the surface is shaped around the
     * moment it does. Drafting, disclosure planning and status-recording are all `referral.manage`;
     * `send` alone carries `referral.send`, because once the sheet is out this office no longer
     * controls who reads it and nothing can be taken back (ADR 0021 §5).
     *
     * The disclosure endpoints are separate from the referral itself on purpose: what was
     * released, to whom and why is a record in its own right, not a field on a form.
     */
    Route::get('admin/referrals', [ReferralController::class, 'index'])->name('v1.admin.referrals.index');
    Route::post('admin/referrals', [ReferralController::class, 'store'])->name('v1.admin.referrals.store');
    Route::get('admin/referrals/{referral}', [ReferralController::class, 'show'])->name('v1.admin.referrals.show');
    Route::patch('admin/referrals/{referral}', [ReferralController::class, 'update'])->name('v1.admin.referrals.update');

    Route::post('admin/referrals/{referral}/authority', [ReferralController::class, 'recordAuthority'])->name('v1.admin.referrals.authority');
    Route::post('admin/referrals/{referral}/shared-fields', [ReferralController::class, 'shareField'])->name('v1.admin.referrals.shared-fields.store');
    Route::delete('admin/referrals/{referral}/shared-fields/{field}', [ReferralController::class, 'withholdField'])->name('v1.admin.referrals.shared-fields.destroy');
    Route::post('admin/referrals/{referral}/attachments', [ReferralController::class, 'attachDocument'])->name('v1.admin.referrals.attachments.store');
    Route::delete('admin/referrals/{referral}/attachments/{document}', [ReferralController::class, 'detachDocument'])->name('v1.admin.referrals.attachments.destroy');

    // The sheet itself. Producing one is a disclosure event and is audited as such, because a
    // printed sheet exists whether or not it is ever sent.
    Route::get('admin/referrals/{referral}/summary', [ReferralController::class, 'summary'])->name('v1.admin.referrals.summary');

    Route::post('admin/referrals/{referral}/send', [ReferralController::class, 'send'])->name('v1.admin.referrals.send');
    Route::post('admin/referrals/{referral}/status', [ReferralController::class, 'recordStatus'])->name('v1.admin.referrals.status');
    Route::post('admin/referrals/{referral}/notes', [ReferralController::class, 'addNote'])->name('v1.admin.referrals.notes.store');

    /*
     * The applicant's own view. Status and a fixed message only — never the reason, the
     * destination contact, the outcome text or any note (ADR 0021 §6).
     */
    Route::get('me/referrals', [MyReferralController::class, 'index'])->name('v1.me.referrals.index');
});
