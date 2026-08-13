<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ServiceCatalog\Domain\ServiceCatalogRepository;
use Modules\ServiceCatalog\Infrastructure\ConfigServiceCatalogRepository;

final class ServiceCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServiceCatalogRepository::class, ConfigServiceCatalogRepository::class);
    }
}
