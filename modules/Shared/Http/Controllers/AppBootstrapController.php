<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Http\ApiResponse;

/**
 * What a client needs before it knows anything else (ADR 0032 §2).
 *
 * UNAUTHENTICATED, AND IT HAS TO BE. An app that cannot start cannot sign in to be told
 * that it should update — a minimum-version gate behind authentication is a gate that opens
 * only for clients that did not need it. The same applies to a client that must show a
 * maintenance notice, or a support number, when nothing else is reachable.
 *
 * SO IT HOLDS NOTHING WORTH PROTECTING. Everything here is either already public (the API
 * version, a support number an LGU prints on a poster) or a rendering hint that grants nothing.
 * `config/client.php` says the same thing at the other end, and `NoBrowserSecretsTest` enforces
 * it.
 *
 * THE FEATURE FLAGS ARE NOT AUTHORIZATION. Article 3.4 is explicit: a value that reaches a
 * client is untrusted input on the way back. Each flag here is read from the config the owning
 * module already reads, so there is one source per flag; a client that ignored every one of them
 * would gain nothing, because the module behind each feature refuses independently.
 */
final class AppBootstrapController
{
    public function __invoke(Request $request): JsonResponse
    {
        $channel = ClientChannel::fromHeader($request->header('X-Client-Channel'));

        return ApiResponse::item([
            'service' => config('api.service_name'),
            'api_version' => config('api.version'),

            /*
             * SERVER TIME, and the reason it is here is the master command's requirement that no
             * critical operation depend on the client clock. Nothing on the server reads a
             * client-supplied time; this is published so a client with a wrong clock can notice
             * and show relative times honestly rather than telling somebody an event that starts
             * in an hour started yesterday.
             */
            'server_time' => now()->toIso8601ZuluString(),
            'timezone' => 'Asia/Manila',

            'client' => [
                // Echoed back so a client can see how its header was parsed. An unrecognised
                // channel degrades to `unknown` and proceeds with identical authority.
                'channel' => $channel->value,
                'default_page_size' => $channel->defaultPerPage(),
                /*
                 * THE SERVER DECIDES WHETHER A BUILD IS TOO OLD. A client that decides for itself
                 * is exactly the client that will not — a build with a broken update check cannot
                 * fix its own update check. Empty means no minimum, so a missing configuration
                 * never becomes an accidental hard block.
                 */
                'minimum_version' => (string) config('client.bootstrap.minimum_versions.'.$channel->value, ''),
            ],

            'features' => $this->features(),

            'support' => [
                'email' => (string) config('client.bootstrap.support.email', ''),
                'phone' => (string) config('client.bootstrap.support.phone', ''),
            ],

            /*
             * The conventions a retryable write follows, published rather than documented-only so
             * a client author does not have to find the markdown (ADR 0032 §3).
             */
            'conventions' => [
                'idempotency_header' => 'Idempotency-Key',
                'request_id_header' => 'X-Request-Id',
                'channel_header' => 'X-Client-Channel',
            ],
        ]);
    }

    /**
     * Every flag read from the config its owning module reads.
     *
     * @return array<string, bool>
     */
    private function features(): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('client.bootstrap.features', []);

        $features = [];

        foreach ($map as $name => $configKey) {
            // Indirect on purpose: this endpoint reports what the module will actually do, and
            // cannot drift into claiming a feature is on while the module refuses it.
            $features[$name] = (bool) config($configKey, false);
        }

        return $features;
    }
}
