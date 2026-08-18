<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tasks\Http\Controllers\V1\TaskController;
use Modules\Tasks\Http\Controllers\V1\WorkController;

/*
 * Tasks routes. Mounted under /api/v1 by routes/api.php.
 *
 * A TASK PAYLOAD CARRIES NOTHING WORTH READING — a type, an opaque identifier and a short
 * instruction. The subject is opened through its own module's endpoint, which does its own check.
 *
 * That is how "team membership alone does not grant access to a linked sensitive entity" is held:
 * not by a permission check per row, which the first new field would forget, but by there being
 * nothing on the row to protect (ADR 0024 §2).
 */

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     * One endpoint, several queues. `?mine=1`, `?overdue=1`, `?due_today=1`, `?upcoming=1`, plus
     * type/priority/status/team filters.
     *
     * `mine` resolves from the token and never from a parameter: a queue filtered by an account id
     * in the query string is a queue anybody can point at anybody.
     */
    /*
     * ── work queues (TAB 07) ──────────────────────────────────────────────────────────
     *
     * Derived views over the same `tasks` rows, read-only. Acting on an item goes to the
     * task's own endpoints below, which already audit — a queue that could also mutate
     * would be a second write path to one record.
     *
     * `team` is `staff.view` rather than `task.view` on purpose: reading a colleague's
     * caseload is supervision, not a default that comes with having a queue of your own.
     */
    Route::get('admin/work/mine', [WorkController::class, 'mine'])->name('v1.admin.work.mine');
    Route::get('admin/work/team', [WorkController::class, 'team'])->name('v1.admin.work.team');
    Route::get('admin/work/alerts', [WorkController::class, 'alerts'])->name('v1.admin.work.alerts');

    Route::get('tasks', [TaskController::class, 'index'])->name('v1.tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('v1.tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('v1.tasks.show');

    // Records an outcome and nothing else. Completing "confirm the release" does not confirm the
    // release — it records that somebody says they did.
    Route::post('tasks/{task}/closure', [TaskController::class, 'close'])->name('v1.tasks.close');
    Route::post('tasks/{task}/assignment', [TaskController::class, 'assign'])->name('v1.tasks.assign');
});
