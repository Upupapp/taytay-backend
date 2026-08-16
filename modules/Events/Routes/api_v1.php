<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Events\Http\Controllers\V1\EventController;
use Modules\Events\Http\Controllers\V1\EventRegistrationController;

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

Route::get('events', [EventController::class, 'publicIndex'])
    // Public and read-mostly: a poster's worth of information, identical for every reader.
    // Downgraded to `private` the moment a signed-in resident asks (ADR 0032 §4).
    ->defaults('cache', 'public')
    ->name('v1.events.index');
Route::get('events/{event}', [EventController::class, 'publicShow'])
    ->defaults('cache', 'public')
    ->name('v1.events.show');

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

/*
 * ── registration, waitlist and attendance (TAB 26) ────────────────────────────────────
 *
 * REGISTRATION IS AUTHENTICATED even though reading an event is not. Reading a poster is public;
 * taking one of a fixed number of seats is a claim on a scarce public resource, and an anonymous
 * one is unaccountable, uncancellable and trivially repeatable.
 *
 * Every citizen read below is scoped AT THE QUERY to the resident resolved from the token. There
 * is no citizen endpoint that takes a registration id and looks it up unscoped, which is how
 * "a citizen cannot access another resident's registration by changing the ID" is held — the row
 * is absent, so the answer is a 404 nobody had to remember to write (ADR 0031 §4).
 *
 * RATE LIMITED, per account. A register/withdraw loop is the cheapest way to churn a waitlist and
 * make the promotion job announce a seat to a different person every few seconds.
 */
Route::middleware(['auth:sanctum', 'throttle:engagement'])->group(function (): void {
    Route::post('events/{event}/registration', [EventRegistrationController::class, 'register'])
        ->name('v1.events.register');
    Route::delete('events/{event}/registration', [EventRegistrationController::class, 'withdraw'])
        ->name('v1.events.withdraw');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me/event-registrations', [EventRegistrationController::class, 'mine'])
        ->name('v1.me.event-registrations.index');
    Route::get('me/event-registrations/{registration}', [EventRegistrationController::class, 'mineShow'])
        ->name('v1.me.event-registrations.show');

    Route::get('admin/events/{event}/registrations', [EventRegistrationController::class, 'index'])
        ->name('v1.admin.events.registrations.index');
    // Declared before the `{registration}` routes so the literal segment is not swallowed.
    Route::post('admin/events/{event}/registrations/promote', [EventRegistrationController::class, 'promote'])
        ->name('v1.admin.events.registrations.promote');
    Route::post('admin/events/{event}/registrations/{registration}/cancel', [EventRegistrationController::class, 'cancel'])
        ->name('v1.admin.events.registrations.cancel');
    Route::post('admin/events/{event}/registrations/{registration}/restore', [EventRegistrationController::class, 'restore'])
        ->name('v1.admin.events.registrations.restore');
    // Its own permission: the person at the door is often not the person who wrote the event.
    Route::post('admin/events/{event}/registrations/{registration}/attendance', [EventRegistrationController::class, 'markAttendance'])
        ->name('v1.admin.events.registrations.attendance');
});
