<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Shared\Http\ApiResponse;

/**
 * Unauthenticated liveness probe (docs/api/conventions.md §9).
 *
 * Deliberately minimal: no framework version, no environment name, no dependency status,
 * no configuration. A public health endpoint must not become a reconnaissance surface.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::item([
            'service' => config('api.service_name'),
            'status' => 'ok',
            'api_version' => config('api.version'),
        ]);
    }
}
