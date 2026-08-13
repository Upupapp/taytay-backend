<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

interface ServiceCatalogRepository
{
    /**
     * Every catalog entry regardless of publication status. Filtering by what the actor
     * may see is an application-layer authorization concern, never the repository's.
     *
     * @return list<LguService>
     */
    public function all(): array;
}
