<?php

declare(strict_types=1);

namespace Modules\Credential\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Credential\Application\ReassignCredentialsOnResidentMerge;
use Modules\ResidentProfile\Contracts\ResidentMerged;

/**
 * Credential owns the digital ID and its verification log.
 *
 * The module is registered unconditionally; the `credential.digital_id.enabled` flag is
 * checked at the service boundary rather than here, so the routes and the contract matrix
 * stay honest about what exists while the flag decides whether it answers (ADR 0011).
 */
final class CredentialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Credential listens for a resident merge and repoints its own rows.
         *
         * Registered HERE, in the module that owns the data, rather than in ResidentProfile
         * — that is what makes the dependency one-directional. ResidentProfile announces;
         * this module decides that a merge means its cards move (ADR 0013 §6).
         *
         * Deliberately NOT gated on `credential.digital_id.enabled`: credentials issued
         * while the feature was on must still follow their holder after it is switched off,
         * or turning the flag off would silently strand them on a soft-deleted resident.
         */
        Event::listen(ResidentMerged::class, ReassignCredentialsOnResidentMerge::class);
    }
}
