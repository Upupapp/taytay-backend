<?php

declare(strict_types=1);

namespace Modules\Shared\Application\Pagination;

use Illuminate\Http\Request;
use Modules\Shared\Application\ClientChannel;

/**
 * Validated page/per-page input (docs/api/conventions.md §5).
 *
 * Out-of-range values are CLAMPED, not rejected: `?per_page=1000000` must not become a
 * resource-consumption vector (OWASP API4:2023), but it also must not fail a citizen's
 * request outright.
 */
final readonly class PaginationParams
{
    public const DEFAULT_PER_PAGE = 25;

    public const MIN_PER_PAGE = 1;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request, ?int $defaultPerPage = null): self
    {
        $channel = $request->attributes->get('client_channel');
        $default = $defaultPerPage
            ?? ($channel instanceof ClientChannel ? $channel->defaultPerPage() : self::DEFAULT_PER_PAGE);

        return new self(
            page: self::clampPage($request->query('page')),
            perPage: self::clampPerPage($request->query('per_page'), $default),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function clampPage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT);

        return $page === false || $page < 1 ? 1 : $page;
    }

    private static function clampPerPage(mixed $value, int $default): int
    {
        $perPage = filter_var($value, FILTER_VALIDATE_INT);

        if ($perPage === false) {
            $perPage = $default;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));
    }
}
