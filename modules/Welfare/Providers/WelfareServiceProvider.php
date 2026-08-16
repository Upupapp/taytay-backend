<?php

declare(strict_types=1);

namespace Modules\Welfare\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use Modules\Welfare\Application\ReassignWelfareRecordsOnResidentMerge;

/**
 * Welfare owns social welfare casework: the case, its lifecycle, its assignment and its
 * timeline.
 *
 * Distinct from `ServiceDelivery` (planned), which handles transactions against the service
 * catalog — cedula, permits, clearances. Those are counter transactions with a receipt; a
 * welfare case is casework with an assessment, a recommendation and a decision about public
 * money, and it carries a completely different lifecycle and audit obligation.
 *
 * No bindings: the services are concrete and resolved by the container directly. The provider
 * exists because `config/modules.php` requires one per module, which is what makes "is this
 * module loaded" answerable in one place (ADR 0001).
 */
final class WelfareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Welfare listens for a resident merge and repoints its own rows.
         *
         * Registered HERE, in the module that owns the data, which is what keeps the dependency
         * one-directional: ResidentProfile announces and knows nothing about who cares
         * (ADR 0013 §6).
         *
         * This closes a defect that opened when Welfare arrived in TAB 11 — the merge service
         * repointed the consumers that existed when it was written, and cases were not among
         * them, so a merge left them attached to a soft-deleted resident (ADR 0019 §4).
         */
        Event::listen(ResidentMerged::class, ReassignWelfareRecordsOnResidentMerge::class);
    }
}
