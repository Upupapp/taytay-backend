<?php

declare(strict_types=1);

namespace Modules\Content\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Content owns admin-authored public communication.
 *
 * The only module whose records are meant to be read by people who are not their subject. That
 * inverts the usual risk: the danger is not disclosure of a row — publication is the point — but
 * publishing before it was meant to go out, or to an audience it was not meant for (ADR 0028).
 */
final class ContentServiceProvider extends ServiceProvider
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
