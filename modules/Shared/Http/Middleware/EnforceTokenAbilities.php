<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a token's abilities mean something.
 *
 * `TokenService` has always stamped every token with `staff` or `citizen`, and
 * until TAB 02 **nothing anywhere checked them** — no `tokenCan()`, no
 * `ability:` middleware, no route constraint. The grant was decorative, which is
 * the worst state for a security control to be in: it reads like enforcement in
 * the code that issues it, and enforces nothing at the point of use.
 *
 * That mattered the moment TAB 02 needed a *restricted* token. A staff account
 * that must use a second factor but has never enrolled one cannot be given a
 * full session — and cannot be refused outright either, because the enrolment
 * endpoints themselves require authentication, so refusing would lock the office
 * out of the only route to compliance. The answer is a token that can reach
 * enrolment and nothing else, and that answer is only safe if abilities are
 * actually enforced.
 *
 * DENY BY DEFAULT (Article 3.5). A token carrying `mfa-enrolment` may reach the
 * allow-list below and no other route. A route added next year is refused to
 * such a token without anybody remembering to think about it, because the
 * failure mode of forgetting must be "we refused something we could have
 * allowed", never "we served a half-authenticated caller".
 */
final class EnforceTokenAbilities
{
    /**
     * The ability an unenrolled staff member's token carries.
     */
    public const ENROLMENT_ABILITY = 'mfa-enrolment';

    /**
     * The only routes such a token may reach: enrol a factor, confirm it, and
     * end the session. Named routes rather than URIs, so a path change cannot
     * silently widen or narrow the hole.
     *
     * `me.show` is included because the enrolment screen has to be able to say
     * whose account it is enrolling. It returns the account and its permissions,
     * which is information the holder of the password already has.
     */
    private const ENROLMENT_ROUTES = [
        'v1.me.show',
        'v1.me.mfa.begin',
        'v1.me.mfa.confirm',
        'v1.auth.tokens.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            // No token on the request: either the route is public, or
            // `auth:sanctum` has already refused it. Not this middleware's
            // decision to make.
            return $next($request);
        }

        if (! $token->can(self::ENROLMENT_ABILITY)) {
            return $next($request);
        }

        if (in_array((string) $request->route()?->getName(), self::ENROLMENT_ROUTES, true)) {
            return $next($request);
        }

        /*
         * PUBLIC ROUTES ARE NOT THIS MIDDLEWARE'S BUSINESS.
         *
         * Sanctum resolves a bearer token even on a route that does not require
         * one, so without this an enrolment token would be refused at
         * `POST auth/tokens` — the very route the staff member must reach to
         * sign in properly once they have enrolled. The restriction is about
         * what an authenticated *session* may do, so it applies only where the
         * route demands authentication.
         */
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        $authenticated = array_filter(
            $middleware,
            static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'auth:'),
        );

        if ($authenticated === []) {
            return $next($request);
        }

        /*
         * 403, not 404. The existence of these endpoints is not a secret — the
         * caller is a known staff member holding a valid password — and telling
         * them plainly what is required is the difference between an office that
         * enrols and an office that files a support ticket about a broken
         * console. "Existence is a privilege" (conventions.md §4) protects
         * records, not the shape of the API.
         */
        throw new ApiException(
            ErrorCode::Forbidden,
            'Set up your authenticator app before using the console. This sign-in can only reach second-factor enrolment.',
        );
    }
}
