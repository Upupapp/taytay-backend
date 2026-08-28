<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\ResidentProfile\Contracts\CorrectionStatus;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\AccountResidentLink;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentAlias;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionRequest;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentStatusEvent;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 08, as tests.
 *
 * The three this file exists for:
 *
 *  1. **One canonical resident** is used everywhere — approval links the account to it, and
 *     nothing else creates a second.
 *  2. **A citizen cannot mass-assign staff-only properties.** Verification tier and active
 *     state are outcomes of a review, and no citizen payload may reach them.
 *  3. **Nothing changes silently.** Every correction leaves history, and a changed name
 *     leaves an alias so search still finds the person.
 */
final class ResidentRegistryTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the account/resident link ─────────────────────────────────────────────────────

    #[Test]
    public function approving_kyc_links_the_account_to_the_canonical_resident(): void
    {
        $account = $this->citizen();
        $this->approvedCaseFor($account);

        $resident = Resident::query()->firstOrFail();

        // The gap this closes: before TAB 08 the case recorded `resolved_resident_id` and
        // the account's own link stayed null, so a citizen who had just been verified was
        // told no record was linked to them.
        $this->assertSame((string) $resident->uuid, (string) $account->refresh()->resident_id);

        // …and the link is reviewable, not just a column.
        $link = AccountResidentLink::query()->where('account_id', $account->uuid)->firstOrFail();
        $this->assertSame('kyc-approval', $link->origin);
        $this->assertSame('active', $link->status);
    }

    #[Test]
    public function a_citizen_reads_their_own_profile_and_only_their_own(): void
    {
        [$mine, $resident] = $this->activeCitizenWithResident();
        [$theirs] = $this->activeCitizenWithResident();

        Sanctum::actingAs($mine);
        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $resident->uuid);

        // There is no identifier anywhere in this endpoint's contract, so there is nothing
        // to tamper with — the other citizen simply gets their own record.
        Sanctum::actingAs($theirs);
        $other = $this->getJson('/api/v1/me/profile')->assertOk()->json('data.id');

        $this->assertNotSame((string) $resident->uuid, $other);
    }

    #[Test]
    public function the_citizen_profile_omits_staff_only_and_sensitive_fields(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $resident->forceFill([
            'monthly_income_centavos' => 1_500_000,
            'philsys_last_four' => '1234',
        ])->save();

        Sanctum::actingAs($account);

        $payload = $this->getJson('/api/v1/me/profile')->assertOk()->json('data');

        // Means-testing evidence, the government identifier fragment and the matching key
        // are absent by construction, not merely unrendered by the current client.
        $this->assertArrayNotHasKey('monthly_income_centavos', $payload);
        $this->assertArrayNotHasKey('philsys_last_four', $payload);
        $this->assertArrayNotHasKey('identity_fingerprint', $payload);
    }

    #[Test]
    public function a_resident_without_a_linked_record_is_told_so_rather_than_given_a_blank_one(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->getJson('/api/v1/me/profile')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ── corrections ───────────────────────────────────────────────────────────────────

    #[Test]
    public function a_self_service_correction_applies_immediately_and_is_recorded(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['street_address' => '99 Bagong Kalsada'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('99 Bagong Kalsada', $resident->refresh()->street_address);

        // Applied is not the same as untraceable.
        $this->assertDatabaseHas('resident_status_events', [
            'resident_id' => $resident->id,
            'field' => 'street_address',
            'new_value' => '99 Bagong Kalsada',
        ]);
    }

    #[Test]
    public function an_identity_correction_waits_for_a_reviewer(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $before = $resident->last_name;

        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['last_name' => 'Reyes'],
            'note' => 'Married name.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // The canonical record has NOT moved. This is the whole point of the second door:
        // a verified surname is exactly what a fraudulent claim would rewrite.
        $this->assertSame($before, $resident->refresh()->last_name);
    }

    #[Test]
    public function a_citizen_cannot_mass_assign_verification_tier_or_active_state(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $resident->forceFill(['verification_tier' => VerificationTier::Unverified])->save();

        Sanctum::actingAs($account);

        // Not merely ignored — refused. A silently dropped field teaches a client that the
        // request worked.
        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['verification_tier' => 'verified'],
        ])->assertStatus(422);

        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['is_active' => true, 'street_address' => '1 Real Street'],
        ])->assertStatus(422);

        $this->assertSame(VerificationTier::Unverified, $resident->refresh()->verification_tier);
    }

    #[Test]
    public function only_one_correction_request_may_be_open_at_a_time(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/profile/corrections', ['changes' => ['last_name' => 'Reyes']])
            ->assertCreated();

        // Two pending requests can be approved in either order, and the second applies
        // values computed against a record that has already moved.
        $this->postJson('/api/v1/me/profile/corrections', ['changes' => ['first_name' => 'Marya']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    #[Test]
    public function approving_a_correction_applies_it_records_history_and_preserves_the_old_name(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $originalFingerprint = $resident->identity_fingerprint;

        Sanctum::actingAs($account);
        $correction = $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['last_name' => 'Reyes'],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/approve", [
            'review_note' => 'Marriage certificate sighted.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $resident->refresh();
        $this->assertSame('Reyes', $resident->last_name);

        // The superseded name survives, so a clerk holding an older form still finds them.
        $this->assertDatabaseHas('resident_aliases', [
            'resident_id' => $resident->id,
            'last_name' => 'Dela Cruz',
            'source' => 'correction',
        ]);

        // …and the matching key follows the identity, or duplicate detection goes blind.
        $this->assertNotSame($originalFingerprint, $resident->identity_fingerprint);
        $this->assertSame(
            IdentityFingerprint::forName($resident->first_name, 'Reyes', $resident->birth_date->toDateString()),
            $resident->identity_fingerprint,
        );
    }

    #[Test]
    public function a_rejected_correction_leaves_the_record_alone_and_explains_itself(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);
        $correction = $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['birth_date' => '1991-02-02'],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->reviewer());

        // A refusal with no reason is one the resident cannot act on or appeal.
        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/reject")->assertStatus(422);

        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/reject", [
            'review_note' => 'Birth certificate does not support this.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('1990-01-15', $resident->refresh()->birth_date->toDateString());
    }

    #[Test]
    public function a_resolved_correction_cannot_be_decided_twice(): void
    {
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);
        $correction = $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['last_name' => 'Reyes'],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/approve")->assertOk();

        // A double-tapped approve would apply the change twice and write a second history
        // row claiming a previous value that is no longer true.
        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/approve")
            ->assertStatus(409);
    }

    // ── L-15: a client may send back the identifier it was given ─────────────────────

    /**
     * A resident is created by barangay **code**, which is what every response now names.
     *
     * Without this the read side is migrated and the write side pins the auto-increment key in
     * place: a client receives `barangay_code` and has to send `barangay_id` back, which is the
     * identifier Article 4 keeps out of payloads in the first place.
     */
    #[Test]
    public function a_resident_can_be_created_by_barangay_code(): void
    {
        Sanctum::actingAs($this->reviewer());

        $code = (string) DB::table('barangays')->where('id', $this->barangayId())->value('code');

        $payload = $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Coded',
            'last_name' => 'Barangay',
            'sex' => 'female',
            'birth_date' => '1988-03-04',
            'civil_status' => 'single',
            'barangay_code' => $code,
            'street_address' => '4 Mabini Street',
        ])->assertCreated()->json('data');

        $this->assertSame($this->barangayId(), Resident::query()->where('uuid', $payload['id'])->value('barangay_id'));
    }

    /**
     * **Neither identifier is refused, rather than defaulting to somewhere.**
     *
     * A barangay is a scope boundary as well as an address: a record filed in the wrong one lands
     * where its own office cannot see it.
     */
    #[Test]
    public function a_resident_cannot_be_created_with_no_barangay_at_all(): void
    {
        Sanctum::actingAs($this->reviewer());

        $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Nowhere',
            'last_name' => 'Person',
            'sex' => 'female',
            'birth_date' => '1988-03-04',
            'civil_status' => 'single',
            'street_address' => '4 Mabini Street',
        ])->assertStatus(422);
    }

    /** A code naming no barangay is refused, never resolved to something. */
    #[Test]
    public function an_unknown_barangay_code_is_refused(): void
    {
        Sanctum::actingAs($this->reviewer());

        $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Unknown',
            'last_name' => 'Barangay',
            'sex' => 'female',
            'birth_date' => '1988-03-04',
            'civil_status' => 'single',
            'barangay_code' => 'brgy-does-not-exist',
            'street_address' => '4 Mabini Street',
        ])->assertStatus(422);
    }

    // ── the staff registry ────────────────────────────────────────────────────────────

    #[Test]
    public function a_staff_created_resident_always_starts_unverified(): void
    {
        Sanctum::actingAs($this->reviewer());

        $payload = $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'sex' => 'male',
            'birth_date' => '1961-06-19',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '1 Calamba Street',
            // Sent deliberately. There is no route by which this reaches the record.
            'verification_tier' => 'verified',
        ])->assertCreated()->json('data');

        $this->assertSame('unverified', $payload['verification_tier']);
        $this->assertNull(Resident::query()->where('uuid', $payload['id'])->value('verified_at'));
    }

    #[Test]
    public function changing_verification_requires_its_own_permission_and_a_reason(): void
    {
        $resident = $this->existingResident(['verification_tier' => VerificationTier::Unverified]);

        // lgu_staff may manage residents but must not decide who is verified.
        Sanctum::actingAs($this->reviewer('lgu_staff'));
        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/verification", [
            'verification_tier' => 'verified',
            'reason' => 'Documents sighted.',
        ])->assertForbidden();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/verification", [
            'verification_tier' => 'verified',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/verification", [
            'verification_tier' => 'verified',
            'reason' => 'Birth certificate and barangay clearance sighted.',
        ])->assertOk()->assertJsonPath('data.verification_tier', 'verified');

        $this->assertDatabaseHas('resident_status_events', [
            'resident_id' => $resident->id,
            'event' => 'verification-changed',
            'new_value' => 'verified',
        ]);
    }

    #[Test]
    public function demoting_verification_clears_the_verified_timestamp(): void
    {
        $resident = $this->existingResident();
        $this->assertNotNull($resident->verified_at);

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/verification", [
            'verification_tier' => 'unverified',
            'reason' => 'Document later found to be forged.',
        ])->assertOk();

        // A `verified_at` left behind reads as "verified once" to every later screen.
        $this->assertNull($resident->refresh()->verified_at);
    }

    #[Test]
    public function search_finds_a_resident_under_a_name_they_no_longer_use(): void
    {
        $resident = $this->existingResident();

        ResidentAlias::query()->create([
            'resident_id' => $resident->id,
            'first_name' => 'Maria',
            'last_name' => 'Bautista',
            'source' => 'correction',
            'recorded_at' => now(),
        ]);

        Sanctum::actingAs($this->reviewer());

        // If search only matched the current name, the clerk would conclude this person is
        // not enrolled and create the duplicate the whole module exists to prevent.
        $this->getJson('/api/v1/admin/residents?q=Bautista')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $resident->uuid);
    }

    #[Test]
    public function a_resident_outside_the_callers_barangay_reads_as_not_found(): void
    {
        $other = $this->existingResident(['barangay_id' => $this->otherBarangayId()]);

        $clerk = $this->citizen(['account_type' => 'staff']);
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        // NOT FOUND, never FORBIDDEN: "exists but not yours" is enough to enumerate the
        // municipality one guessed id at a time (OWASP API1).
        $this->getJson("/api/v1/admin/residents/{$other->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/admin/residents')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function reading_a_resident_record_is_audited(): void
    {
        $resident = $this->existingResident();
        $reviewer = $this->reviewer();

        Sanctum::actingAs($reviewer);
        $this->getJson("/api/v1/admin/residents/{$resident->uuid}")->assertOk();

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'resident.viewed',
            'entity_id' => (string) $resident->uuid,
            'actor_subject_id' => (string) $reviewer->uuid,
        ]);
    }

    #[Test]
    public function an_unauthenticated_caller_reaches_nothing(): void
    {
        $resident = $this->existingResident();

        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
        $this->getJson('/api/v1/admin/residents')->assertUnauthorized();
        $this->getJson("/api/v1/admin/residents/{$resident->uuid}")->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_holds_no_staff_registry_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/residents')->assertForbidden();
        $this->getJson('/api/v1/admin/resident-corrections')->assertForbidden();
        $this->getJson('/api/v1/admin/resident-duplicates')->assertForbidden();
    }

    // ── account links ─────────────────────────────────────────────────────────────────

    #[Test]
    public function an_account_may_act_for_only_one_resident(): void
    {
        [$account, $first] = $this->activeCitizenWithResident();
        $second = $this->existingResident(['first_name' => 'Ana', 'last_name' => 'Lim']);

        // Bring the pre-linked account under the reviewable history the service maintains.
        AccountResidentLink::query()->create([
            'resident_id' => $first->id,
            'account_id' => $account->uuid,
            'origin' => 'staff-link',
            'status' => 'active',
            'linked_at' => now(),
        ]);

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // Silently repointing the column would hand one person another person's file.
        $this->postJson("/api/v1/admin/residents/{$second->uuid}/account-links", [
            'account_id' => (string) $account->uuid,
        ])->assertStatus(409);
    }

    #[Test]
    public function a_staff_account_cannot_be_linked_to_a_resident(): void
    {
        $resident = $this->existingResident();
        $employee = $this->reviewer('lgu_staff');

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        // An employee sign-in doubling as a resident identity destroys the audit trail's
        // ability to say who actually made a change.
        $this->postJson("/api/v1/admin/residents/{$resident->uuid}/account-links", [
            'account_id' => (string) $employee->uuid,
        ])->assertNotFound();
    }

    #[Test]
    public function revoking_a_link_keeps_the_history_and_detaches_the_account(): void
    {
        $account = $this->citizen();
        $this->approvedCaseFor($account);
        $resident = Resident::query()->firstOrFail();

        $link = AccountResidentLink::query()->where('account_id', $account->uuid)->firstOrFail();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->deleteJson("/api/v1/admin/residents/{$resident->uuid}/account-links/{$link->uuid}", [
            'reason' => 'Linked to the wrong record during onboarding.',
        ])->assertOk()->assertJsonPath('data.status', 'revoked');

        // The account survives — it is a way to authenticate, not a person — and it is no
        // longer attached.
        $this->assertNull($account->refresh()->resident_id);

        // "This account used to be able to act for that resident" is exactly the fact a
        // privacy complaint asks about, so the row is kept.
        $this->assertDatabaseHas('account_resident_links', [
            'uuid' => (string) $link->uuid,
            'status' => 'revoked',
        ]);
    }

    #[Test]
    public function history_is_append_only_and_survives_further_edits(): void
    {
        $resident = $this->existingResident();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->patchJson("/api/v1/admin/residents/{$resident->uuid}", [
            'street_address' => '5 Mabini Street',
            'reason' => 'Reported at the barangay hall.',
        ])->assertOk();

        $this->patchJson("/api/v1/admin/residents/{$resident->uuid}", [
            'street_address' => '7 Mabini Street',
            'reason' => 'Corrected house number.',
        ])->assertOk();

        $events = ResidentStatusEvent::query()
            ->where('resident_id', $resident->id)
            ->where('field', 'street_address')
            ->orderBy('id')
            ->get();

        // Both edits survive with their own before/after. An overwritten history is not
        // evidence.
        $this->assertCount(2, $events);
        $this->assertSame('12 Rizal Street', $events[0]->previous_value);
        $this->assertSame('5 Mabini Street', $events[0]->new_value);
        $this->assertSame('7 Mabini Street', $events[1]->new_value);
    }

    #[Test]
    public function an_edit_that_changes_nothing_writes_no_history(): void
    {
        $resident = $this->existingResident();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->patchJson("/api/v1/admin/residents/{$resident->uuid}", [
            'street_address' => $resident->street_address,
        ])->assertOk();

        // Noise buries the changes that matter.
        $this->assertSame(0, ResidentStatusEvent::query()
            ->where('resident_id', $resident->id)
            ->where('field', 'street_address')
            ->count());
    }

    #[Test]
    public function a_withdrawn_request_can_no_longer_be_approved(): void
    {
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);
        $correction = $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['last_name' => 'Reyes'],
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/me/profile/corrections/{$correction}")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/resident-corrections/{$correction}/approve")->assertStatus(409);

        $this->assertSame(
            CorrectionStatus::Withdrawn,
            ResidentCorrectionRequest::query()->where('uuid', $correction)->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_citizen_cannot_withdraw_another_residents_request(): void
    {
        [$mine] = $this->activeCitizenWithResident();
        [$theirs] = $this->activeCitizenWithResident();

        Sanctum::actingAs($mine);
        $correction = $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['last_name' => 'Reyes'],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($theirs);
        $this->deleteJson("/api/v1/me/profile/corrections/{$correction}")->assertNotFound();

        $this->assertSame(
            'pending',
            DB::table('resident_correction_requests')->where('uuid', $correction)->value('status'),
        );
    }
}
