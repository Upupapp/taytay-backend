<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Modules\Shared\Application\Pagination\PaginationParams;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Collections are always paginated, and out-of-range client input is clamped rather than
 * rejected (docs/api/conventions.md §5, ADR 0003).
 *
 * Clamping matters twice over: `?per_page=1000000` must not become a resource-consumption
 * vector (OWASP API4:2023), and a citizen on a bad connection must not get a 422 for a
 * silly query string.
 */
final class PaginationTest extends TestCase
{
    #[Test]
    public function every_collection_carries_pagination_meta(): void
    {
        $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['request_id', 'pagination' => ['page', 'per_page', 'total', 'total_pages', 'has_more']],
            ]);
    }

    #[Test]
    public function it_applies_the_default_page_size(): void
    {
        $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', PaginationParams::DEFAULT_PER_PAGE)
            ->assertJsonPath('meta.pagination.page', 1);
    }

    #[Test]
    public function the_mobile_channel_gets_a_smaller_default_page(): void
    {
        // Presentation default only — the channel still confers no authority.
        $this->getJson('/api/v1/services', ['X-Client-Channel' => 'citizen-mobile'])
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 15);
    }

    #[Test]
    public function an_oversized_page_size_is_clamped_to_the_maximum(): void
    {
        $this->getJson('/api/v1/services?per_page=1000000')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', PaginationParams::MAX_PER_PAGE);
    }

    #[Test]
    public function a_zero_or_negative_page_size_is_clamped_to_the_minimum(): void
    {
        $this->getJson('/api/v1/services?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', PaginationParams::MIN_PER_PAGE);

        $this->getJson('/api/v1/services?per_page=-20')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', PaginationParams::MIN_PER_PAGE);
    }

    #[Test]
    public function a_non_numeric_page_size_falls_back_to_the_default(): void
    {
        $this->getJson('/api/v1/services?per_page=all')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', PaginationParams::DEFAULT_PER_PAGE);
    }

    #[Test]
    public function a_page_beyond_the_end_returns_an_empty_collection_not_an_error(): void
    {
        $this->getJson('/api/v1/services?page=999')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.has_more', false);
    }

    #[Test]
    public function it_walks_pages_consistently(): void
    {
        $first = $this->getJson('/api/v1/services?per_page=2&page=1')->assertOk();
        $second = $this->getJson('/api/v1/services?per_page=2&page=2')->assertOk();

        $total = $first->json('meta.pagination.total');
        $this->assertIsInt($total);
        $this->assertGreaterThan(4, $total, 'The seeded catalog must be large enough to page through.');

        $first->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total_pages', (int) ceil($total / 2))
            ->assertJsonPath('meta.pagination.has_more', true);

        $this->assertNotSame(
            $first->json('data.0.id'),
            $second->json('data.0.id'),
            'Consecutive pages must not repeat the same record.'
        );
    }
}
