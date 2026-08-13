<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure;

use Illuminate\Contracts\Config\Repository as Config;
use Modules\ServiceCatalog\Domain\LguService;
use Modules\ServiceCatalog\Domain\PublicationStatus;
use Modules\ServiceCatalog\Domain\ServiceCatalogRepository;
use Modules\ServiceCatalog\Domain\ServiceCategory;
use Modules\Shared\Application\ClientChannel;

/**
 * PROVISIONAL catalog store, backed by config/service_catalog.php.
 *
 * The catalog becomes an LGU-editable table when ServiceCatalog gains write use cases in
 * a later TAB; the interface it is bound to does not change, so no caller is affected.
 * Persisting a schema now, before the write side is designed, would violate the
 * expand-migrate-contract discipline in CLAUDE.md Article 6.
 */
final class ConfigServiceCatalogRepository implements ServiceCatalogRepository
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return list<LguService>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->config->get('service_catalog.services', []);

        return array_values(array_map(self::hydrate(...), $entries));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function hydrate(array $entry): LguService
    {
        /** @var list<string> $channels */
        $channels = $entry['channels'] ?? [];

        return new LguService(
            id: (string) $entry['id'],
            code: (string) $entry['code'],
            name: (string) $entry['name'],
            description: (string) ($entry['description'] ?? ''),
            category: ServiceCategory::from((string) $entry['category']),
            status: PublicationStatus::from((string) $entry['status']),
            availableChannels: array_values(array_filter(array_map(
                static fn (string $channel): ?ClientChannel => ClientChannel::tryFrom($channel),
                $channels,
            ))),
        );
    }
}
