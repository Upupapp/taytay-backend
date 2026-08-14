<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerTokenIdentifiers();
        $this->registerRateLimiters();
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

    /**
     * Rate limits for the sign-in surface.
     *
     * Keyed by IP **and** by the submitted identifier. IP alone lets an attacker spread
     * attempts across a botnet against one account; identifier alone lets one attacker
     * lock every account they can name. Both together bound each dimension.
     *
     * The identifier is hashed into the key: rate-limit keys are stored in the cache and
     * turn up in logs and dashboards, and a plaintext key would put every attempted email
     * address and mobile number there (CLAUDE.md Article 5.5).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('identity-sign-in', fn (Request $request): array => [
            Limit::perMinute((int) config('identity.rate_limits.sign_in'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('identity.rate_limits.code_verify'))->by('id:'.self::identifierKey($request)),
        ]);

        RateLimiter::for('identity-code-request', fn (Request $request): array => [
            Limit::perMinute((int) config('identity.rate_limits.code_request'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('identity.rate_limits.code_request'))->by('id:'.self::identifierKey($request)),
        ]);

        // A throttled response must still be a JSON envelope, or the mobile client that
        // handles 429 correctly gets an HTML page instead (conventions §4).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    private static function identifierKey(Request $request): string
    {
        $identifier = (string) ($request->input('email') ?? $request->input('mobile_number') ?? '');

        return $identifier === '' ? 'anonymous' : hash('sha256', Str::lower($identifier));
    }
}
