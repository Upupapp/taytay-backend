<?php

declare(strict_types=1);

namespace Modules\Tasks\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Tasks\Application\RaiseTaskOnReferralOverdue;
use Modules\Tasks\Application\RaiseTaskOnVisitFollowUp;
use Modules\Welfare\Contracts\ReferralBecameOverdue;
use Modules\Welfare\Contracts\VisitFollowUpDue;

/**
 * Tasks owns work queues and the automation that fills them.
 *
 * IT LISTENS AND NEVER CALLS BACK. Welfare announces that a referral went overdue or a visit
 * needs a follow-up; this module decides that means somebody owes work. Welfare does not know
 * Tasks exists, which is what keeps the dependency one-directional — the same inversion
 * `ResidentMerged` uses (ADR 0013 §6).
 *
 * The listeners are registered HERE, in the module that owns the data, and both events were
 * published by earlier TABs with no listener at all. That was deliberate: a seam built before it
 * is needed is a seam; a seam built after is a refactor.
 */
final class TasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(ReferralBecameOverdue::class, RaiseTaskOnReferralOverdue::class);
        Event::listen(VisitFollowUpDue::class, RaiseTaskOnVisitFollowUp::class);
    }
}
