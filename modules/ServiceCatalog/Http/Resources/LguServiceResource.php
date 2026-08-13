<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Http\Resources;

use Modules\ServiceCatalog\Domain\LguService;

/**
 * Serialises a catalog entry for /api/v1.
 *
 * Identifiers exposed to clients are UUIDs and enums are lowercase strings
 * (docs/api/conventions.md §6). The shape is identical for every channel — clients differ
 * in WHICH entries they may see, not in how an entry looks.
 */
final class LguServiceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(LguService $service): array
    {
        return [
            'id' => $service->id,
            'code' => $service->code,
            'name' => $service->name,
            'description' => $service->description,
            'category' => $service->category->value,
            'status' => $service->status->value,
            'available_channels' => array_map(
                static fn ($channel): string => $channel->value,
                $service->availableChannels,
            ),
        ];
    }
}
