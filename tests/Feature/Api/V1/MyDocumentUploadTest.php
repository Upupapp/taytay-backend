<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\VerificationStatus;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use PHPUnit\Framework\Attributes\Test;

/**
 * An applicant supplying documents for their own case (ADR 0020 §8).
 *
 * THE CRITERION: **a citizen may upload only into their own permitted requirement slots.**
 *
 * Held by the lookup rather than by a check afterwards — resident from the token, case from that
 * resident, requirement from that case — so there is no identifier in the contract that widens
 * what the caller can reach.
 */
final class MyDocumentUploadTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('object-storage');
        config()->set('files.disk', 'object-storage');
    }

    #[Test]
    public function an_applicant_sees_what_their_own_case_still_needs(): void
    {
        [$account, $case] = $this->citizenWithCase();

        Sanctum::actingAs($account);
        $body = $this->getJson("/api/v1/me/cases/{$case}/requirements")->assertOk()->json('data');

        $this->assertCount(1, $body['requirements']);
        $this->assertSame('Not yet provided.', $body['requirements'][0]['status_message']);

        // Published so the client enforces the same limits this server does, rather than a copy
        // that drifts.
        $this->assertSame(10 * 1024 * 1024, $body['accepts']['max_bytes']);
        $this->assertContains('application/pdf', $body['accepts']['mime_types']);
    }

    #[Test]
    public function an_applicant_uploads_into_their_own_slot(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);

        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
        ])->assertCreated();

        // Uploading is presenting, not accepting. Every citizen upload lands pending.
        $this->assertSame(VerificationStatus::Pending, DocumentVersion::query()->value('verification_status'));
    }

    #[Test]
    public function an_applicant_cannot_upload_into_someone_elses_case(): void
    {
        [, $case, $requirement] = $this->citizenWithCase();
        [$stranger] = $this->citizenWithCase();

        Sanctum::actingAs($stranger);

        /*
         * NOT FOUND, never FORBIDDEN. A 403 would confirm that the case exists and belongs to
         * somebody — which is what turns a document endpoint into a case-enumeration endpoint.
         */
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 theirs'),
        ])->assertNotFound();

        $this->getJson("/api/v1/me/cases/{$case}/requirements")->assertNotFound();
        $this->assertSame(0, DocumentVersion::query()->count());
    }

    #[Test]
    public function a_requirement_from_another_case_cannot_be_filled_through_this_one(): void
    {
        [$account, $case] = $this->citizenWithCase();
        [, , $otherRequirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);

        // Without the case-scoped requirement lookup the case id would be decoration.
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$otherRequirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 x'),
        ])->assertNotFound();
    }

    #[Test]
    public function an_applicant_cannot_claim_a_clerk_saw_the_paper(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);

        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
            // Ignored: the source comes from the route, not the body.
            'source' => 'scanned',
        ])->assertCreated();

        /*
         * `scanned` asserts a clerk imaged the paper at the counter and
         * `external-verification` that staff telephoned the issuer. An applicant cannot make
         * either claim about themselves — the same rule as the intake source in ADR 0017.
         */
        $this->assertSame(DocumentSource::Uploaded, DocumentVersion::query()->value('source'));
    }

    #[Test]
    public function an_applicant_cannot_verify_their_own_document(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
        ])->assertCreated();

        // The staff endpoints hold no citizen path at all.
        $this->postJson("/api/v1/admin/cases/{$case}/requirements/{$requirement}/verification", [
            'status' => 'verified',
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/cases/{$case}/requirements/{$requirement}/applicability", [
            'applicability' => 'does-not-apply',
            'reason' => 'I do not have one.',
        ])->assertForbidden();
    }

    #[Test]
    public function the_applicant_view_omits_the_offices_handling(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
        ])->assertCreated();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/cases/{$case}/requirements/{$requirement}/verification", [
            'status' => 'verified',
            'note' => 'Checked against the barangay register by J. Cruz.',
        ])->assertOk();

        Sanctum::actingAs($account);
        $body = $this->getJson("/api/v1/me/cases/{$case}/requirements")->assertOk()->content();
        $requirementBody = json_decode($body, true)['data']['requirements'][0];

        $this->assertTrue($requirementBody['is_accepted']);
        $this->assertSame('Received and accepted.', $requirementBody['status_message']);

        // The internal remark on an ACCEPTANCE is not for the applicant. It is shown only on a
        // rejection, where it is the instruction for what to bring instead.
        $this->assertStringNotContainsString('J. Cruz', $body);
        $this->assertNull($requirementBody['current_version']['message']);

        // Nor the scan status, the reviewer, or the verification timestamp.
        $this->assertStringNotContainsString('scan_status', $body);
        $this->assertStringNotContainsString('verified_by', $body);
    }

    #[Test]
    public function a_rejection_tells_the_applicant_what_to_do_next(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
        ])->assertCreated();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/cases/{$case}/requirements/{$requirement}/verification", [
            'status' => 'rejected',
            'note' => 'The certificate names a different person.',
        ])->assertOk();

        Sanctum::actingAs($account);
        $body = $this->getJson("/api/v1/me/cases/{$case}/requirements")->assertOk()->json('data.requirements.0');

        $this->assertSame('This needs to be provided again.', $body['status_message']);
        $this->assertSame('The certificate names a different person.', $body['current_version']['message']);
    }

    #[Test]
    public function an_applicant_opens_only_what_they_supplied(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);
        $version = $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 mine'),
        ])->assertCreated()->json('data.id');

        $handle = $this->postJson(
            "/api/v1/me/cases/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        $this->get("/api/v1/documents/{$handle}")->assertOk();

        // And a stranger holding the handle gets nothing.
        [$stranger] = $this->citizenWithCase();
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/documents/{$handle}")->assertNotFound();
    }

    #[Test]
    public function a_citizen_upload_is_checked_by_the_server_not_the_app(): void
    {
        [$account, $case, $requirement] = $this->citizenWithCase();

        Sanctum::actingAs($account);

        // A client that skipped its own courtesy check, or one written by somebody else.
        $this->postJson("/api/v1/me/cases/{$case}/requirements/{$requirement}/documents", [
            'file' => UploadedFile::fake()->createWithContent('photo.png', 'MZ not a png at all'),
        ])->assertStatus(415);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * A citizen account, a case belonging to them, and its one requirement.
     *
     * @return array{Account, string, string}
     */
    private function citizenWithCase(): array
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $case = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'medical',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');

        $requirements = $this->postJson("/api/v1/admin/cases/{$case}/requirements", [
            'program_id' => (string) $this->program()->uuid,
        ])->assertCreated()->json('data.requirements');

        return [$account, $case, $requirements[0]['id']];
    }

    private function program(): Program
    {
        /** @var Program $program */
        $program = Program::query()->firstOrCreate(['code' => 'AICS'], [
            'name' => 'AICS',
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'published',
            'is_citizen_visible' => true,
            'eligibility_guidance_version' => '1',
        ]);

        ProgramRequirement::query()->firstOrCreate(
            ['program_id' => $program->id, 'code' => 'barangay-certificate', 'template_version' => '1'],
            [
                'label' => 'Barangay certificate of indigency',
                'obligation' => 'required',
                'citizen_instructions' => 'Ask your barangay hall for a certificate of indigency.',
            ],
        );

        return $program;
    }
}
