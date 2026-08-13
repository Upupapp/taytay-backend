<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Shared\Support\ModuleRegistry;

/**
 * Boots the modular monolith (ADR 0001).
 *
 * `app/` holds framework wiring only; this provider is the single seam between the
 * Laravel application and the domain modules under `modules/`.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (ModuleRegistry::providers() as $provider) {
            $this->app->register($provider);
        }
    }
}
