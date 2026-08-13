<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Illuminate\Http\Request;
use Modules\ServiceCatalog\Domain\ServiceCategory;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\Pagination\PaginationParams;

/**
 * Explicit, named query input.
 *
 * Filters are an enumerated allow-list, never a pass-through to the query builder
 * (docs/api/conventions.md §5) — an unrecognised filter value is dropped rather than
 * applied, so a client cannot widen or reshape the query.
 *
 * Note what is NOT here: anything about who is asking. Authority travels in the
 * ActorContext, resolved server-side.
 */
final readonly class ListServicesCriteria
{
    public function __construct(
        public PaginationParams $pagination,
        public ?ServiceCategory $category = null,
        public ?ClientChannel $channel = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $channel = $request->query('channel');

        return new self(
            pagination: PaginationParams::fromRequest($request),
            category: is_string($category) ? ServiceCategory::tryFrom($category) : null,
            // An explicit ?channel= filter is a presentation filter chosen by the caller.
            // It is unrelated to the X-Client-Channel header and confers no authority.
            channel: is_string($channel) ? ClientChannel::tryFrom($channel) : null,
        );
    }
}
