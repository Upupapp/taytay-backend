<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Contracts\FileClassification;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * F28 — a KYC case had nowhere to put an identity document.
 *
 * ---
 *
 * A claim could be opened and submitted, and the applicant could not send the document that
 * settles a case the registry match does not. The only upload route in the whole contract
 * belonged to a `Welfare` assistance case, where filing an ID would attach it to an application
 * the resident never made.
 *
 * **No `kyc_case_documents` table was added.** `Files` already owns slots, versioning,
 * supersession, scan status and retention; a second document store would be a second answer to
 * "what did this person show us".
 */
final class KycDocumentTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('object-storage');
        config()->set('files.disk', 'object-storage');
    }

    #[Test]
    public function an_applicant_attaches_a_document_to_their_own_case(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();

        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'identity-document')
            ->assertJsonPath('data.attached', true);

        // Filed under the case, in the module that owns documents.
        $this->assertSame(
            'kyc-case',
            DB::table('documents')->where('document_type', 'identity-document')->value('owner_type'),
        );
    }

    #[Test]
    public function the_case_reports_what_is_attached_and_what_is_not(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();

        $before = $this->getJson('/api/v1/me/kyc')->assertOk()->json('data.documents');

        // Both types listed, neither attached. An applicant seeing an empty list cannot tell
        // "nothing sent" from "this app does not do that", and "nothing sent" is the state most
        // easily misread as sent.
        $this->assertCount(2, $before);
        $this->assertSame([false, false], array_column($before, 'attached'));

        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])->assertCreated();

        $after = collect($this->getJson('/api/v1/me/kyc')->assertOk()->json('data.documents'))
            ->keyBy('type');

        $this->assertTrue($after['identity-document']['attached']);
        $this->assertFalse($after['proof-of-address']['attached']);
    }

    #[Test]
    public function an_identity_document_is_classified_by_the_server(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();

        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            // Ignored: there is no classification parameter in this contract, and a client that
            // could set one could under-classify its own resident's ID.
            'classification' => 'public-reference',
            'type' => 'identity-document',
        ])->assertCreated();

        $this->assertSame(
            FileClassification::Personal->value,
            DB::table('stored_files')->value('classification'),
        );
    }

    #[Test]
    public function nothing_can_be_attached_once_the_case_is_with_the_office(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();
        $this->postJson('/api/v1/me/kyc/submit')->assertOk();

        // A document arriving after submission changes what a reviewer already looked at, without
        // the reviewer knowing.
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('late.jpg'),
            'type' => 'identity-document',
        ])->assertStatus(409);
    }

    #[Test]
    public function the_office_asking_for_more_reopens_the_door(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $case = $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/me/kyc/submit')->assertOk();

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/kyc-cases/{$case}/request-information", [
            'message' => 'Please send a clearer photo of your ID.',
        ])->assertOk();

        // `needs-more-information` is the one non-terminal state with a resident action in it, and
        // that action is usually exactly this.
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('clearer.jpg'),
            'type' => 'identity-document',
        ])->assertCreated();
    }

    #[Test]
    public function there_is_no_selfie_and_no_biometric_slot(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();

        // A facial image is Sensitive under this system's own vocabulary, is not revocable the
        // way a password is, and a released mobile build cannot be trusted to grade its own
        // verification. Adding a type here is not a small change.
        foreach (['selfie', 'liveness', 'face', 'biometric', 'photo'] as $forbidden) {
            $this->postJson('/api/v1/me/kyc/documents', [
                'file' => UploadedFile::fake()->image('face.jpg'),
                'type' => $forbidden,
            ])->assertStatus(422);
        }

        $this->assertSame(0, DB::table('stored_files')->count());
    }

    // ── it has to reach a reviewer ────────────────────────────────────────────────────

    #[Test]
    public function the_reviewer_deciding_the_case_can_see_and_open_it(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $case = $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])->assertCreated();
        $this->postJson('/api/v1/me/kyc/submit')->assertOk();

        Sanctum::actingAs($this->reviewer());

        /*
         * Without this the applicant's documents are a write nobody reads, which is worse than
         * refusing them: the resident believes the office holds their ID.
         */
        $documents = collect($this->getJson("/api/v1/admin/kyc-cases/{$case}")->assertOk()->json('data.documents'))
            ->keyBy('type');
        $this->assertTrue($documents['identity-document']['attached']);

        $this->postJson("/api/v1/admin/kyc-cases/{$case}/documents/identity-document/access")
            ->assertOk()
            ->assertJsonStructure(['data' => ['handle', 'expires_at']]);
    }

    #[Test]
    public function a_reviewers_note_on_a_document_is_never_shown_to_the_applicant(): void
    {
        $account = $this->citizen();
        Sanctum::actingAs($account);
        $case = $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])->assertCreated();

        Sanctum::actingAs($this->reviewer());
        $reviewerView = $this->getJson("/api/v1/admin/kyc-cases/{$case}")->assertOk()->json('data.documents.0');

        // The reviewer sees their own and their colleague's working.
        $this->assertArrayHasKey('verification_status', $reviewerView);
        $this->assertArrayHasKey('verification_note', $reviewerView);
        $this->assertArrayHasKey('scan_status', $reviewerView);

        Sanctum::actingAs($account);
        $applicantView = $this->getJson('/api/v1/me/kyc')->assertOk()->json('data.documents.0');

        // The applicant sees the decision on their case, not the deliberation that led to it.
        foreach (['verification_status', 'verification_note', 'scan_status', 'version'] as $internal) {
            $this->assertArrayNotHasKey($internal, $applicantView, $internal);
        }
    }

    // ── only your own ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_document_cannot_be_opened_from_somebody_elses_case(): void
    {
        $owner = $this->citizen();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/me/kyc', $this->claims())->assertCreated();
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])->assertCreated();

        // A second citizen with a case of their own and nothing attached to it. The slot is
        // derived from the caller's own case uuid, so there is no identifier in the request that
        // could reach the first applicant's file.
        $other = $this->citizen();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/me/kyc', $this->claims([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'birth_date' => '1985-06-19',
            'sex' => 'male',
        ]))->assertCreated();

        $this->postJson('/api/v1/me/kyc/documents/identity-document/access')->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_attach_anything(): void
    {
        $this->postJson('/api/v1/me/kyc/documents', [
            'file' => UploadedFile::fake()->image('philid.jpg'),
            'type' => 'identity-document',
        ])->assertUnauthorized();
    }
}
