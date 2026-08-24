<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Every rate limiter in one place (ADR 0035 §2).
 *
 * BEFORE THIS THEY LIVED IN THREE PROVIDERS AND A LITERAL, and the three surfaces the master
 * command names most explicitly — KYC submission, search and exports — had no limit at all. That is
 * the predictable result of defining limiters next to the module that needed one first: nobody
 * reviewing "are we rate limited?" can answer it without reading every provider, so nobody asks.
 *
 * **KEYED BY ACCOUNT WHERE THERE IS ONE.** A household behind a single connection is several
 * legitimate residents, and a barangay hall's wifi is dozens; keying by IP alone would throttle a
 * queue of people at a counter as though they were one abuser. IP is used only where there is no
 * account yet — and there it is paired with a hash of the identifier being tried, so neither
 * dimension alone is enough to get through.
 */
final class RateLimits
{
    public static function register(): void
    {
        /*
         * ── unauthenticated ───────────────────────────────────────────────────────────
         *
         * Two limits per surface, and both must pass. An attacker rotating IP addresses still
         * hits the per-identifier limit; one hammering a single account from one address hits
         * both. Either alone is trivially defeated by the other's blind spot.
         */

        RateLimiter::for('identity-sign-in', fn (Request $request): array => [
            Limit::perMinute(self::limit('sign_in'))->by('ip:'.$request->ip()),
            Limit::perMinute(self::limit('sign_in'))->by('id:'.self::identifierKey($request)),
        ]);

        RateLimiter::for('identity-code-request', fn (Request $request): array => [
            Limit::perMinute(self::limit('code_request'))->by('ip:'.$request->ip()),
            Limit::perMinute(self::limit('code_request'))->by('id:'.self::identifierKey($request)),
        ]);

        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(self::limit('registration'))->by('ip:'.$request->ip()),
        ]);

        /*
         * ── authenticated ─────────────────────────────────────────────────────────────
         */

        RateLimiter::for('engagement', fn (Request $request): Limit => self::perAccount($request, 'engagement'));

        RateLimiter::for('event-registration', fn (Request $request): Limit => self::perAccount($request, 'event_registration'));

        /*
         * Each KYC submission puts a case in front of a human reviewer, so an unthrottled endpoint
         * is a denial-of-service attack on the office's attention rather than on the server.
         */
        RateLimiter::for('kyc-submission', fn (Request $request): Limit => self::perAccount($request, 'kyc_submission'));

        /*
         * An upload costs storage, a virus scan, and eventually somebody's attention (F28).
         *
         * Looser than `kyc-submission` on purpose: submitting is the act that queues a human, and
         * attaching a document is something an applicant reasonably does several times — a photo
         * of an ID that came out blurred, then the back of it, then a proof of address. A limit
         * tight enough to be interesting to an attacker would mostly punish somebody standing in
         * a barangay hall on a slow connection.
         */
        RateLimiter::for('uploads', fn (Request $request): Limit => self::perAccount($request, 'uploads'));

        /*
         * Search is the endpoint an enumeration attempt reaches for: it is built to answer partial
         * questions about many records at once. Every searcher is already scoped and authorized,
         * so this limits the rate of legitimate-looking questions rather than the answers.
         */
        RateLimiter::for('search', fn (Request $request): Limit => self::perAccount($request, 'search'));

        /*
         * PER HOUR, not per minute — the tightest authenticated limit in the system.
         *
         * An export is a copy of the database leaving this application's control (ADR 0026 §3).
         * Ten an hour is generous for somebody doing their job and useless to somebody
         * exfiltrating a caseload.
         */
        RateLimiter::for('export', fn (Request $request): Limit => Limit::perHour(
            self::limit('export_per_hour'),
        )->by(self::actorKey($request)));

        /*
         * The global backstop. A throttled response must still be a JSON envelope, or the mobile
         * client that handles 429 correctly gets an HTML page instead (conventions §4).
         */
        RateLimiter::for('api', fn (Request $request): Limit => self::perAccount($request, 'default'));
    }

    /**
     * The limiters this system declares, for the coverage test.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'identity-sign-in',
            'identity-code-request',
            'registration',
            'engagement',
            'event-registration',
            'kyc-submission',
            'uploads',
            'search',
            'export',
            'api',
        ];
    }

    private static function perAccount(Request $request, string $key): Limit
    {
        return Limit::perMinute(self::limit($key))->by(self::actorKey($request));
    }

    /**
     * The account, or the address if there is no account.
     *
     * Prefixed so an account id and an IP address can never collide in the limiter's key space —
     * unlikely, and the failure would be one caller silently consuming another's budget.
     */
    private static function actorKey(Request $request): string
    {
        $account = $request->user()?->getAuthIdentifier();

        return $account === null ? 'ip:'.$request->ip() : 'account:'.$account;
    }

    /**
     * A stable, non-reversible key for the identifier a caller is trying.
     *
     * HASHED, because a rate-limiter key ends up in a cache store and in whatever an operator
     * dumps while debugging — and the plaintext value here is an email address or a mobile number
     * belonging to somebody who has not even signed in yet (Article 5.5).
     */
    private static function identifierKey(Request $request): string
    {
        $identifier = (string) ($request->input('email') ?? $request->input('mobile_number') ?? '');

        return $identifier === '' ? 'anonymous' : hash('sha256', Str::lower($identifier));
    }

    private static function limit(string $key): int
    {
        return max(1, (int) config('security.rate_limits.'.$key, 60));
    }
}
