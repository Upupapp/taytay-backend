<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceCatalog;

use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Domain\Role;
use Modules\ServiceCatalog\Application\ListServicesCriteria;
use Modules\ServiceCatalog\Application\ListServicesQuery;
use Modules\ServiceCatalog\Domain\LguService;
use Modules\ServiceCatalog\Domain\PublicationStatus;
use Modules\ServiceCatalog\Domain\ServiceCatalogRepository;
use Modules\ServiceCatalog\Domain\ServiceCategory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\Pagination\PaginationParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The application service is tested directly, without HTTP, because it is the single
 * place the rule lives. If this passes, every channel gets the same behaviour by
 * construction (ADR 0002).
 */
final class ListServicesQueryTest extends TestCase
{
    #[Test]
    public function a_guest_sees_only_published_entries(): void
    {
        $page = $this->query()->handle(ActorContext::guest(), $this->criteria());

        $this->assertSame(['PUBLISHED_A', 'PUBLISHED_B'], $this->codes($page->items));
        $this->assertSame(2, $page->total);
    }

    #[Test]
    public function an_actor_without_the_permission_sees_only_published_entries(): void
    {
        $page = $this->query()->handle(ActorContext::authenticated('subject-1'), $this->criteria());

        $this->assertSame(['PUBLISHED_A', 'PUBLISHED_B'], $this->codes($page->items));
    }

    #[Test]
    public function an_actor_with_the_permission_also_sees_drafts_and_retired_entries(): void
    {
        $page = $this->query()->handle($this->staff(), $this->criteria());

        $this->assertSame(['PUBLISHED_A', 'DRAFT_C', 'PUBLISHED_B', 'RETIRED_D'], $this->codes($page->items));
        $this->assertSame(4, $page->total);
    }

    #[Test]
    public function the_channel_recorded_on_the_actor_does_not_widen_the_result(): void
    {
        foreach (ClientChannel::cases() as $channel) {
            $page = $this->query()->handle(
                ActorContext::authenticated('subject-1', [], [], $channel),
                $this->criteria(),
            );

            $this->assertSame(
                ['PUBLISHED_A', 'PUBLISHED_B'],
                $this->codes($page->items),
                "Channel `{$channel->value}` must not affect what is returned."
            );
        }
    }

    #[Test]
    public function it_filters_by_category_within_what_the_actor_may_see(): void
    {
        $page = $this->query()->handle(
            ActorContext::guest(),
            $this->criteria(category: ServiceCategory::Buwis),
        );

        $this->assertSame(['PUBLISHED_B'], $this->codes($page->items));
    }

    #[Test]
    public function category_filtering_cannot_reveal_an_unpublished_entry(): void
    {
        // A filter is a narrowing device, never a way around the permission check.
        $page = $this->query()->handle(
            ActorContext::guest(),
            $this->criteria(category: ServiceCategory::National),
        );

        $this->assertSame([], $page->items);
        $this->assertSame(0, $page->total);
    }

    #[Test]
    public function it_filters_by_the_channel_a_service_is_offered_on(): void
    {
        $page = $this->query()->handle(
            ActorContext::guest(),
            $this->criteria(channel: ClientChannel::CitizenMobile),
        );

        $this->assertSame(['PUBLISHED_A'], $this->codes($page->items));
    }

    #[Test]
    public function it_paginates_the_result(): void
    {
        $page = $this->query()->handle(
            $this->staff(),
            $this->criteria(pagination: new PaginationParams(2, 3)),
        );

        $this->assertSame(['RETIRED_D'], $this->codes($page->items));
        $this->assertSame(4, $page->total);
        $this->assertFalse($page->hasMore());
    }

    private function query(): ListServicesQuery
    {
        return new ListServicesQuery(new InMemoryServiceCatalogRepository, new AuthorizationService);
    }

    private function staff(): ActorContext
    {
        return ActorContext::authenticated(
            'staff-1',
            [Role::LguStaff->value],
            Role::permissionsFor([Role::LguStaff->value]),
        );
    }

    private function criteria(
        ?PaginationParams $pagination = null,
        ?ServiceCategory $category = null,
        ?ClientChannel $channel = null,
    ): ListServicesCriteria {
        return new ListServicesCriteria(
            $pagination ?? new PaginationParams(1, PaginationParams::DEFAULT_PER_PAGE),
            $category,
            $channel,
        );
    }

    /**
     * @param  list<LguService>  $services
     * @return list<string>
     */
    private function codes(array $services): array
    {
        return array_map(static fn (LguService $service): string => $service->code, $services);
    }
}

/**
 * A fixed catalog, so the test asserts the rule rather than the seed data in
 * config/service_catalog.php.
 */
final class InMemoryServiceCatalogRepository implements ServiceCatalogRepository
{
    /**
     * @return list<LguService>
     */
    public function all(): array
    {
        return [
            new LguService(
                'id-a', 'PUBLISHED_A', 'Published A', '', ServiceCategory::Dokumento,
                PublicationStatus::Published,
                [ClientChannel::CitizenWeb, ClientChannel::CitizenMobile],
            ),
            new LguService(
                'id-c', 'DRAFT_C', 'Draft C', '', ServiceCategory::National,
                PublicationStatus::Draft,
                [ClientChannel::AdminConsole],
            ),
            new LguService(
                'id-b', 'PUBLISHED_B', 'Published B', '', ServiceCategory::Buwis,
                PublicationStatus::Published,
                [ClientChannel::CitizenWeb],
            ),
            new LguService(
                'id-d', 'RETIRED_D', 'Retired D', '', ServiceCategory::Trabaho,
                PublicationStatus::Retired,
                [ClientChannel::CitizenWeb],
            ),
        ];
    }
}
