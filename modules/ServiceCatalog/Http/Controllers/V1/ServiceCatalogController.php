<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceCatalog\Application\ListServicesCriteria;
use Modules\ServiceCatalog\Application\ListServicesQuery;
use Modules\ServiceCatalog\Http\Resources\LguServiceResource;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Http\ApiResponse;

/**
 * ONE controller, mounted at both the citizen and the admin route (see Routes/api_v1.php).
 *
 * This is the multi-client rule made concrete (CLAUDE.md Article 3, ADR 0002): there is
 * no citizen copy and no admin copy to drift apart. The controller only translates HTTP
 * to a query and back — it holds no business rule and makes no authorization decision of
 * its own.
 *
 * The ActorContext is injected from the container, where AccessControl built it from
 * authenticated state. It cannot be supplied or influenced by the request body.
 */
final class ServiceCatalogController
{
    public function __construct(private readonly ListServicesQuery $listServices) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $page = $this->listServices->handle($actor, ListServicesCriteria::fromRequest($request));

        return ApiResponse::page($page, LguServiceResource::toArray(...));
    }
}
