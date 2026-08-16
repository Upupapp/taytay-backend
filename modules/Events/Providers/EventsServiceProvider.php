<?php

declare(strict_types=1);

namespace Modules\Events\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Events owns official LGU events and, from TAB 26, their registrations.
 *
 * SEPARATE FROM `Content` even though both publish to the public. A newsfeed post is a statement;
 * an event is an operational commitment with a venue, a capacity and a list of people expecting to
 * be let in. Registration, waitlist and attendance are real state with race conditions, and
 * folding them into a content module would put them beside a table whose hardest problem is
 * scheduling a publish.
 */
final class EventsServiceProvider extends ServiceProvider
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
