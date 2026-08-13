<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

/**
 * Explicit lifecycle state for a catalog entry (CLAUDE.md Article 6): a single enumerated
 * state, never an inferred pair of booleans such as is_active/is_hidden.
 */
enum PublicationStatus: string
{
    /** Being prepared by LGU staff. Not visible to citizens. */
    case Draft = 'draft';

    /** Offered to citizens. */
    case Published = 'published';

    /** No longer offered, retained for historical transactions. Not visible to citizens. */
    case Retired = 'retired';

    /**
     * Whether a citizen with no special permission may see this entry.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
