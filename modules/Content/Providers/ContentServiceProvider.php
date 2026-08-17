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
        /*
         * Engagement rate limit, registered by the module that owns the endpoints.
         *
         * KEYED BY ACCOUNT, NOT BY IP. A household behind one connection is several legitimate
         * residents, and a barangay hall's public wifi is dozens — an IP limit would silence a
         * whole neighbourhood because one person was enthusiastic.
         *
         * Twenty writes a minute is generous for a person and useless for a script. A blunt
         * instrument on purpose: the master command asks for a rate limit and explicitly defers
         * AI moderation, so this is the abuse control that exists today (ADR 0029 §5).
         */
    }
}
