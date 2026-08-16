<?php

declare(strict_types=1);

namespace Modules\Reporting\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reporting owns aggregates, report definitions and the export lifecycle.
 *
 * IT OWNS NO FACTS. Every number here is counted from another module's tables, and nothing in
 * this module is the canonical source of anything except the record that an export was asked for.
 * That is why it reads through query builders rather than importing other modules' services: a
 * dashboard is a read model, and a read model that could write would be a second authority.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
