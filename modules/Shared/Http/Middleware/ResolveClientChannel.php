<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records which client channel a request claims to come from.
 *
 * The value is recorded for audit and used for presentation defaults ONLY. It confers no
 * authority whatsoever (CLAUDE.md Article 3.3, ADR 0002), which is asserted by
 * tests/Feature/Api/V1/ClientChannelIsNotAuthorityTest.php.
 */
final class ResolveClientChannel
{
    public const HEADER = 'X-Client-Channel';

    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $channel = ClientChannel::fromHeader($request->headers->get(self::HEADER));

        $this->context->setChannel($channel);
        $request->attributes->set('client_channel', $channel);

        return $next($request);
    }
}
