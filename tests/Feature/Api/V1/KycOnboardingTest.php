<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Application\KycCaseService;
use Modules\ResidentProfile\Contracts\KycStatus;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 06, as tests.
 *
 * The one this file exists for: **registration never silently creates a duplicate verified
 * resident.** Everything else here is a way that could stop being true.
 */
final class KycOnboardingTest extends KycTestCase
{
    use RefreshDatabase;

    // ── registration creates a case, never a resident ─────────────────────────────────

    #[Test]
    public function registering_creates_a_case_and_no_resident(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->postJson('/api/v1/me/kyc', $this->claims())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.resident_id', null);

        // The canonical registry is untouched. A resident exists only when a reviewer
        // says so.
        $this->assertSame(0, Resident::query()->count());
        $this->assertSame(1, KycCase::query()->count());
    }

    #[Test]
    public function registering_twice_returns_the_same_case(): void
    {
        Sanctum::actingAs($this->citizen());

        $first = $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated()->json('data.id');

        // A double tap, or a retry on a dropped connection, must not open two cases: two
        // reviewers would work them independently and the second approval is the duplicate.
        $this->assertSame($first, $second);
        $this->assertSame(1, KycCase::query()->count());
    }

    #[Test]
    public function submitting_never_reaches_approved(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();
        $this->postJson('/api/v1/me/kyc/submit')->assertOk()
            ->assertJsonPath('data.status', KycStatus::ManualReview->value);

        // Screening hands over to a person. There is no automatic path to approval, and
        // still no resident.
        $this->assertSame(0, Resident::query()->count());
    }

    #[Test]
    public function an_account_already_linked_to_a_resident_cannot_register_again(): void
    {
        $account = $this->citizen();
        $case = $this->approvedCaseFor($account);

        $this->assertNotNull($case->resolved_resident_id);

        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/kyc', $this->claims())->assertStatus(409);
        $this->assertSame(1, Resident::query()->count());
    }

    // ── ambiguity requires a human ────────────────────────────────────────────────────

    #[Test]
    public function an_exact_name_and_birth_date_match_is_surfaced_as_a_candidate(): void
    {
        $existing = $this->existingResident();

        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();
        $this->postJson('/api/v1/me/kyc/submit')->assertOk();

        $case = KycCase::query()->firstOrFail();

        $this->assertSame(1, $case->candidates()->count());
        $this->assertSame('exact', $case->candidates()->first()->confidence);
        $this->assertSame($existing->id, $case->candidates()->first()->resident_id);
    }

    #[Test]
    public function matching_ignores_case_accents_and_spacing(): void
    {
        $this->existingResident(['first_name' => 'Maria', 'last_name' => 'Peña']);

        Sanctum::actingAs($this->citizen());
        $this->postJson('/api/v1/me/kyc', $this->claims([
            'first_name' => '  maria ',
            'last_name' => 'PENA',
        ]))->assertCreated();
        $this->postJson('/api/v1/me/kyc/submit')->assertOk();

        // "Peña" and "Pena" are the same person typed by two different systems. Treating
        // them as different people is the commonest source of a duplicate registry entry.
        $this->assertSame(1, KycCase::query()->firstOrFail()->candidates()->count());
    }

    #[Test]
    public function a_case_with_an_undecided_candidate_cannot_be_approved(): void
    {
        $this->existingResident();
        $case = $this->submittedCase();

        Sanctum::actingAs($this->reviewer());

        // The reviewer must look at the possible duplicate before deciding anything.
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")
            ->assertStatus(409);

        $this->assertSame(1, Resident::query()->count());
    }

    #[Test]
    public function approving_after_marking_a_candidate_as_the_same_person_links_rather_than_duplicates(): void
    {
        $existing = $this->existingResident();
        $case = $this->submittedCase();
        $candidate = $case->candidates()->firstOrFail();

        Sanctum::actingAs($this->reviewer());

        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/candidates/{$candidate->uuid}", [
            'decision' => 'same-person',
        ])->assertOk();

        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve", [
            'link_resident_id' => $existing->uuid,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        // THE CENTRAL ASSERTION: still one resident, now verified and linked.
        $this->assertSame(1, Resident::query()->count());
        $this->assertTrue($existing->refresh()->isVerified());
    }

    #[Test]
    public function a_reviewer_cannot_create_a_new_resident_after_calling_a_candidate_the_same_person(): void
    {
        $this->existingResident();
        $case = $this->submittedCase();
        $candidate = $case->candidates()->firstOrFail();

        Sanctum::actingAs($this->reviewer());

        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/candidates/{$candidate->uuid}", [
            'decision' => 'same-person',
        ])->assertOk();

        // Saying "this is the same person" and then creating a new record is a
        // contradiction, and the expensive half of it is the duplicate.
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")->assertStatus(409);
        $this->assertSame(1, Resident::query()->count());
    }

    #[Test]
    public function a_reviewer_cannot_link_a_resident_they_never_confirmed(): void
    {
        $unrelated = $this->existingResident(['first_name' => 'Jose', 'last_name' => 'Rizal']);
        $case = $this->submittedCase();

        Sanctum::actingAs($this->reviewer());

        // Linking an arbitrary resident id would attach this account to somebody else's
        // record entirely.
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve", [
            'link_resident_id' => $unrelated->uuid,
        ])->assertStatus(409);
    }

    #[Test]
    public function approving_with_no_candidates_creates_exactly_one_verified_resident(): void
    {
        $case = $this->submittedCase();

        $this->assertSame(0, $case->candidates()->count());

        Sanctum::actingAs($this->reviewer());

        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, Resident::query()->count());
        $this->assertSame(VerificationTier::Verified, Resident::query()->firstOrFail()->verification_tier);
    }

    // ── lifecycle ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_invalid_transition_is_refused(): void
    {
        $account = $this->citizen();
        $case = app(KycCaseService::class)->register($account->uuid, $this->claims());

        // draft -> approved does not exist. Status is never assigned directly.
        $this->expectException(InvalidStateTransitionException::class);

        app(KycCaseService::class)->transition($case, KycStatus::Approved, null, $account->uuid);
    }

    #[Test]
    public function every_transition_is_recorded(): void
    {
        $case = $this->submittedCase();

        $recorded = DB::table('kyc_case_transitions')
            ->where('kyc_case_id', $case->id)
            ->orderBy('id')
            ->pluck('to_status')
            ->all();

        $this->assertSame(['draft', 'submitted', 'screening', 'manual-review'], $recorded);
    }

    #[Test]
    public function a_returned_case_can_be_corrected_and_resubmitted(): void
    {
        $case = $this->submittedCase();

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/request-information", [
            'message' => 'Please upload a clearer barangay certificate.',
        ])->assertOk();

        Sanctum::actingAs(Account::query()->where('uuid', $case->account_id)->firstOrFail());

        $this->getJson('/api/v1/me/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'needs-more-information')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.message', 'Please upload a clearer barangay certificate.');

        $this->postJson('/api/v1/me/kyc/submit')->assertOk()
            ->assertJsonPath('data.status', 'manual-review');
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_cannot_open_the_review_queue(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->getJson('/api/v1/admin/kyc-cases')->assertForbidden();
    }

    #[Test]
    public function a_citizen_cannot_read_another_persons_case(): void
    {
        $case = $this->submittedCase();

        Sanctum::actingAs($this->citizen(['mobile_number' => '+639170009999']));

        // Even with the identifier in hand: the admin route needs a permission, and the
        // /me route resolves from the token rather than a parameter.
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();
        $this->getJson('/api/v1/me/kyc')->assertNotFound();
    }

    #[Test]
    public function a_reviewer_without_approve_permission_cannot_approve(): void
    {
        $case = $this->submittedCase();

        // lgu_staff reviews duplicates but does not decide who becomes verified — the two
        // are deliberately different permissions.
        Sanctum::actingAs($this->reviewer('lgu_staff'));

        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertOk();
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")->assertForbidden();
        $this->assertSame(0, Resident::query()->count());
    }

    #[Test]
    public function the_applicant_projection_hides_candidates_and_internal_reasons(): void
    {
        $this->existingResident();
        $case = $this->submittedCase();

        Sanctum::actingAs(Account::query()->where('uuid', $case->account_id)->firstOrFail());

        $body = (string) $this->getJson('/api/v1/me/kyc')->assertOk()->getContent();

        // An applicant must never learn that their record resembles somebody else's — not
        // the candidate list, not the count, not the internal screening note.
        $this->assertStringNotContainsString('candidate', $body);
        $this->assertStringNotContainsString('Screening found', $body);
        $this->assertStringNotContainsString('undecided', $body);

        // Their own claims are theirs to see; nothing about the other resident is.
        $this->assertStringContainsString('Maria', $body);
        $this->assertStringNotContainsString('resident_match', $body);
    }

    // ── audit ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function decisions_are_audited_without_recording_the_claim(): void
    {
        $case = $this->submittedCase();

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")->assertOk();

        $summaries = DB::table('audit_entries')->pluck('summary')->implode(' ');

        $this->assertStringContainsString('KYC approved', $summaries);
        // The trail proves the decision without becoming a second copy of the record.
        $this->assertStringNotContainsString('Dela Cruz', $summaries);
        $this->assertStringNotContainsString('1990-01-15', $summaries);
    }
}
