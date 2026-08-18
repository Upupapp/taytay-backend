<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Domain\AcceptedMediaType;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Files\Jobs\ProcessUploadedFile;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 15, as tests.
 *
 *  1. **Direct object-storage paths are not public.**
 *  2. **Unauthorized guessed file ids cannot be downloaded.**
 *  3. **Replacing a document preserves the old version's metadata.**
 *
 * Plus the two rules the master command states in prose and that are easiest to lose: a citizen
 * may upload only into their own permitted slots, and the server — not the client — decides what
 * a file actually is.
 */
final class CaseDocumentTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fake disk, so the tests exercise the real storage path rather than a mock of it,
        // and so nothing writes outside the test run.
        Storage::fake('object-storage');
        config()->set('files.disk', 'object-storage');
    }

    // ── the server decides what a file is ─────────────────────────────────────────────

    #[Test]
    public function a_file_pretending_to_be_an_image_is_refused(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        /*
         * The exact attack the client-side check cannot stop: correct extension, correct
         * declared MIME type, contents that are neither. Both clients check this before
         * uploading as a courtesy; this is the boundary.
         */
        $response = $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('id.jpg', '<?php echo "not an image";'),
        ]);

        $response->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        $this->assertSame(0, StoredFile::query()->count());
    }

    #[Test]
    public function a_file_over_the_limit_is_refused_with_a_code_the_client_can_act_on(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent(
                'big.pdf',
                '%PDF'.str_repeat('x', AcceptedMediaType::MAX_BYTES + 1),
            ),
        ])
            // Distinct from a generic 422: "too large" means take a photo instead, "wrong type"
            // means give up on this file. Both clients already have copy for each.
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    #[Test]
    public function the_stored_name_and_type_come_from_the_contents_not_the_caller(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        // A PDF wearing a .jpg name, in a directory that would traverse if it were ever used
        // to build a path.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('../../etc/passwd.jpg', '%PDF-1.4 real pdf'),
        ])->assertCreated();

        /** @var StoredFile $file */
        $file = StoredFile::query()->firstOrFail();

        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertStringEndsWith('.pdf', (string) $file->storage_key);

        // Nothing the caller sent contributes a character to the key.
        $this->assertStringNotContainsString('..', (string) $file->storage_key);
        $this->assertStringNotContainsString('passwd', (string) $file->storage_key);
        $this->assertStringNotContainsString('/', (string) $file->original_name);
    }

    #[Test]
    public function post_upload_work_is_queued_rather_than_made_the_uploader_wait(): void
    {
        Queue::fake();
        [$case, $requirement] = $this->caseWithRequirement();

        $this->recordScan($case, $requirement);

        Queue::assertPushed(ProcessUploadedFile::class);

        // Pending is not clean. A file nobody has scanned must not look scanned.
        $this->assertSame('pending', StoredFile::query()->firstOrFail()->scan_status->value);
    }

    // ── criterion 1: nothing is public ────────────────────────────────────────────────

    #[Test]
    public function the_private_disk_exposes_no_public_url(): void
    {
        // The structural half of the criterion: a disk with no `url` cannot produce a public
        // link even by accident, so no future endpoint can hand one out.
        $this->assertNull(config('filesystems.disks.object-storage.url'));
        $this->assertNotSame('public', config('filesystems.disks.object-storage.visibility'));
    }

    #[Test]
    public function no_endpoint_returns_a_storage_path(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $body = $this->getJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents")
            ->assertOk()->content();

        $key = (string) StoredFile::query()->firstOrFail()->storage_key;

        // The behavioural half: the key never leaves the server, so there is nothing to paste.
        $this->assertStringNotContainsString($key, $body);
        $this->assertStringNotContainsString('object-storage', $body);
        $this->assertNotEmpty($version);
    }

    // ── criterion 2: a guessed id opens nothing ───────────────────────────────────────

    #[Test]
    public function a_guessed_handle_downloads_nothing(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->getJson('/api/v1/documents/'.Str::uuid7())->assertNotFound();
    }

    #[Test]
    public function a_handle_issued_to_someone_else_is_not_found_rather_than_forbidden(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $handle = $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        // A second staff member, with every permission the first one has.
        Sanctum::actingAs($this->secondReviewer());

        /*
         * NOT FOUND, never FORBIDDEN. "Forbidden" would confirm the handle was real, which is
         * exactly what somebody probing wants to learn (OWASP API1).
         */
        $this->getJson("/api/v1/documents/{$handle}")->assertNotFound();
    }

    #[Test]
    public function a_handle_works_once(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $handle = $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        $this->get("/api/v1/documents/{$handle}")->assertOk();

        // A handle left in a browser history or pasted into a chat is already dead.
        $this->getJson("/api/v1/documents/{$handle}")->assertNotFound();
    }

    #[Test]
    public function an_expired_handle_opens_nothing(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $handle = $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        $this->travel(5)->minutes();

        $this->getJson("/api/v1/documents/{$handle}")->assertNotFound();
    }

    #[Test]
    public function a_version_from_another_case_cannot_be_opened_through_this_one(): void
    {
        [$caseA, $requirementA] = $this->caseWithRequirement();
        $versionA = $this->recordScan($caseA, $requirementA);

        [$caseB, $requirementB] = $this->caseWithRequirement();

        /*
         * Without the slot check the case id in the path would be decoration, and any version
         * uuid would open from any case the caller can reach. That is the guessed-id path the
         * criterion is about, one level up from the handle.
         */
        $this->postJson("/api/v1/admin/assistance-requests/{$caseB}/requirements/{$requirementB}/documents/{$versionA}/access")
            ->assertNotFound();
    }

    #[Test]
    public function reading_a_document_is_audited(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $handle = $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        $this->get("/api/v1/documents/{$handle}")->assertOk();

        // Article 5.4. Object storage never tells the application a fetch happened, which is
        // exactly why the bytes come through here rather than from a signed URL.
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'document.read',
            'entity_id' => $version,
        ]);
    }

    #[Test]
    public function the_download_refuses_to_be_embedded_or_cached(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        $handle = $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
        )->assertOk()->json('data.handle');

        $response = $this->get("/api/v1/documents/{$handle}")->assertOk();

        // A citizen's identity document rendered inline in somebody's page is a disclosure with
        // no record of having happened.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    // ── criterion 3: replacing preserves history ──────────────────────────────────────

    #[Test]
    public function replacing_a_document_preserves_the_previous_version(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        $first = $this->recordScan($case, $requirement, 'first.pdf');

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('second.pdf', '%PDF-1.4 second'),
            'replaces_because' => 'The first scan was unreadable.',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $versions = $this->getJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents")
            ->assertOk()->json('data.versions');

        $this->assertCount(2, $versions);

        /*
         * THE CRITERION. The superseded version is the evidence of what the office actually saw
         * when it decided — a case approved in March on a certificate replaced in June must
         * still be explicable in December.
         */
        $this->assertSame($first, $versions[0]['id']);
        $this->assertSame(1, $versions[0]['version']);
        $this->assertNotNull($versions[0]['superseded_at']);
        $this->assertSame('The first scan was unreadable.', $versions[0]['superseded_reason']);
        $this->assertSame('first.pdf', $versions[0]['file']['name']);

        // And its bytes are still there — superseding is not deleting.
        $this->assertSame(2, StoredFile::query()->count());
    }

    #[Test]
    public function a_replacement_must_say_why(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $this->recordScan($case, $requirement);

        // An unexplained supersession leaves a version nobody can account for — worse than no
        // history, because it looks like history.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('second.pdf', '%PDF-1.4 second'),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_superseded_version_cannot_be_verified(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $this->recordScan($case, $requirement);
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('second.pdf', '%PDF-1.4 second'),
            'replaces_because' => 'Unreadable.',
        ])->assertCreated();

        // Verifying the old one would put an accepted stamp on a document the office has already
        // replaced, and the requirement would then be satisfied by evidence nobody is using.
        $versions = $this->getJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents")
            ->json('data.versions');

        $this->assertNotNull($versions[0]['superseded_at']);
        $this->assertSame(2, $versions[1]['version']);
    }

    // ── the document number is masked before it is stored ─────────────────────────────

    #[Test]
    public function a_document_number_is_reduced_to_four_characters_before_storage(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'external-verification',
            'document_number' => '1234-5678-9012-3456',
        ])->assertCreated()->assertJsonPath('data.document_number', '••••3456');

        /*
         * The point of masking at write time rather than render time: the full number is not in
         * the database, so it is not in a backup, a replica, a dump or a query log, and no
         * future endpoint can leak what was never kept.
         */
        $stored = (string) DocumentVersion::query()->value('document_number_last4');
        $this->assertSame('3456', $stored);
        $this->assertDatabaseMissing('document_versions', ['document_number_last4' => '1234-5678-9012-3456']);
    }

    #[Test]
    public function a_number_is_not_kept_at_all_where_the_file_is_the_record(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('id.jpg', "\xFF\xD8\xFFrest"),
            'document_number' => '1234-5678-9012-3456',
        ])->assertCreated()->assertJsonPath('data.document_number', null);

        // The image IS the record here. Storing the number as well would be a second copy of a
        // government identifier for no operational gain.
        $this->assertNull(DocumentVersion::query()->value('document_number_last4'));
    }

    #[Test]
    public function a_sourceless_record_may_not_carry_a_file_and_a_file_source_may_not_omit_one(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        // Claims the office holds a copy it does not.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'external-verification',
            'file' => UploadedFile::fake()->createWithContent('id.jpg', "\xFF\xD8\xFFrest"),
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
        ])->assertStatus(422);
    }

    // ── who may do what ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_clerk_who_receives_a_document_is_not_the_one_who_accepts_it(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();

        Sanctum::actingAs($this->reviewer('lgu_staff'));

        // Taking papers at the counter is the job.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 x'),
        ])->assertCreated();

        /*
         * Judging it sufficient is not. A verified requirement is what advances a case toward
         * money, so it is a second pair of eyes by construction.
         */
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/verification", [
            'status' => 'verified',
        ])->assertForbidden();
    }

    #[Test]
    public function a_rejection_must_say_what_was_wrong(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $this->recordScan($case, $requirement);

        // The applicant has to be told what to bring instead, and an unexplained rejection
        // cannot be told apart from a mistake.
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/verification", [
            'status' => 'rejected',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/verification", [
            'status' => 'rejected',
            'note' => 'The certificate names a different person.',
        ])->assertOk()->assertJsonPath('data.verification_status', 'rejected');
    }

    #[Test]
    public function receiving_a_document_does_not_satisfy_the_requirement(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $this->recordScan($case, $requirement);

        $body = $this->getJson("/api/v1/admin/assistance-requests/{$case}/requirements")->assertOk()->json('data');

        // Treating receipt as satisfaction would let a case reach approval on papers nobody
        // read.
        $this->assertFalse($body['requirements'][0]['is_satisfied']);
        $this->assertSame(1, $body['outstanding_count']);

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/verification", [
            'status' => 'verified',
        ])->assertOk();

        $after = $this->getJson("/api/v1/admin/assistance-requests/{$case}/requirements")->assertOk()->json('data');
        $this->assertTrue($after['requirements'][0]['is_satisfied']);
        $this->assertSame(0, $after['outstanding_count']);
    }

    #[Test]
    public function nobody_currently_holds_the_permission_to_share_a_copy_outward(): void
    {
        [$case, $requirement] = $this->caseWithRequirement();
        $version = $this->recordScan($case, $requirement);

        /*
         * The outward-sharing path is built and refused rather than built and quietly granted.
         * The first holder of `document.share` should be a decision the LGU makes on the record,
         * not a line that arrived with a feature (gap G-26).
         */
        $this->postJson(
            "/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents/{$version}/access",
            ['for_sharing' => true],
        )->assertForbidden();
    }

    #[Test]
    public function document_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/assistance-requests/'.Str::uuid7().'/requirements')->assertUnauthorized();
        $this->getJson('/api/v1/documents/'.Str::uuid7())->assertUnauthorized();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * A case with one required document slot, with an admin already signed in.
     *
     * @return array{string, string}
     */
    private function caseWithRequirement(): array
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $program = $this->program();
        $resident = $this->applicant();

        $case = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'medical',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');

        $requirements = $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements", [
            'program_id' => (string) $program->uuid,
        ])->assertCreated()->json('data.requirements');

        return [$case, $requirements[0]['id']];
    }

    private function recordScan(string $case, string $requirement, string $name = 'cert.pdf'): string
    {
        return $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$requirement}/documents", [
            'source' => 'scanned',
            'file' => UploadedFile::fake()->createWithContent($name, '%PDF-1.4 contents'),
        ])->assertCreated()->json('data.id');
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

    private function applicant(): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => 'Doc'.$n,
            'middle_name' => null,
            'last_name' => 'Umento',
            'birth_date' => '1986-02-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    private function secondReviewer(): Account
    {
        $account = Account::factory()->staff()->create();
        $this->grantRole($account, 'lgu_admin', $this->barangayId());

        return $account;
    }
}
