<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Search owns record discovery and saved filter presets.
 *
 * IT OWNS NO RECORDS AND NO INDEX. Every searcher runs a scoped query against the owning module's
 * table, applying the same permission and the same barangay scope that module's detail endpoint
 * applies.
 *
 * A separate search index maintained alongside the authorization rules is an index that eventually
 * disagrees with them, and the disagreement is invisible: nobody notices a search returning one
 * extra row until somebody clicks it and gets a 404 they should not have been able to provoke
 * (ADR 0027 §1).
 */
final class SearchServiceProvider extends ServiceProvider
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
