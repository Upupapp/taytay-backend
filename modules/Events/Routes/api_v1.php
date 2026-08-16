<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Events\Http\Controllers\V1\EventController;

/*
 * Official LGU events. Mounted under /api/v1 by routes/api.php.
 *
 * THE READER ROUTES ARE OUTSIDE THE AUTH GROUP, and that is an affirmative choice recorded here as
 * Article 3.5 requires. An event is a public invitation: a poster with a QR code is read by
 * somebody who has no account, and requiring one to find out when the feeding programme starts
 * would be a barrier invented by the software rather than by the office.
 *
 * What makes that safe is not a check in the controller — it is that every reader route runs
 * through `EventService::publicQuery()`, which narrows on status AT THE QUERY. A draft is ABSENT
 * from a public lookup rather than filtered out of one, so "a draft event cannot be fetched via a
 * citizen endpoint" survives the next endpoint somebody adds (ADR 0030 §3).
 *
 * THERE IS NO CITIZEN WRITE ROUTE, AND NO CITIZEN WRITE METHOD BEHIND ONE. "A resident cannot
 * create or edit events" is not a permission check that could be omitted from a new endpoint —
 * there is nothing for one to call.
 */

Route::get('events', [EventController::class, 'publicIndex'])->name('v1.events.index');
Route::get('events/{event}', [EventController::class, 'publicShow'])->name('v1.events.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/events', [EventController::class, 'index'])->name('v1.admin.events.index');
    Route::post('admin/events', [EventController::class, 'store'])->name('v1.admin.events.store');
    Route::get('admin/events/{event}', [EventController::class, 'show'])->name('v1.admin.events.show');
    Route::patch('admin/events/{event}', [EventController::class, 'update'])->name('v1.admin.events.update');

    // Publish / cancel / complete / archive. The permission comes from the TARGET state, so
    // publishing and cancelling can be held more narrowly than drafting.
    Route::post('admin/events/{event}/status', [EventController::class, 'transition'])
        ->name('v1.admin.events.status');

    // The office runs the same programme every month; retyping a venue is how one gets it wrong.
    Route::post('admin/events/{event}/duplicate', [EventController::class, 'duplicate'])
        ->name('v1.admin.events.duplicate');

    Route::get('admin/events/{event}/registration-summary', [EventController::class, 'registrationSummary'])
        ->name('v1.admin.events.registration-summary');
});
