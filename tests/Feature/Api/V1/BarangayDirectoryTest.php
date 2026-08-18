<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * The barangay directory, and the reason it exists.
 *
 * `POST me/kyc` requires a barangay and nothing published the list, so an
 * applicant was asked for an identifier they had no way to obtain. That made
 * this endpoint — and with it the Verified tier, the digital ID and every
 * service resting on them — unreachable from any client. The test that matters
 * most here is the last one: a resident can complete registration using only
 * what the directory gave them.
 */
final class BarangayDirectoryTest extends KycTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_directory_is_readable_without_an_account(): void
    {
        $this->barangayId();

        // An affirmative choice, not an oversight: the first thing onboarding
        // asks for is an address, so a list only account-holders can read would
        // not solve the problem it exists for.
        $this->getJson('/api/v1/barangays')
            ->assertOk()
            ->assertJsonPath('data.0.code', fn (mixed $code): bool => is_string($code));
    }

    #[Test]
    public function it_publishes_uuids_and_codes_but_never_the_primary_key(): void
    {
        $this->barangayId();
        $this->otherBarangayId();

        $response = $this->getJson('/api/v1/barangays')->assertOk();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            // Article 4: identifiers exposed to clients are UUIDs, and
            // auto-increment keys never appear in a payload. L-15 records what
            // it cost when `barangay_id` leaked as a raw integer elsewhere.
            $this->assertIsString($row['id']);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $row['id'],
            );
            $this->assertIsString($row['code']);
            $this->assertIsString($row['name']);
            $this->assertArrayNotHasKey('barangay_id', $row);
        }

        $keys = DB::table('barangays')->pluck('id')->map(static fn ($id): string => (string) $id);
        foreach ($rows as $row) {
            $this->assertNotContains($row['id'], $keys->all());
        }
    }

    #[Test]
    public function it_is_paginated_like_every_other_collection(): void
    {
        $this->barangayId();

        $this->getJson('/api/v1/barangays')
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['pagination' => ['page', 'per_page', 'total', 'total_pages', 'has_more']],
            ]);
    }

    #[Test]
    public function a_resident_can_register_using_only_what_the_directory_gave_them(): void
    {
        // The whole point. Before this, the only way to satisfy `me/kyc` was to
        // know an auto-increment key no endpoint published.
        $this->barangayId();
        Sanctum::actingAs($this->citizen());

        $code = $this->getJson('/api/v1/barangays')->assertOk()->json('data.0.code');

        $claims = $this->claims();
        unset($claims['barangay_id']);
        $claims['barangay_code'] = $code;

        $this->postJson('/api/v1/me/kyc', $claims)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        // The code was translated to the internal key at the adapter and never
        // reached the domain, so there is one identifier stored rather than two
        // that could disagree.
        $expected = DB::table('barangays')->where('code', $code)->value('id');
        $this->assertSame(
            (int) $expected,
            (int) DB::table('kyc_cases')->value('claimed_barangay_id'),
        );
    }

    #[Test]
    public function the_integer_key_still_works_for_the_console(): void
    {
        // Expand now, contract when the admin console moves (Article 6). Removing
        // it in the same change would break a client that is not this one.
        Sanctum::actingAs($this->citizen());

        $this->postJson('/api/v1/me/kyc', $this->claims())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    #[Test]
    public function registration_still_requires_a_barangay_by_one_name_or_the_other(): void
    {
        Sanctum::actingAs($this->citizen());

        $claims = $this->claims();
        unset($claims['barangay_id']);

        $this->postJson('/api/v1/me/kyc', $claims)
            ->assertStatus(422);
    }

    #[Test]
    public function an_unknown_code_is_refused_rather_than_resolved_to_nothing(): void
    {
        Sanctum::actingAs($this->citizen());

        $claims = $this->claims();
        unset($claims['barangay_id']);
        $claims['barangay_code'] = 'brgy-does-not-exist';

        $this->postJson('/api/v1/me/kyc', $claims)
            ->assertStatus(422);
    }
}
