<?php

declare(strict_types=1);

namespace Modules\Files\Domain;

/**
 * The renditions this system derives from an image.
 *
 * TWO, AND NOT MORE. Every variant is bytes somebody stores, a job somebody runs and an object
 * somebody has to remember to delete when the content is withdrawn. A "small / medium / large /
 * original / square / retina" ladder is six of each, and five of them are never requested.
 *
 * A thumbnail for a list, and a web rendition for the one somebody opened. If a client needs a
 * size between the two it scales the web one, which costs a phone nothing and this office an
 * object it does not have to reason about.
 */
enum MediaVariant: string
{
    /** For a feed row or a grid. */
    case Thumbnail = 'thumbnail';

    /** For the detail view. Large enough to read a notice, small enough for a phone. */
    case Web = 'web';

    /**
     * The longest side, in pixels.
     *
     * NOT the shortest and not a fixed box: a poster is portrait, a group photograph is landscape,
     * and constraining the longest edge keeps both proportional without cropping a face out of one
     * of them.
     */
    public function maxEdge(): int
    {
        return match ($this) {
            self::Thumbnail => 400,
            self::Web => 1280,
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $variant): string => $variant->value, self::cases());
    }
}
