<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Shared\Application\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates a request end to end (docs/api/conventions.md §2).
 *
 * A client-supplied X-Request-Id is adopted only after sanitisation; otherwise one is
 * generated. The id is always echoed so a citizen can quote it to a support desk.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->adoptRequestId($request->headers->get(self::HEADER));

        $request->attributes->set('request_id', $this->context->requestId());

        $response = $next($request);
        $response->headers->set(self::HEADER, $this->context->requestId());

        return $response;
    }
}
