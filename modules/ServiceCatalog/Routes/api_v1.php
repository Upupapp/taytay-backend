<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceCatalog\Http\Controllers\V1\ProgramController;
use Modules\ServiceCatalog\Http\Controllers\V1\ProviderController;
use Modules\ServiceCatalog\Http\Controllers\V1\ServiceCatalogController;

/*
 * ServiceCatalog routes. Mounted under /api/v1 by routes/api.php.
 *
 * Both routes below point at the SAME controller and the SAME application service. The
 * only difference is authentication; what a caller may see is decided by their
 * server-resolved permissions, never by which URL they used (ADR 0002).
 */

// PUBLIC BY DESIGN: the catalog of published services is public information, and citizens
// must be able to browse it before registering. Unpublished entries are excluded by the
// permission check inside ListServicesQuery, not by this route being public.
Route::get('services', [ServiceCatalogController::class, 'index'])
    ->name('v1.services.index');

/*
 * The `admin` prefix is routing convenience for the admin console. It grants nothing:
 * an authenticated resident reaching this URL gets exactly the published catalog, and an
 * LGU admin reaching the citizen URL above sees drafts. Asserted by
 * tests/Feature/Api/V1/ClientChannelIsNotAuthorityTest.php.
 */
Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
    Route::get('services', [ServiceCatalogController::class, 'index'])
        ->name('v1.admin.services.index');
});

/*
 * ── programmes ────────────────────────────────────────────────────────────────────────
 *
 * PUBLIC BY DESIGN, like the service catalogue above and for the same reason: citizens must be
 * able to see what the LGU offers before registering. The listing and the detail both narrow to
 * published AND citizen-visible for a caller without `program.view` — filtered at the query, so
 * an internal referral programme is absent from the rows and from the pagination total alike.
 *
 * One controller, one service, two audiences. What a caller sees is decided by their
 * server-resolved permissions, never by which URL they used (ADR 0002).
 */
Route::get('programs', [ProgramController::class, 'index'])->name('v1.programs.index');
Route::get('programs/{program}', [ProgramController::class, 'show'])->name('v1.programs.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('admin/programs', [ProgramController::class, 'store'])->name('v1.admin.programs.store');
    Route::patch('admin/programs/{program}', [ProgramController::class, 'update'])->name('v1.admin.programs.update');
    Route::post('admin/programs/{program}/status', [ProgramController::class, 'changeStatus'])->name('v1.admin.programs.status');
    Route::post('admin/programs/{program}/requirements', [ProgramController::class, 'storeRequirement'])->name('v1.admin.programs.requirements.store');
    Route::post('admin/programs/{program}/eligibility-criteria', [ProgramController::class, 'storeCriterion'])->name('v1.admin.programs.criteria.store');
    // Opens a new guidance version by copying the criteria forward. Editing them in place would
    // rewrite the rules a past decision was made against (ADR 0018 §6).
    Route::post('admin/programs/{program}/guidance-versions', [ProgramController::class, 'publishGuidanceVersion'])->name('v1.admin.programs.guidance-versions.store');

    /*
     * ── the service provider directory ────────────────────────────────────────────────
     *
     * STAFF ONLY, and not because the information is secret — most of it is on a signboard. A
     * public directory of "offices the MSWDO refers welfare clients to" is a map of where
     * vulnerable people are sent, and publishing one invites impersonation of exactly the offices
     * families are told to trust.
     *
     * It lives in ServiceCatalog rather than Welfare because it is a catalogue of who provides
     * what — the same kind of fact as a programme, and one that outlives any particular referral.
     */
    Route::get('admin/service-providers', [ProviderController::class, 'index'])->name('v1.admin.providers.index');
    Route::post('admin/service-providers', [ProviderController::class, 'store'])->name('v1.admin.providers.store');
    Route::get('admin/service-providers/{provider}', [ProviderController::class, 'show'])->name('v1.admin.providers.show');
    Route::patch('admin/service-providers/{provider}', [ProviderController::class, 'update'])->name('v1.admin.providers.update');
    Route::post('admin/service-providers/{provider}/status', [ProviderController::class, 'changeStatus'])->name('v1.admin.providers.status');
    // A directory nobody re-checks is a list of disconnected numbers within two years, and the
    // failure is silent: the referral goes out, nobody answers, the family finds out last.
    Route::post('admin/service-providers/{provider}/verification', [ProviderController::class, 'verify'])->name('v1.admin.providers.verify');
});
