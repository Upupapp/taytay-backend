<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

use Modules\Shared\Application\ClientChannel;

/**
 * A service the LGU offers.
 *
 * A pure domain value object: no Eloquent, no HTTP, no framework. This is what lets the
 * same catalog be served to citizen web, mobile, admin and (later) a different store
 * without touching the rule below.
 */
final readonly class LguService
{
    /**
     * @param  list<ClientChannel>  $availableChannels
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $description,
        public ServiceCategory $category,
        public PublicationStatus $status,
        public array $availableChannels,
    ) {}

    /**
     * Visibility is decided by the actor's permissions, not by the URL they used and not
     * by the client they used (ADR 0002). This method answers only the domain half of the
     * question: is this entry public at all?
     */
    public function isVisibleToPublic(): bool
    {
        return $this->status->isPubliclyVisible();
    }

    public function isAvailableOn(ClientChannel $channel): bool
    {
        return in_array($channel, $this->availableChannels, true);
    }
}
