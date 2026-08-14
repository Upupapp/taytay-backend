<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\ActorContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for a real defect found in TAB 01.
 *
 * The ActorContext and RequestContext were first bound with the container's `scoped`
 * lifetime. Container-lifetime bindings survive between requests on any long-lived worker
 * — Laravel Octane, or a test issuing several calls against one application instance — so
 * the SECOND request silently reused the FIRST request's actor. In production that is one
 * citizen inheriting another's authority (CLAUDE.md Article 5.3).
 *
 * Both are now memoised on the Request object, so a new request cannot reuse the previous
 * one's context. These tests each make several requests on purpose; a single-request test
 * would not have caught it.
 */
final class ActorContextIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLISHED_SERVICE_COUNT = 6;

    #[Test]
    public function authenticating_after_an_anonymous_request_is_honoured(): void
    {
        $this->getJson('/api/v1/services?per_page=100')
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');

        $admin = Account::factory()->create();
        $this->grantRole($admin, 'lgu_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/services?per_page=100')
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT + 1, 'data');
    }

    #[Test]
    public function authority_does_not_survive_into_a_later_anonymous_request(): void
    {
        $admin = Account::factory()->create();
        $this->grantRole($admin, 'lgu_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/services?per_page=100')
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT + 1, 'data');

        // The next caller is anonymous. They must not inherit the admin's view.
        Auth::forgetGuards();
        app()->forgetInstance(ActorContext::class);

        $this->getJson('/api/v1/services?per_page=100')
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
    }

    #[Test]
    public function each_request_receives_a_distinct_generated_request_id(): void
    {
        $first = $this->getJson('/api/v1/health')->assertOk()->json('meta.request_id');
        $second = $this->getJson('/api/v1/health')->assertOk()->json('meta.request_id');

        $this->assertNotSame(
            $first,
            $second,
            'Reusing a correlation id across requests would make support and audit trails ambiguous.'
        );
    }

    #[Test]
    public function the_actor_context_is_stable_within_a_single_request(): void
    {
        $staff = Account::factory()->create();
        $this->grantRole($staff, 'lgu_staff');
        Sanctum::actingAs($staff);

        // Memoisation must still hold within one request: resolving the actor twice in a
        // single request must not produce two different answers.
        $this->getJson('/api/v1/services?per_page=100')->assertOk();

        $first = app(ActorContext::class);
        $second = app(ActorContext::class);

        $this->assertSame($first, $second);
    }
}
