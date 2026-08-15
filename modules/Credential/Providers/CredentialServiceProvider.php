<?php

declare(strict_types=1);

namespace Modules\Credential\Providers;

use Illuminate\Support\ServiceProvider;

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
}
