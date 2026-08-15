<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AccessControl\Http\Controllers\V1\StaffController;

/*
 * Staff provisioning and authority (ADR 0012).
 *
 * NO PUBLIC ROUTE IN THIS FILE. Every route requires a token, and every one is authorized
 * again inside the controller or the service against `staff.view` / `staff.manage` — the
 * `auth:sanctum` middleware proves who is calling and nothing more (CLAUDE.md Article 3.5).
 *
 * There is no `admin/` prefix here on purpose. A route prefix is a naming convention, not
 * a permission; anything that reads as authority in a path invites somebody to treat it as
 * one (Article 3.4).
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('staff', [StaffController::class, 'index'])->name('v1.staff.index');
    Route::post('staff', [StaffController::class, 'store'])->name('v1.staff.store');

    // Before {staff}, so the literal segment is not swallowed by the wildcard.
    Route::get('staff/authority-catalog', [StaffController::class, 'catalog'])->name('v1.staff.catalog');

    Route::get('staff/{staff}', [StaffController::class, 'show'])->name('v1.staff.show');
    Route::delete('staff/{staff}', [StaffController::class, 'deactivate'])->name('v1.staff.deactivate');

    Route::post('staff/{staff}/roles', [StaffController::class, 'assignRole'])->name('v1.staff.roles.store');
    Route::delete('staff/{staff}/roles/{role}', [StaffController::class, 'revokeRole'])->name('v1.staff.roles.destroy');

    Route::post('staff/{staff}/barangays', [StaffController::class, 'grantBarangay'])->name('v1.staff.barangays.store');
    Route::delete('staff/{staff}/barangays/{barangay}', [StaffController::class, 'revokeBarangay'])->name('v1.staff.barangays.destroy');
});
