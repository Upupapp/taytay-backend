<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Application\AuditTrail;
use Modules\Audit\Application\ReassignConsentOnResidentMerge;
use Modules\Audit\Console\PreflightCommand;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use Modules\Shared\Contracts\AuditWriter;

/**
 * Audit owns the append-only trail and the privacy governance metadata around it.
 *
 * IT DEPENDS ON NOTHING BUT SHARED, and that is what lets every other module write to it. A module
 * that the whole system depends on cannot itself depend on the system: an audit trail that needed
 * `ResidentProfile` to record a resident merge would close a cycle with the module whose merges it
 * exists to record.
 *
 * It also OWNS NO FACTS ABOUT PEOPLE. Every row here describes an act, names the actor by
 * identifier, and names the record acted upon by identifier — never the contents of either.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * THE INVERSION (ADR 0034 §1). Ten modules write to the trail; this module also has an
         * HTTP surface that must ask AccessControl who may read it. Depending on the concrete
         * writer would close the cycle `AccessControl -> Audit -> AccessControl`, so the modules
         * depend on the interface in Shared and this binds the one implementation.
         *
         * No null fallback: if this binding is missing the application does not boot, which is
         * correct for a system holding Philippine personal data (Article 5.4).
         */
        $this->app->singleton(AuditWriter::class, AuditTrail::class);
    }

    public function boot(): void
    {
        /*
         * A merge repoints consent records. Without this line a merge leaves a consent pointing at
         * a soft-deleted resident, so a photograph published under a consent the office can no
         * longer find is a photograph published under no consent at all — and nothing would fail
         * (ADR 0019 §4).
         */
        Event::listen(ResidentMerged::class, ReassignConsentOnResidentMerge::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PreflightCommand::class]);
        }
    }
}
