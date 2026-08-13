<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceCatalog\Application\ListServicesQuery;
use Modules\ServiceCatalog\Http\Controllers\V1\ServiceCatalogController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Evidence for the TAB 01 acceptance criterion "the same domain services can support
 * citizen web, mobile and admin clients" (CLAUDE.md Article 3.1, ADR 0002).
 *
 * The point is not that the clients get identical data — they must not, since they are
 * authorized differently. The point is that there is exactly ONE implementation of the
 * behaviour, so an authorization fix applies to every channel at once and no per-client
 * copy can drift.
 */
final class SharedDomainServiceAcrossClientsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNELS = ['citizen-web', 'citizen-mobile', 'admin-console', 'verifier-device'];

    #[Test]
    public function the_citizen_and_admin_routes_resolve_to_the_same_controller_action(): void
    {
        $citizen = Route::getRoutes()->getByName('v1.services.index');
        $admin = Route::getRoutes()->getByName('v1.admin.services.index');

        $this->assertNotNull($citizen);
        $this->assertNotNull($admin);

        $this->assertSame(
            $citizen->getActionName(),
            $admin->getActionName(),
            'There must be no separate admin controller to drift from the citizen one.'
        );
        $this->assertSame(ServiceCatalogController::class.'@index', $citizen->getActionName());
    }

    #[Test]
    public function the_controller_delegates_to_the_single_application_service(): void
    {
        $constructor = (new ReflectionClass(ServiceCatalogController::class))->getConstructor();

        $this->assertNotNull($constructor);

        $dependencies = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $constructor->getParameters(),
        );

        // A controller may only validate shape, build a query, call the application
        // service and shape the response (CLAUDE.md Article 3.2). Anything else injected
        // here — a repository, a model, a policy — would be logic escaping the service.
        $this->assertSame([ListServicesQuery::class], $dependencies);
    }

    #[Test]
    public function every_channel_receives_an_identical_payload_for_the_same_actor(): void
    {
        $payloads = [];

        foreach (self::CHANNELS as $channel) {
            $payloads[$channel] = $this->getJson('/api/v1/services?per_page=100', [
                'X-Client-Channel' => $channel,
            ])->assertOk()->json('data');
        }

        foreach (self::CHANNELS as $channel) {
            $this->assertSame(
                $payloads['citizen-web'],
                $payloads[$channel],
                "Channel `{$channel}` received a different payload; response shape must be "
                    .'stable across channels (CLAUDE.md Article 3.6).'
            );
        }
    }

    #[Test]
    public function the_same_actor_gets_the_same_result_from_the_citizen_and_admin_urls(): void
    {
        $user = User::factory()->create();
        config(['access_control.assignments' => [(string) $user->getAuthIdentifier() => ['lgu_admin']]]);
        Sanctum::actingAs($user);

        $viaCitizenUrl = $this->getJson('/api/v1/services?per_page=100')->assertOk()->json('data');
        $viaAdminUrl = $this->getJson('/api/v1/admin/services?per_page=100')->assertOk()->json('data');

        $this->assertSame(
            $viaCitizenUrl,
            $viaAdminUrl,
            'Both URLs run the same query with the same actor, so they must agree exactly.'
        );
    }

    #[Test]
    public function staff_and_residents_differ_only_by_authorization_not_by_endpoint(): void
    {
        $resident = $this->getJson('/api/v1/services?per_page=100')->assertOk()->json('data');

        $staff = User::factory()->create();
        config(['access_control.assignments' => [(string) $staff->getAuthIdentifier() => ['lgu_staff']]]);
        Sanctum::actingAs($staff);

        $staffView = $this->getJson('/api/v1/services?per_page=100')->assertOk()->json('data');

        $this->assertIsArray($resident);
        $this->assertIsArray($staffView);

        // Same endpoint, same service, strictly wider result — the difference is the
        // permission, not a second code path.
        $this->assertGreaterThan(count($resident), count($staffView));
        $this->assertSame(
            array_column($resident, 'code'),
            array_slice(array_column($staffView, 'code'), 0, count($resident)),
        );
    }

    #[Test]
    public function the_response_shape_is_identical_for_residents_and_staff(): void
    {
        $residentEntry = $this->getJson('/api/v1/services')->assertOk()->json('data.0');

        $staff = User::factory()->create();
        config(['access_control.assignments' => [(string) $staff->getAuthIdentifier() => ['lgu_staff']]]);
        Sanctum::actingAs($staff);

        $staffEntry = $this->getJson('/api/v1/admin/services')->assertOk()->json('data.0');

        $this->assertIsArray($residentEntry);
        $this->assertIsArray($staffEntry);

        // Clients differ in WHAT they may see, never in envelope or field shape.
        $this->assertSame(array_keys($residentEntry), array_keys($staffEntry));
    }
}
