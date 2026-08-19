<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The core security guarantee of TAB 01, named in CLAUDE.md Article 3 and ADR 0002:
 *
 *   1. the client channel is telemetry, never authority; and
 *   2. no business-critical authorization is delegated to frontend UI state.
 *
 * Every assertion below is an attempt to obtain elevated data by lying to the server the
 * way a decompiled mobile build or an intercepting proxy would. All of them must fail.
 *
 * The draft catalog entry is the canary: it is visible only to an actor holding the
 * server-resolved `services.view-unpublished` permission.
 */
final class ClientChannelIsNotAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT_SERVICE_CODE = 'NATIONAL_ID_ASSISTANCE';

    private const PUBLISHED_SERVICE_COUNT = 6;

    #[Test]
    public function claiming_the_admin_console_channel_grants_nothing(): void
    {
        $response = $this->getJson('/api/v1/services', ['X-Client-Channel' => 'admin-console']);

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
        $this->assertDraftIsHidden($response->json('data'));
    }

    #[Test]
    public function spoofed_role_and_permission_headers_grant_nothing(): void
    {
        $response = $this->getJson('/api/v1/services', [
            'X-Client-Channel' => 'admin-console',
            'X-Client-Role' => 'lgu_admin',
            'X-User-Role' => 'lgu_admin',
            'X-Permissions' => 'services.view-unpublished,services.manage',
            'X-Is-Admin' => 'true',
        ]);

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
        $this->assertDraftIsHidden($response->json('data'));
    }

    #[Test]
    public function spoofed_query_parameters_grant_nothing(): void
    {
        $response = $this->getJson(
            '/api/v1/services?is_admin=1&role=lgu_admin&permissions[]=services.view-unpublished&status=draft'
        );

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
        $this->assertDraftIsHidden($response->json('data'));
    }

    #[Test]
    public function an_unknown_channel_is_treated_as_unknown_and_still_works(): void
    {
        // A garbled header must neither elevate nor break a citizen's request.
        $this->getJson('/api/v1/services', ['X-Client-Channel' => 'superuser-console'])
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
    }

    #[Test]
    public function the_admin_url_prefix_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/services')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function an_authenticated_resident_gains_nothing_from_the_admin_url(): void
    {
        // The account exists and is authenticated, but holds no role assignment — which
        // is the default for every citizen account (deny by default).
        Sanctum::actingAs(Account::factory()->create());

        $response = $this->getJson('/api/v1/admin/services', ['X-Client-Channel' => 'admin-console']);

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
        $this->assertDraftIsHidden(
            $response->json('data'),
            'The /admin prefix is routing convenience; it must confer no authority.'
        );
    }

    #[Test]
    public function an_lgu_admin_sees_unpublished_entries_even_on_the_citizen_url(): void
    {
        $this->actAsLguAdmin();

        // The mirror image of the previous test: authority follows the actor, not the URL
        // and not the channel. This is why there is no citizen/admin service pair to drift.
        $response = $this->getJson('/api/v1/services', ['X-Client-Channel' => 'citizen-mobile']);

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT + 1, 'data');
        $this->assertContains(self::DRAFT_SERVICE_CODE, array_column((array) $response->json('data'), 'code'));
    }

    #[Test]
    public function an_lgu_admin_who_claims_to_be_a_citizen_still_sees_unpublished_entries(): void
    {
        $this->actAsLguAdmin();

        // Downgrading the channel header must not change authority either — the channel
        // is not a permission dial in any direction.
        $this->getJson('/api/v1/services', ['X-Client-Channel' => 'citizen-web'])
            ->assertOk()
            ->assertJsonCount(self::PUBLISHED_SERVICE_COUNT + 1, 'data');
    }

    #[Test]
    public function an_unknown_role_in_configuration_is_ignored(): void
    {
        $user = Account::factory()->create();

        /*
         * Written directly, because `grantRole` now refuses a role the catalog does not contain.
         *
         * That guard exists because six tests granted job titles this system has no role for and
         * every one of them passed for the wrong reason — the account had no permissions, so the
         * refusal they asserted was guaranteed. **This** test's whole subject is what the system
         * does when an unknown role reaches the database, so it has to be able to put one there.
         */
        foreach (['super_admin', 'root'] as $invented) {
            DB::table('role_assignments')->insert([
                'uuid' => (string) Str::uuid7(),
                'subject_id' => $user->uuid,
                'role' => $invented,
                'scope_type' => 'all-barangays',
                'barangay_id' => null,
                'valid_from' => now()->subMinute(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Sanctum::actingAs($user);

        // A role outside the catalog is dropped rather than passed through, so a typo in
        // configuration cannot silently widen access.
        $response = $this->getJson('/api/v1/admin/services');

        $response->assertOk()->assertJsonCount(self::PUBLISHED_SERVICE_COUNT, 'data');
        $this->assertDraftIsHidden($response->json('data'));
    }

    private function actAsLguAdmin(): Account
    {
        $user = Account::factory()->create();

        $this->grantRole($user, 'lgu_admin');

        Sanctum::actingAs($user);

        return $user;
    }

    private function assertDraftIsHidden(mixed $data, string $message = 'An unpublished entry leaked to an unauthorized actor.'): void
    {
        $this->assertIsArray($data);
        $this->assertNotContains(self::DRAFT_SERVICE_CODE, array_column($data, 'code'), $message);
    }
}
