<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides whether a response may be cached, and by whom (ADR 0032 §4).
 *
 * **THE DEFAULT IS `no-store`, AND EVERY ROUTE IS PRIVATE UNTIL IT SAYS OTHERWISE.**
 *
 * This is the single most consequential line in the file, and the direction matters more than the
 * mechanism. A response holding one resident's welfare case, sitting in a shared cache and served
 * to the next caller who asks for the same URL, is the disclosure this entire system exists to
 * prevent — and it does not require an authorization bug to happen. A CDN, a corporate proxy or a
 * browser's back button is enough.
 *
 * A route that forgets to declare itself is therefore private. The failure mode of forgetting is
 * "we cached less than we could have", which costs a little bandwidth, rather than "we served
 * somebody else's file", which cannot be undone.
 *
 * PUBLIC IS OPT-IN, PER ROUTE, AND ONLY FOR ANONYMOUS RESPONSES. A route marks itself with
 * `->defaults('cache', 'public')`, and even then the header is downgraded to `private` the moment
 * an authenticated caller is behind the request. The events list is genuinely public — until a
 * signed-in resident asks for it, at which point the response is *about* somebody, and a shared
 * cache must not keep it.
 *
 * NO ETag IS COMPUTED HERE. Laravel's `SetCacheHeaders` will hash a body for `ETag`, and an
 * `ETag` on a private response is a small fingerprint of that response sitting in a proxy's
 * memory. The public routes get `max-age`, which is the part that saves a phone on a poor
 * connection real time; a hash saves the last few bytes and is not worth the surface.
 */
final class ApplyCacheDirectives
{
    /** Responses that may never be stored anywhere, by anyone. */
    private const PRIVATE_DIRECTIVE = 'no-store, no-cache, private, must-revalidate';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Only successful reads are cacheable at all. A write has no cached form, and an error
        // body carries a request id that must not be replayed to a different caller.
        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            $response->headers->set('Cache-Control', self::PRIVATE_DIRECTIVE);

            return $response;
        }

        if (! $this->isPublic($request)) {
            $response->headers->set('Cache-Control', self::PRIVATE_DIRECTIVE);

            /*
             * Told explicitly, because a shared cache keying on URL alone would serve one
             * resident's answer to the next. `no-store` already forbids that; `Vary` is the
             * belt to its braces, and costs nothing.
             */
            $response->headers->set('Vary', 'Authorization, X-Client-Channel');

            return $response;
        }

        $seconds = max(0, (int) config('client.public_cache_seconds', 60));

        $response->headers->set('Cache-Control', sprintf('public, max-age=%d', $seconds));
        $response->headers->set('Vary', 'X-Client-Channel');

        return $response;
    }

    /**
     * Whether this response is genuinely about nobody.
     *
     * TWO CONDITIONS, BOTH REQUIRED. The route must declare itself public, **and** there must be
     * no authenticated caller — because the same URL answers both, and only one of those answers
     * is safe to hand to a stranger.
     */
    private function isPublic(Request $request): bool
    {
        if ($request->route()?->defaults['cache'] ?? null) {
            return $request->route()->defaults['cache'] === 'public'
                && $request->user() === null
                && $request->bearerToken() === null;
        }

        return false;
    }
}
