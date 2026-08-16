<?php

declare(strict_types=1);

namespace Modules\Files\Domain;

/**
 * Whether a derived object is reachable without authorization.
 *
 * RECORDED ON THE ROW rather than inferred from which disk it landed on. "Is this object publicly
 * reachable" then has an answer a query can give and a test can assert, instead of one that
 * depends on knowing which disk name maps to which bucket in which environment — which is exactly
 * the kind of knowledge that is correct in the head of the person who set it up and nowhere else.
 */
enum MediaVisibility: string
{
    case Private = 'private';

    /**
     * Reachable by anybody with the URL.
     *
     * Only ever set by `MediaPublisher`, only for re-encoded derivatives, and only when the module
     * owning the content says the content is published (ADR 0033 §3).
     */
    case Public = 'public';

    public function disk(): string
    {
        return $this === self::Public
            ? (string) config('files.public_disk', 'public-media')
            : (string) config('files.disk', 'object-storage');
    }
}
