<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ResidentProfile\Application\KycCaseService;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use PHPUnit\Framework\Attributes\Test;

/**
 * The privacy commitments of TAB 06, asserted rather than asserted-to.
 *
 * Two of them are structural: biometric templates are not stored because there is nowhere
 * to store them, and a full PhilSys number cannot leak because no column exists. A field
 * that does not exist is the only field that is certainly safe.
 */
final class KycPrivacyAndRetentionTest extends KycTestCase
{
    use RefreshDatabase;

    // ── biometrics ────────────────────────────────────────────────────────────────────

    #[Test]
    public function biometrics_are_disabled_by_default(): void
    {
        // Off in config, and the template switch is not even environment-tunable.
        $this->assertFalse((bool) config('resident_profile.biometrics.enabled'));
        $this->assertFalse((bool) config('resident_profile.biometrics.store_templates'));
    }

    #[Test]
    public function there_is_nowhere_to_store_a_biometric_template(): void
    {
        /*
         * Biometric data is irrevocable: a leaked password is changed, a leaked face is
         * not. The safest design is the absence of a column — a schema that cannot hold a
         * template cannot be talked into holding one later by someone who assumes the
         * field is already there.
         */
        foreach (['residents', 'kyc_cases', 'kyc_documents'] as $table) {
            foreach (['biometric', 'biometric_template', 'face_template', 'fingerprint_template', 'iris'] as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "`{$table}.{$column}` exists; biometric templates must not be stored (ADR 0010 §5)."
                );
            }
        }
    }

    #[Test]
    public function there_is_no_column_for_a_full_philsys_number(): void
    {
        // RA 11055: only the last four digits are ever held, and even those are encrypted.
        $this->assertTrue(Schema::hasColumn('residents', 'philsys_last_four'));
        $this->assertFalse(Schema::hasColumn('residents', 'philsys_number'));
        $this->assertFalse(Schema::hasColumn('residents', 'psn'));
    }

    #[Test]
    public function the_philsys_fragment_is_encrypted_at_rest(): void
    {
        $resident = $this->existingResident();
        $resident->forceFill(['philsys_last_four' => '4821'])->save();

        $stored = (string) DB::table('residents')->where('id', $resident->id)->value('philsys_last_four');

        // Four digits plus a name and birth date is a meaningful correlation key against
        // other datasets, so even the fragment is encrypted.
        $this->assertNotSame('4821', $stored);
        $this->assertSame('4821', $resident->refresh()->philsys_last_four);
    }

    #[Test]
    public function a_resident_never_serialises_its_sensitive_columns_by_accident(): void
    {
        $resident = $this->existingResident();
        $resident->forceFill(['philsys_last_four' => '4821'])->save();

        $array = $resident->refresh()->toArray();

        // One stray `Log::info($resident)` must not put a government identifier fragment
        // or the matching key in a log file.
        $this->assertArrayNotHasKey('philsys_last_four', $array);
        $this->assertArrayNotHasKey('identity_fingerprint', $array);
    }

    // ── document handling ─────────────────────────────────────────────────────────────

    #[Test]
    public function documents_are_stored_as_pointers_not_as_images(): void
    {
        // The image lives on the private object-storage disk; the row is a pointer plus a
        // hash. Putting scans in the database puts them in every backup and replica.
        $this->assertTrue(Schema::hasColumn('kyc_documents', 'storage_path'));
        $this->assertTrue(Schema::hasColumn('kyc_documents', 'content_hash'));
        $this->assertFalse(Schema::hasColumn('kyc_documents', 'contents'));
        $this->assertFalse(Schema::hasColumn('kyc_documents', 'image_data'));
        $this->assertFalse(Schema::hasColumn('kyc_documents', 'base64'));
    }

    #[Test]
    public function no_document_number_is_extracted_and_stored(): void
    {
        // The reviewer reads the image. Storing the number as well would create a second
        // copy of a government identifier for no operational gain.
        foreach (['document_number', 'id_number', 'serial_number'] as $column) {
            $this->assertFalse(Schema::hasColumn('kyc_documents', $column));
        }
    }

    // ── retention ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function submitting_starts_the_retention_clock(): void
    {
        $case = $this->submittedCase();

        // The documents exist to answer one question; the clock on keeping them starts
        // when it is asked (RA 10173 storage limitation).
        $this->assertNotNull($case->purge_after);
        $this->assertTrue(
            $case->purge_after->between(
                now()->addDays((int) config('resident_profile.kyc.retention_days'))->subMinute(),
                now()->addDays((int) config('resident_profile.kyc.retention_days'))->addMinute(),
            ),
        );
    }

    #[Test]
    public function a_case_records_whether_its_documents_have_been_purged(): void
    {
        // Purging clears the stored objects while keeping the case row as evidence that a
        // document was supplied and reviewed — the decision stays auditable after the
        // evidence is destroyed.
        $this->assertTrue(Schema::hasColumn('kyc_cases', 'purged_at'));
        $this->assertTrue(Schema::hasColumn('kyc_documents', 'deleted_from_storage_at'));

        $case = $this->submittedCase();
        $this->assertFalse($case->isPurged());
    }

    // ── canonical source of truth ─────────────────────────────────────────────────────

    #[Test]
    public function the_case_holds_claims_and_the_resident_holds_facts(): void
    {
        $case = $this->submittedCase();

        // The claim and the canonical record are different tables on purpose: writing an
        // unverified assertion into `residents` is how it quietly becomes official data.
        $this->assertSame('Maria', $case->claimed_first_name);
        $this->assertSame(0, Resident::query()->count());
    }

    #[Test]
    public function a_second_registration_while_one_is_open_does_not_create_a_second_case(): void
    {
        $account = $this->citizen();
        $service = app(KycCaseService::class);

        $first = $service->register($account->uuid, $this->claims());
        $second = $service->register($account->uuid, $this->claims(['first_name' => 'Different']));

        // Idempotent by account, and it keeps the original claims rather than silently
        // overwriting them with a second attempt.
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Maria', $second->claimed_first_name);
        $this->assertSame(1, KycCase::query()->count());
    }
}
