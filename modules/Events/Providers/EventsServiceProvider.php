<?php

declare(strict_types=1);

namespace Modules\Events\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Events\Application\ReassignEventRegistrationsOnResidentMerge;
use Modules\ResidentProfile\Contracts\ResidentMerged;

/**
 * Events owns official LGU events and the places people hold at them.
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
        /*
         * A listener rather than a call: Events depends on ResidentProfile, so the reverse call
         * would close a cycle (ADR 0019 §4). Without this line a merge leaves registrations
         * pointing at a soft-deleted resident — a name on the door list nobody can look up — and
         * `ResidentMergeCoverageTest` fails loudly rather than letting it be discovered at a
         * covered court.
         */
        Event::listen(ResidentMerged::class, ReassignEventRegistrationsOnResidentMerge::class);
    }
}
