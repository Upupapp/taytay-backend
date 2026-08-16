<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

use Modules\Files\Domain\AcceptedMediaType;

/**
 * What this system accepts, published so clients enforce the same limits the server does.
 *
 * The signatures themselves stay inside {@see AcceptedMediaType} — how the server proves a file
 * is what it claims is not another module's business, and publishing the magic bytes would
 * invite somebody to reimplement the check instead of calling it.
 *
 * What IS published is the pair a client needs to give somebody a useful message before a slow
 * upload: the types and the ceiling. Both clients already hold their own copies of these
 * numbers; serving them here is what stops those copies drifting from the boundary that actually
 * decides.
 */
final class UploadPolicy
{
    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return AcceptedMediaType::values();
    }

    public static function maxBytes(): int
    {
        return AcceptedMediaType::MAX_BYTES;
    }

    /**
     * @return array{mime_types: list<string>, max_bytes: int}
     */
    public static function toArray(): array
    {
        return [
            'mime_types' => self::acceptedMimeTypes(),
            'max_bytes' => self::maxBytes(),
        ];
    }
}
