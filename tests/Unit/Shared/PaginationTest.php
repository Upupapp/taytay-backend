<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Illuminate\Http\Request;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    #[Test]
    public function it_clamps_page_size_into_the_permitted_range(): void
    {
        $this->assertSame(PaginationParams::MAX_PER_PAGE, $this->paramsFor(['per_page' => '1000000'])->perPage);
        $this->assertSame(PaginationParams::MIN_PER_PAGE, $this->paramsFor(['per_page' => '0'])->perPage);
        $this->assertSame(PaginationParams::MIN_PER_PAGE, $this->paramsFor(['per_page' => '-1'])->perPage);
        $this->assertSame(50, $this->paramsFor(['per_page' => '50'])->perPage);
    }

    #[Test]
    public function it_falls_back_to_the_default_for_unusable_input(): void
    {
        $this->assertSame(PaginationParams::DEFAULT_PER_PAGE, $this->paramsFor(['per_page' => 'all'])->perPage);
        $this->assertSame(PaginationParams::DEFAULT_PER_PAGE, $this->paramsFor([])->perPage);
    }

    #[Test]
    public function it_normalises_the_page_number_to_one_based(): void
    {
        $this->assertSame(1, $this->paramsFor([])->page);
        $this->assertSame(1, $this->paramsFor(['page' => '0'])->page);
        $this->assertSame(1, $this->paramsFor(['page' => '-7'])->page);
        $this->assertSame(1, $this->paramsFor(['page' => 'last'])->page);
        $this->assertSame(4, $this->paramsFor(['page' => '4'])->page);
    }

    #[Test]
    public function the_channel_supplies_the_default_page_size_when_the_client_does_not(): void
    {
        $request = Request::create('/api/v1/services');
        $request->attributes->set('client_channel', ClientChannel::CitizenMobile);

        $this->assertSame(15, PaginationParams::fromRequest($request)->perPage);

        // An explicit client value still wins over the channel default.
        $explicit = Request::create('/api/v1/services', 'GET', ['per_page' => '40']);
        $explicit->attributes->set('client_channel', ClientChannel::CitizenMobile);

        $this->assertSame(40, PaginationParams::fromRequest($explicit)->perPage);
    }

    #[Test]
    public function it_computes_the_offset_from_the_page(): void
    {
        $this->assertSame(0, (new PaginationParams(1, 25))->offset());
        $this->assertSame(25, (new PaginationParams(2, 25))->offset());
        $this->assertSame(60, (new PaginationParams(4, 20))->offset());
    }

    #[Test]
    public function a_page_slices_the_collection_and_reports_totals(): void
    {
        $items = range(1, 7);

        $page = Page::fromArray($items, new PaginationParams(2, 3));

        $this->assertSame([4, 5, 6], $page->items);
        $this->assertSame(7, $page->total);
        $this->assertSame(3, $page->totalPages());
        $this->assertTrue($page->hasMore());
        $this->assertSame(
            ['page' => 2, 'per_page' => 3, 'total' => 7, 'total_pages' => 3, 'has_more' => true],
            $page->meta(),
        );
    }

    #[Test]
    public function the_final_page_reports_no_more_results(): void
    {
        $page = Page::fromArray(range(1, 7), new PaginationParams(3, 3));

        $this->assertSame([7], $page->items);
        $this->assertFalse($page->hasMore());
    }

    #[Test]
    public function a_page_beyond_the_end_is_empty_rather_than_an_error(): void
    {
        $page = Page::fromArray(range(1, 7), new PaginationParams(99, 3));

        $this->assertSame([], $page->items);
        $this->assertSame(7, $page->total);
        $this->assertFalse($page->hasMore());
    }

    #[Test]
    public function mapping_preserves_pagination_metadata(): void
    {
        $page = Page::fromArray(range(1, 7), new PaginationParams(1, 3))
            ->map(static fn (int $value): string => "n{$value}");

        $this->assertSame(['n1', 'n2', 'n3'], $page->items);
        $this->assertSame(7, $page->total);
        $this->assertTrue($page->hasMore());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function paramsFor(array $query): PaginationParams
    {
        return PaginationParams::fromRequest(Request::create('/api/v1/services', 'GET', $query));
    }
}
