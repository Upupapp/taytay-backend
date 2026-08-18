<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Welfare\Http\Controllers\V1\AssessmentController;
use Modules\Welfare\Http\Controllers\V1\CaseController;
use Modules\Welfare\Http\Controllers\V1\CaseEligibilityController;
use Modules\Welfare\Http\Controllers\V1\CaseNoteController;
use Modules\Welfare\Http\Controllers\V1\CaseRequirementController;
use Modules\Welfare\Http\Controllers\V1\DocumentDownloadController;
use Modules\Welfare\Http\Controllers\V1\EnrollmentController;
use Modules\Welfare\Http\Controllers\V1\FieldVisitController;
use Modules\Welfare\Http\Controllers\V1\MyAssistanceController;
use Modules\Welfare\Http\Controllers\V1\MyCaseController;
use Modules\Welfare\Http\Controllers\V1\MyReferralController;
use Modules\Welfare\Http\Controllers\V1\MyRequirementController;
use Modules\Welfare\Http\Controllers\V1\ReferralController;
use Modules\Welfare\Http\Controllers\V1\ReleaseController;
use Modules\Welfare\Http\Controllers\V1\SafeguardingController;

/*
 * Welfare routes. Mounted under /api/v1 by routes/api.php.
 *
 * Two audiences, one lifecycle. The `/me/cases` routes resolve the resident from the
 * authenticated account, so an applicant can only ever reach their own and there is no
 * identifier to tamper with. The `/admin/assistance-requests` routes each require an explicit permission,
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
    Route::get('admin/assistance-requests', [CaseController::class, 'index'])->name('v1.admin.assistance-requests.index');
    Route::post('admin/assistance-requests', [CaseController::class, 'store'])->name('v1.admin.assistance-requests.store');
    Route::get('admin/assistance-requests/{case}', [CaseController::class, 'show'])->name('v1.admin.assistance-requests.show');
    Route::get('admin/assistance-requests/{case}/history', [CaseController::class, 'history'])->name('v1.admin.assistance-requests.history');

    /*
     * THE ONE LIFECYCLE ENDPOINT. Nine verbs would be nine places the transition map could be
     * forgotten, and the tenth added in a hurry would be the one that skipped it.
     */
    Route::post('admin/assistance-requests/{case}/transitions', [CaseController::class, 'transition'])->name('v1.admin.assistance-requests.transitions');

    Route::post('admin/assistance-requests/{case}/priority', [CaseController::class, 'changePriority'])->name('v1.admin.assistance-requests.priority');
    Route::post('admin/assistance-requests/{case}/assignment', [CaseController::class, 'assign'])->name('v1.admin.assistance-requests.assign');
    Route::delete('admin/assistance-requests/{case}/assignment', [CaseController::class, 'unassign'])->name('v1.admin.assistance-requests.unassign');
    Route::post('admin/assistance-requests/{case}/archive', [CaseController::class, 'archive'])->name('v1.admin.assistance-requests.archive');

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

    Route::get('admin/assistance-requests/{case}/assessment', [AssessmentController::class, 'show'])->name('v1.admin.assistance-requests.assessment.show');
    Route::post('admin/assistance-requests/{case}/assessment', [AssessmentController::class, 'open'])->name('v1.admin.assistance-requests.assessment.open');
    Route::patch('admin/assistance-requests/{case}/assessment', [AssessmentController::class, 'answer'])->name('v1.admin.assistance-requests.assessment.answer');
    Route::post('admin/assistance-requests/{case}/assessment/complete', [AssessmentController::class, 'complete'])->name('v1.admin.assistance-requests.assessment.complete');
    Route::get('admin/assistance-requests/{case}/prior-cases', [AssessmentController::class, 'history'])->name('v1.admin.assistance-requests.prior-cases');

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
    Route::get('admin/assistance-requests/{case}/eligibility-checks', [CaseEligibilityController::class, 'index'])->name('v1.admin.assistance-requests.eligibility.index');
    Route::post('admin/assistance-requests/{case}/eligibility-checks', [CaseEligibilityController::class, 'store'])->name('v1.admin.assistance-requests.eligibility.store');

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
    Route::get('admin/assistance-requests/{case}/requirements', [CaseRequirementController::class, 'index'])->name('v1.admin.assistance-requests.requirements.index');
    Route::post('admin/assistance-requests/{case}/requirements', [CaseRequirementController::class, 'attachTemplate'])->name('v1.admin.assistance-requests.requirements.attach');
    Route::post('admin/assistance-requests/{case}/requirements/{requirement}/documents', [CaseRequirementController::class, 'recordDocument'])->name('v1.admin.assistance-requests.requirements.documents.store');
    Route::get('admin/assistance-requests/{case}/requirements/{requirement}/documents', [CaseRequirementController::class, 'history'])->name('v1.admin.assistance-requests.requirements.documents.history');
    Route::post('admin/assistance-requests/{case}/requirements/{requirement}/verification', [CaseRequirementController::class, 'verify'])->name('v1.admin.assistance-requests.requirements.verify');
    Route::post('admin/assistance-requests/{case}/requirements/{requirement}/applicability', [CaseRequirementController::class, 'decideApplicability'])->name('v1.admin.assistance-requests.requirements.applicability');
    Route::post('admin/assistance-requests/{case}/requirements/{requirement}/documents/{version}/access', [CaseRequirementController::class, 'openDocument'])->name('v1.admin.assistance-requests.requirements.documents.access');

    Route::get('admin/assistance-requests/{case}/document-requests', [CaseRequirementController::class, 'listRequests'])->name('v1.admin.assistance-requests.document-requests.index');
    Route::post('admin/assistance-requests/{case}/requirements/{requirement}/document-requests', [CaseRequirementController::class, 'requestDocument'])->name('v1.admin.assistance-requests.document-requests.store');
    Route::post('admin/assistance-requests/{case}/document-requests/{documentRequest}/withdraw', [CaseRequirementController::class, 'withdrawRequest'])->name('v1.admin.assistance-requests.document-requests.withdraw');

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

    /*
     * ── field visits ──────────────────────────────────────────────────────────────────
     *
     * NO COORDINATE REACHES THIS CONTRACT. There is no check-in, no arrival ping, no route and no
     * field to send one to — `NoLocationTrackingTest` fails the build if one appears. A visit
     * records what was found, never where a worker was (ADR 0022 §1).
     *
     * An observation carries whose claim it is, which is the difference between "the roof is
     * missing sheets", "she says her husband stopped sending money" and "the household appears
     * unable to cope". As prose those become indistinguishable in six months.
     */
    Route::get('admin/visits', [FieldVisitController::class, 'index'])->name('v1.admin.visits.index');
    Route::post('admin/visits', [FieldVisitController::class, 'store'])->name('v1.admin.visits.store');
    Route::get('admin/visits/{visit}', [FieldVisitController::class, 'show'])->name('v1.admin.visits.show');
    Route::post('admin/visits/{visit}/observations', [FieldVisitController::class, 'observe'])->name('v1.admin.visits.observations.store');
    Route::post('admin/visits/{visit}/checklist', [FieldVisitController::class, 'check'])->name('v1.admin.visits.checklist');
    Route::post('admin/visits/{visit}/conclusion', [FieldVisitController::class, 'conclude'])->name('v1.admin.visits.conclusion');

    /*
     * ── the running record ────────────────────────────────────────────────────────────
     *
     * A reader without clearance still sees that a protected note EXISTS, who wrote it and when —
     * only the body is removed, and removed by the application rather than hidden by a client. A
     * caseworker who cannot see that three restricted entries exist reads the file as complete.
     */
    Route::get('admin/assistance-requests/{case}/notes', [CaseNoteController::class, 'index'])->name('v1.admin.assistance-requests.notes.index');
    Route::post('admin/assistance-requests/{case}/notes', [CaseNoteController::class, 'store'])->name('v1.admin.assistance-requests.notes.store');
    Route::post('admin/assistance-requests/{case}/notes/{note}/withdrawal', [CaseNoteController::class, 'withdraw'])->name('v1.admin.assistance-requests.notes.withdraw');

    /*
     * ── safeguarding ──────────────────────────────────────────────────────────────────
     *
     * THERE IS DELIBERATELY NO LIST ENDPOINT. A queue of safeguarding concerns is a list of
     * families under suspicion, and once it exists it will be filtered, sorted, exported and
     * eventually joined to something. Every read here is scoped to one named resident somebody
     * already had reason to open (ADR 0022 §4).
     */
    Route::get('admin/residents/{resident}/safeguarding', [SafeguardingController::class, 'forResident'])->name('v1.admin.residents.safeguarding');
    Route::post('admin/safeguarding-concerns', [SafeguardingController::class, 'store'])->name('v1.admin.safeguarding.store');
    Route::post('admin/safeguarding-concerns/{concern}/closure', [SafeguardingController::class, 'close'])->name('v1.admin.safeguarding.close');

    /*
     * ── release and distribution tracking ─────────────────────────────────────────────
     *
     * OPERATIONAL TRACKING, NOT A LEDGER. No journal entries, no posting, no reconciliation —
     * `funding_source` is a label for grouping a report, never a chart-of-accounts reference
     * (ADR 0023).
     *
     * THREE CONTROLS ON THE ONE OPERATION THAT MOVES MONEY, guarding three different failures:
     * an `Idempotency-Key` on confirmation (a retry over a weak connection), a row lock and
     * status re-check inside the service (two staff at two tables clicking at once), and a
     * segregation check on the *person* (the approver may not also release).
     */
    Route::get('admin/releases', [ReleaseController::class, 'index'])->name('v1.admin.releases.index');
    Route::get('admin/releases/{release}', [ReleaseController::class, 'show'])->name('v1.admin.releases.show');
    Route::post('admin/assistance-requests/{case}/releases', [ReleaseController::class, 'store'])->name('v1.admin.assistance-requests.releases.store');

    // The one operation that moves money. `request.release`, held by `disbursing_officer` and by
    // nobody who can approve a case.
    Route::post('admin/releases/{release}/confirmation', [ReleaseController::class, 'confirm'])->name('v1.admin.releases.confirm');
    Route::post('admin/releases/{release}/status', [ReleaseController::class, 'transition'])->name('v1.admin.releases.status');

    Route::post('admin/release-batches', [ReleaseController::class, 'storeBatch'])->name('v1.admin.release-batches.store');
    Route::post('admin/release-batches/{batch}/releases', [ReleaseController::class, 'addToBatch'])->name('v1.admin.release-batches.add');
    // Ordered by reference so two copies printed an hour apart match line for line.
    Route::get('admin/release-batches/{batch}/manifest', [ReleaseController::class, 'manifest'])->name('v1.admin.release-batches.manifest');
});
