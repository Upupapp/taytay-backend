<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (ADR 0035 §3).
 *
 * BEFORE THIS, TWO ENDPOINTS SET THEM AND 259 DID NOT. The document download and the export
 * download each set `nosniff` by hand, because those are the two that return bytes — and that is
 * precisely the shape of control this project keeps finding: correct where somebody thought about
 * it, absent everywhere else, with no way to tell the difference from the outside.
 *
 * THIS IS A JSON API, WHICH MAKES THE POLICY UNUSUALLY STRICT. A browser should never render
 * anything here, never frame it, never load a script from it and never resolve a subresource. So
 * the CSP is `default-src 'none'` — not a tuned allow-list but a refusal of every fetch directive
 * at once — and `frame-ancestors 'none'` on top of `X-Frame-Options` because the two are read by
 * different browsers.
 *
 * The one response that is not JSON is a document or export **stream**, and it is the one that most
 * needs `nosniff`: a browser that content-sniffs a citizen's uploaded file and decides it is HTML
 * will execute it in the API's origin. Those endpoints keep their own headers, which now agree with
 * these rather than being the only ones.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            // A browser must not second-guess the declared type. The attack this prevents is a
            // citizen's uploaded file being sniffed as HTML and executed in this origin.
            'X-Content-Type-Options' => 'nosniff',

            // Nothing here is ever framed. Both headers, because they are read by different
            // browsers and the older one is still the one some corporate proxies enforce.
            'X-Frame-Options' => 'DENY',

            /*
             * A referrer from an API response would carry the request path — which for this API
             * contains resident and case identifiers — to whatever third party a browser navigated
             * to next. `no-referrer` is the only value that cannot leak one.
             */
            'Referrer-Policy' => 'no-referrer',

            // No cross-origin page may embed a response from this API as a resource.
            'Cross-Origin-Resource-Policy' => 'same-origin',

            /*
             * A JSON API renders nothing, so every fetch directive is refused at once rather than
             * tuned. `frame-ancestors` duplicates `X-Frame-Options` on purpose: modern browsers
             * prefer this one and ignore the other.
             */
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",

            /*
             * Powerful features disabled at the response level.
             *
             * `geolocation=()` is not decoration here: ADR 0022 §1 refused a location model for
             * this system, and this is the same refusal stated to the browser. If a page ever
             * renders from this origin it cannot ask where somebody is.
             */
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=(), usb=()',
        ];

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        /*
         * HSTS ONLY OVER HTTPS, AND ONLY WHEN THE LGU TURNS IT ON.
         *
         * The master command says "HSTS only after domain readiness", and the reason is that this
         * header is the one security control that is hard to take back: a browser that has seen it
         * refuses plain HTTP to the domain for the whole `max-age`, so a premature one on a domain
         * whose certificate is not yet right locks people out of the API with no server-side fix.
         *
         * Guarded twice — the request must actually be secure, and the config must be on — because
         * sending it over plain HTTP is meaningless and sending it from a staging box on a shared
         * parent domain can poison the production one via `includeSubDomains`.
         */
        if ($request->isSecure() && config('security.hsts.enabled', false) === true) {
            $directives = 'max-age='.(int) config('security.hsts.max_age', 31536000);

            if (config('security.hsts.include_subdomains', false) === true) {
                $directives .= '; includeSubDomains';
            }

            if (config('security.hsts.preload', false) === true) {
                $directives .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $directives);
        }

        return $response;
    }
}
