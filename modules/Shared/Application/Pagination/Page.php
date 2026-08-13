<?php

declare(strict_types=1);

namespace Modules\Shared\Application\Pagination;

/**
 * A slice of a collection plus the metadata clients need to walk it.
 *
 * Application services return this rather than a framework paginator so that the domain
 * layer stays free of HTTP concerns and so that every module emits the same `meta`.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public PaginationParams $params,
    ) {}

    /**
     * Slice an already-materialised list. Only for small, bounded collections
     * (config-backed catalogs); database-backed queries must paginate in SQL.
     *
     * @param  list<T>  $items
     * @return self<T>
     */
    public static function fromArray(array $items, PaginationParams $params): self
    {
        return new self(
            array_values(array_slice($items, $params->offset(), $params->perPage)),
            count($items),
            $params,
        );
    }

    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->params->perPage);
    }

    public function hasMore(): bool
    {
        return $this->params->page < $this->totalPages();
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_more: bool}
     */
    public function meta(): array
    {
        return [
            'page' => $this->params->page,
            'per_page' => $this->params->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages(),
            'has_more' => $this->hasMore(),
        ];
    }

    /**
     * @template TOut
     *
     * @param  callable(T): TOut  $transform
     * @return self<TOut>
     */
    public function map(callable $transform): self
    {
        return new self(array_map($transform, $this->items), $this->total, $this->params);
    }
}
