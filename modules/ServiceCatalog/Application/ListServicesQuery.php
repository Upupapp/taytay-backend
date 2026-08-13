<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ServiceCatalog\Domain\LguService;
use Modules\ServiceCatalog\Domain\ServiceCatalogRepository;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;

/**
 * Lists catalog entries for ANY client — citizen web, citizen mobile, admin console.
 *
 * This is the reference implementation of ADR 0002: there is exactly one query, and the
 * caller's channel or URL changes nothing. What differs is the ActorContext, and the only
 * thing that widens the result is a server-resolved permission.
 *
 * Consequence, asserted in tests: an LGU admin calling the citizen URL sees drafts, and a
 * resident calling the /admin URL does not. Authority lives in the actor, not the route.
 *
 * Cross-module dependency on AccessControl is through its Application layer only, which
 * is the one direction CLAUDE.md Article 2 permits.
 */
final class ListServicesQuery
{
    public function __construct(
        private readonly ServiceCatalogRepository $repository,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @return Page<LguService>
     */
    public function handle(ActorContext $actor, ListServicesCriteria $criteria): Page
    {
        $maySeeUnpublished = $this->authorization->allows($actor, Permission::ServicesViewUnpublished);

        $services = array_values(array_filter(
            $this->repository->all(),
            static function (LguService $service) use ($criteria, $maySeeUnpublished): bool {
                if (! $maySeeUnpublished && ! $service->isVisibleToPublic()) {
                    return false;
                }

                if ($criteria->category !== null && $service->category !== $criteria->category) {
                    return false;
                }

                return $criteria->channel === null || $service->isAvailableOn($criteria->channel);
            },
        ));

        // The catalog is small and bounded, so slicing in memory is safe here. A
        // database-backed repository must paginate in SQL instead.
        return Page::fromArray($services, $criteria->pagination);
    }
}
