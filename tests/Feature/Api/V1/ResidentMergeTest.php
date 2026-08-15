<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\ResidentProfile\Application\ResidentMergeService;
use Modules\ResidentProfile\Infrastructure\Eloquent\AccountResidentLink;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentDuplicatePair;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentSector;
use PHPUnit\Framework\Attributes\Test;

/**
 * Duplicate review and merge — the destructive half of TAB 08.
 *
 * The acceptance criterion this file exists for: **merge preserves relationship and history
 * integrity.** A merge that drops a credential, strands an account or loses the absorbed
 * person's name has not repaired a duplicate; it has manufactured a different, quieter
 * failure that nobody notices until somebody is refused assistance.
 *
 * Every test here also stands for the rule that nothing merges without a human having said
 * "same person" about that exact pair.
 */
final class ResidentMergeTest extends KycTestCase
{
    use RefreshDatabase;

    // ── detection ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function detection_finds_records_that_share_an_identity_fingerprint(): void
    {
        $this->existingResident();
        $this->existingResident(['street_address' => '48 Bonifacio Street']);

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson('/api/v1/admin/resident-duplicates/detect')
            ->assertOk()
            ->assertJsonPath('data.pairs_found', 1)
            ->assertJsonPath('data.undecided', 1);
    }

    #[Test]
    public function detection_is_idempotent_and_preserves_a_decision_already_made(): void
    {
        $this->existingResident();
        $this->existingResident(['street_address' => '48 Bonifacio Street']);

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson('/api/v1/admin/resident-duplicates/detect')->assertOk();
        $pair = ResidentDuplicatePair::query()->firstOrFail();

        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/decide", [
            'decision' => 'different-person',
            'note' => 'Twin sisters; different middle names on the birth certificates.',
        ])->assertOk();

        // Re-running must not resurrect a settled question in front of the next reviewer.
        $this->postJson('/api/v1/admin/resident-duplicates/detect')->assertOk();

        $this->assertSame(1, ResidentDuplicatePair::query()->count());
        $this->assertSame('different-person', $pair->refresh()->decision);
    }

    #[Test]
    public function a_pair_is_stored_once_regardless_of_the_order_it_is_discovered_in(): void
    {
        $a = $this->existingResident();
        $b = $this->existingResident(['street_address' => '48 Bonifacio Street']);

        $service = app(ResidentMergeService::class);
        $service->recordPair($a, $b, 'name-and-birth-date', 'exact');
        $service->recordPair($b, $a, 'name-and-birth-date', 'exact');

        // Two rows for one question is how two reviewers reach opposite conclusions and
        // each believes they were the only one looking.
        $this->assertSame(1, ResidentDuplicatePair::query()->count());
    }

    // ── the merge gate ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_merge_is_refused_until_a_reviewer_confirms_the_pair(): void
    {
        [$pair, $survivor] = $this->undecidedPair();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Same person.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertSame(0, DB::table('resident_merges')->count());
    }

    #[Test]
    public function deciding_same_person_does_not_itself_merge_anything(): void
    {
        [$pair] = $this->undecidedPair();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/decide", [
            'decision' => 'same-person',
        ])->assertOk();

        // Deciding that two rows describe one person and choosing which row survives are
        // separate judgements. Only the second one is irreversible.
        $this->assertSame(0, DB::table('resident_merges')->count());
        $this->assertSame(2, Resident::query()->count());
    }

    #[Test]
    public function the_survivor_must_be_one_of_the_two_records_in_the_pair(): void
    {
        [$pair] = $this->confirmedPair();
        $unrelated = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim', 'birth_date' => '1975-03-03']);

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // Otherwise the review is decorative: confirm a harmless pair, then merge two
        // completely different people.
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $unrelated->uuid,
            'reason' => 'Attempted redirection.',
        ])->assertStatus(400);

        $this->assertSame(0, DB::table('resident_merges')->count());
    }

    #[Test]
    public function a_merge_always_records_a_reason(): void
    {
        [$pair, $survivor] = $this->confirmedPair();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // A merge with no recorded reason is indistinguishable after the fact from an
        // unauthorised one.
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
        ])->assertStatus(422);
    }

    #[Test]
    public function merging_requires_the_merge_permission_not_merely_resident_management(): void
    {
        [$pair, $survivor] = $this->confirmedPair();

        // lgu_staff may create and correct residents but must never collapse two people.
        Sanctum::actingAs($this->reviewer('lgu_staff'));

        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Same person.',
        ])->assertForbidden();
    }

    // ── preview ───────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_preview_names_conflicting_fields_without_changing_anything(): void
    {
        [$pair, $survivor, $absorbed] = $this->confirmedPair();

        $absorbed->forceFill(['civil_status' => 'married', 'street_address' => '48 Bonifacio Street'])->save();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $payload = $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/preview", [
            'survivor_resident_id' => (string) $survivor->uuid,
        ])->assertOk()->json('data');

        $this->assertContains('civil_status', $payload['conflicts']);
        $this->assertContains('street_address', $payload['conflicts']);
        $this->assertTrue($payload['fields']['civil_status']['differs']);

        // A preview that mutates is not a preview.
        $this->assertSame(2, Resident::query()->count());
        $this->assertSame(0, DB::table('resident_merges')->count());
    }

    // ── the merge itself ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_merge_moves_accounts_kyc_cases_and_sectors_and_records_the_counts(): void
    {
        $account = $this->citizen();
        $this->approvedCaseFor($account);

        /** @var Resident $absorbed */
        $absorbed = Resident::query()->firstOrFail();

        // A second canonical row for the same human being — the duplicate this repairs.
        $survivor = $this->existingResident(['street_address' => '48 Bonifacio Street']);

        ResidentSector::query()->create(['resident_id' => $absorbed->id, 'sector' => 'solo-parent']);
        ResidentSector::query()->create(['resident_id' => $absorbed->id, 'sector' => 'senior-citizen']);
        ResidentSector::query()->create(['resident_id' => $survivor->id, 'sector' => 'senior-citizen']);

        $pair = app(ResidentMergeService::class)->recordPair($survivor, $absorbed, 'name-and-birth-date', 'exact');

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/decide", ['decision' => 'same-person'])
            ->assertOk();

        $payload = $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Enrolled twice — walk-in and online.',
        ])->assertOk()->json('data');

        $this->assertSame(1, $payload['reassigned']['accounts']);
        $this->assertSame(1, $payload['reassigned']['kyc_cases']);

        // The account follows the person. Left behind, they would sign in to find their
        // own record gone.
        $this->assertSame((string) $survivor->uuid, (string) $account->refresh()->resident_id);
        $this->assertDatabaseHas('account_resident_links', [
            'account_id' => (string) $account->uuid,
            'resident_id' => $survivor->id,
            'status' => 'active',
        ]);

        // `senior-citizen` existed on both; moving it blindly would violate the
        // (resident_id, sector) unique key, and leaving it behind would lose it from every
        // sectoral count.
        $sectors = ResidentSector::query()->where('resident_id', $survivor->id)->pluck('sector')->sort()->values()->all();
        $this->assertSame(['senior-citizen', 'solo-parent'], $sectors);
        $this->assertSame(0, ResidentSector::query()->where('resident_id', $absorbed->id)->count());
    }

    #[Test]
    public function a_merge_preserves_the_absorbed_name_as_an_alias_so_search_still_finds_them(): void
    {
        [$pair, $survivor, $absorbed] = $this->confirmedPair();

        $absorbed->forceFill(['last_name' => 'Delacruz'])->save();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Same person, surname spelled two ways.',
        ])->assertOk();

        $this->assertDatabaseHas('resident_aliases', [
            'resident_id' => $survivor->id,
            'last_name' => 'Delacruz',
            'source' => 'merge',
        ]);

        // …and that alias is reachable through search, which is the point of keeping it.
        $this->getJson('/api/v1/admin/residents?q=Delacruz')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $survivor->uuid);
    }

    #[Test]
    public function the_absorbed_record_is_soft_deleted_never_destroyed(): void
    {
        [$pair, $survivor, $absorbed] = $this->confirmedPair();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Duplicate enrolment.',
        ])->assertOk();

        // A merge decided in error has to stay provable. A hard delete makes the mistake
        // unrecoverable *and* unprovable.
        $this->assertSame(1, Resident::query()->count());
        $this->assertSame(2, Resident::withTrashed()->count());

        $absorbed = Resident::withTrashed()->findOrFail($absorbed->id);
        $this->assertNotNull($absorbed->deleted_at);
        $this->assertFalse((bool) $absorbed->is_active);

        // Both sides carry the event, so the history reads correctly from either record.
        $this->assertDatabaseHas('resident_status_events', [
            'resident_id' => $survivor->id,
            'event' => 'absorbed-record',
        ]);
        $this->assertDatabaseHas('resident_status_events', [
            'resident_id' => $absorbed->id,
            'event' => 'merged-into',
        ]);
    }

    #[Test]
    public function the_same_pair_cannot_be_merged_twice(): void
    {
        [$pair, $survivor] = $this->confirmedPair();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Duplicate enrolment.',
        ])->assertOk();

        // A retry — a double tap, a dropped connection — must not produce a second merge
        // record against an already-absorbed row.
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/merge", [
            'survivor_resident_id' => (string) $survivor->uuid,
            'reason' => 'Duplicate enrolment.',
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('resident_merges')->count());
    }

    #[Test]
    public function a_merge_spanning_a_barangay_the_caller_cannot_reach_is_refused(): void
    {
        $mine = $this->existingResident();
        $theirs = $this->existingResident([
            'street_address' => '48 Bonifacio Street',
            'barangay_id' => $this->otherBarangayId(),
        ]);

        $pair = app(ResidentMergeService::class)->recordPair($mine, $theirs, 'name-and-birth-date', 'exact');

        $clerk = $this->citizen(['account_type' => 'staff']);
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        // Otherwise a barangay-scoped clerk could move a resident beyond their own reach,
        // or rewrite a record in a barangay they were never granted.
        $this->postJson("/api/v1/admin/resident-duplicates/{$pair->uuid}/decide", [
            'decision' => 'same-person',
        ])->assertNotFound();
    }

    #[Test]
    public function an_out_of_scope_pair_is_omitted_from_the_queue(): void
    {
        $a = $this->existingResident(['barangay_id' => $this->otherBarangayId()]);
        $b = $this->existingResident([
            'street_address' => '48 Bonifacio Street',
            'barangay_id' => $this->otherBarangayId(),
        ]);

        app(ResidentMergeService::class)->recordPair($a, $b, 'name-and-birth-date', 'exact');

        $clerk = $this->citizen(['account_type' => 'staff']);
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        $this->getJson('/api/v1/admin/resident-duplicates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * Two colliding residents with a pair recorded and nobody having ruled on it.
     *
     * @return array{ResidentDuplicatePair, Resident, Resident}
     */
    private function undecidedPair(): array
    {
        $survivor = $this->existingResident();
        $absorbed = $this->existingResident(['street_address' => '48 Bonifacio Street']);

        $pair = app(ResidentMergeService::class)->recordPair($survivor, $absorbed, 'name-and-birth-date', 'exact');

        return [$pair, $survivor, $absorbed];
    }

    /**
     * The same, with a reviewer having confirmed they are one person.
     *
     * @return array{ResidentDuplicatePair, Resident, Resident}
     */
    private function confirmedPair(): array
    {
        [$pair, $survivor, $absorbed] = $this->undecidedPair();

        $pair->forceFill([
            'decision' => 'same-person',
            'decided_at' => now(),
        ])->save();

        return [$pair->refresh(), $survivor, $absorbed];
    }

    /**
     * Guards the assumption every merge test rests on: an active link exists to be moved.
     */
    private function assertHasActiveLink(Resident $resident): void
    {
        $this->assertTrue(
            AccountResidentLink::query()
                ->where('resident_id', $resident->id)
                ->where('status', 'active')
                ->exists(),
        );
    }
}
