<?php

declare(strict_types=1);

namespace Modules\Welfare\Providers;

use Illuminate\Support\ServiceProvider;

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
}
