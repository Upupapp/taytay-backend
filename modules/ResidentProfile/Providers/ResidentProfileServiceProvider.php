<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * ResidentProfile owns the canonical resident registry and the KYC lifecycle.
 *
 * No bindings yet: the services are concrete and resolved by the container directly. The
 * provider exists because `config/modules.php` requires one per module, which is what
 * makes "is this module loaded" answerable in one place (ADR 0001).
 */
final class ResidentProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
