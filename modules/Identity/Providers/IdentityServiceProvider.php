<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerTokenIdentifiers();
    }

    /**
     * Gives every issued token a UUID.
     *
     * Sanctum keys tokens by autoincrement id, and a person managing their own sessions
     * has to be able to name one to revoke it. Handing out the sequential id would leak
     * how many tokens the system has ever issued (conventions §6), so the public handle
     * is a UUID assigned here.
     */
    private function registerTokenIdentifiers(): void
    {
        PersonalAccessToken::creating(static function (PersonalAccessToken $token): void {
            if (! $token->getAttribute('uuid')) {
                $token->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }
}
