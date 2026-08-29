<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Application\AuditActionCatalog;
use Modules\Audit\Application\RetentionPolicy;
use Modules\Audit\Domain\AuditRisk;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Contracts\AuditWriter;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 29, as tests.
 *
 *  1. **Audit logs avoid unnecessary PII duplication.**
 *  2. **Sensitive exports and downloads are traceable.**
 *  3. **Retention and legal-basis values remain configurable pending LGU approval.**
 */
final class AuditAndPrivacyTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: the trail is not a second copy of the data ──────────────────────

    #[Test]
    public function a_changeset_passed_whole_stores_only_its_field_names(): void
    {
        /*
         * THE MISTAKE THIS GUARDS AGAINST IS AN EASY ONE. A `$changes` array in an update method
         * is already keyed by field name, so passing it straight to the audit call looks right —
         * and would write a resident's birth date, address and government identifier into a table
         * that is retained longer than most records and exported for compliance review.
         */
        $this->writer()->record(null, 'resident.updated', 'Resident corrected', 'ResidentProfile.Resident', null, [
            'birth_date' => '1985-03-02',
            'address_line' => '12 Manggahan Street, Dolores',
            'philsys_number' => '1234-5678-9012',
        ]);

        $entry = DB::table('audit_entries')->where('action', 'resident.updated')->first();

        $this->assertSame('birth_date,address_line,philsys_number', $entry->changed_fields);

        // Not one value survived.
        foreach (['1985-03-02', 'Manggahan', '1234-5678-9012'] as $value) {
            $this->assertStringNotContainsString($value, (string) json_encode($entry));
        }
    }

    #[Test]
    public function anything_that_is_not_a_column_name_is_dropped(): void
    {
        $this->writer()->record(null, 'resident.updated', 'Corrected', 'ResidentProfile.Resident', null, [
            'birth_date',
            // A caller passing a list of VALUES instead of names. Each is dropped rather than
            // stored, because storing them is the failure the whole design exists to prevent.
            '1985-03-02',
            'maria.santos@example.test',
            '12 Manggahan Street',
        ]);

        $entry = DB::table('audit_entries')->where('action', 'resident.updated')->first();

        $this->assertSame('birth_date', $entry->changed_fields);
    }

    #[Test]
    public function no_audit_summary_written_by_a_full_system_run_contains_a_personal_identifier(): void
    {
        $this->exerciseTheSystem();

        $rows = DB::table('audit_entries')->get();

        $this->assertGreaterThan(5, $rows->count(), 'The fixture wrote nothing; the scan proves nothing.');

        $findings = [];

        foreach ($rows as $row) {
            $text = trim(($row->summary ?? '').' '.($row->changed_fields ?? '').' '.($row->reason ?? ''));

            foreach ($this->identifierPatterns() as $label => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $findings[] = sprintf('%s: %s → %s', $row->action, $label, $text);
                }
            }
        }

        $this->assertSame([], $findings, implode("\n", [
            'These audit entries carry something that looks like personal data:',
            '',
            ...$findings,
            '',
            'A trail that duplicates the data it protects is a second, less-guarded copy of it —',
            'read by operators investigating something else, retained longer than most records,',
            'and exported for compliance review (ADR 0034 §1).',
        ]));
    }

    #[Test]
    public function the_identifier_scanner_actually_detects_an_identifier(): void
    {
        /*
         * THE NEGATIVE FIXTURE. The scan above is worthless if the patterns match nothing — a
         * detector that cannot detect is worse than none, because it is believed.
         */
        $samples = [
            'philsys' => 'Updated to 1234-5678-9012',
            'email' => 'Contact set to maria.santos@example.test',
            'mobile' => 'Number changed to +639171234567',
            'birth date' => 'Born 1985-03-02',
        ];

        foreach ($samples as $label => $text) {
            $matched = false;

            foreach ($this->identifierPatterns() as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $matched = true;
                    break;
                }
            }

            $this->assertTrue($matched, "The scanner missed a planted {$label}.");
        }

        // And it does not flag an ordinary summary, or it would flag everything and prove nothing.
        foreach (['Welfare case approved', 'Event published', 'Registration waitlisted'] as $clean) {
            foreach ($this->identifierPatterns() as $label => $pattern) {
                $this->assertSame(0, preg_match($pattern, $clean), "False positive [{$label}] on: {$clean}");
            }
        }
    }

    // ── criterion 2: sensitive acts are traceable ────────────────────────────────────

    #[Test]
    public function every_act_the_master_command_names_is_classified_high_risk(): void
    {
        /*
         * Transcribed from the master command's own list. If somebody renames an action without
         * updating the catalogue, its risk silently drops to `routine` — and a "high-risk" filter
         * that quietly stops returning resident merges is worse than no filter at all.
         */
        $named = [
            'auth/security' => ['identity.sign-in-blocked', 'identity.account-locked', 'identity.mfa-disabled'],
            'resident merge' => ['resident.merged'],
            'verification' => ['resident.verified', 'kyc.case-approved'],
            'sensitive document download' => ['document.opened', 'document.shared'],
            'case status' => ['case.approved', 'case.rejected', 'case.closed'],
            'assessment' => ['assessment.completed'],
            'approval/release' => ['release.confirmed', 'release.scheduled'],
            'PII export' => ['report.person-level-export-requested', 'report.export-requested'],
            'role/permission change' => ['access.role-assigned', 'access.barangay-granted'],
            'newsfeed moderation/publish' => ['newsfeed.published', 'newsfeed.comment-hidden'],
            'event export/attendance' => ['event.attendance-marked', 'event.registrant-export'],
        ];

        foreach ($named as $requirement => $actions) {
            foreach ($actions as $action) {
                $this->assertSame(
                    AuditRisk::High,
                    AuditActionCatalog::riskFor($action),
                    "[{$action}] covers the master command's «{$requirement}» and must be high-risk.",
                );
            }
        }
    }

    #[Test]
    public function ordinary_work_is_not_high_risk(): void
    {
        /*
         * The other half of the classification. Marking everything high would attach network
         * identifiers to every routine read — a movement log of the office's own staff — and
         * produce a high-risk digest containing everything, which nobody reads.
         */
        foreach (['task.open', 'provider.created', 'file.uploaded', 'identity.token-issued'] as $action) {
            $this->assertSame(AuditRisk::Routine, AuditActionCatalog::riskFor($action));
        }
    }

    #[Test]
    public function a_sensitive_export_leaves_a_high_risk_trail(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $this->postJson('/api/v1/admin/exports', [
            'report' => 'release-manifest',
            'format' => 'csv',
        ])->assertCreated();

        $entry = DB::table('audit_entries')
            ->where('action', 'report.person-level-export-requested')
            ->first();

        // The acceptance criterion, end to end: a copy of a caseload was requested, and the trail
        // says who asked and when.
        $this->assertNotNull($entry, 'A person-level export left no trail.');
        $this->assertSame('high', $entry->risk);
        $this->assertNotNull($entry->request_id);
    }

    #[Test]
    public function reading_the_trail_is_itself_recorded(): void
    {
        $auditor = $this->auditor();
        Sanctum::actingAs($auditor);

        $this->getJson('/api/v1/admin/audit-entries?risk=high')->assertOk();

        /*
         * The trail is more concentrated than any record it describes: a search for
         * `safeguarding.opened` names which residents have protection cases without opening one.
         * So "who has been reading the audit log" must have an answer.
         */
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'audit.searched',
            'actor_subject_id' => (string) $auditor->uuid,
        ]);
    }

    #[Test]
    public function the_trail_is_not_readable_without_its_own_permission(): void
    {
        // Held by nobody by default — not even the MSWDO head, whose own reads it records.
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->getJson('/api/v1/admin/audit-entries')->assertForbidden();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);
        $this->getJson('/api/v1/admin/audit-entries')->assertForbidden();
    }

    // ── criterion 3: retention stays configurable and unapproved ─────────────────────

    #[Test]
    public function nothing_is_purged_until_the_dpo_approves_the_schedule(): void
    {
        $policy = app(RetentionPolicy::class);

        $this->assertFalse($policy->isApproved());

        [$mayPurge, $reason] = $policy->mayPurge(
            'welfare_case',
            now()->subYears(20),
            'Welfare.Case',
            '11111111-1111-1111-1111-111111111111',
        );

        /*
         * TWENTY YEARS PAST ANY PLAUSIBLE SCHEDULE, and still refused. Deletion is the one
         * operation this system cannot undo: a record kept too long can be destroyed tomorrow, a
         * record destroyed on an unapproved timetable is gone.
         */
        $this->assertFalse($mayPurge);
        $this->assertStringContainsString('Data Protection Officer', $reason);
    }

    #[Test]
    public function an_approved_schedule_still_refuses_a_record_under_legal_hold(): void
    {
        config()->set('privacy.retention.approved', true);

        $dpo = $this->dpo();
        Sanctum::actingAs($dpo);

        $caseId = '22222222-2222-2222-2222-222222222222';

        $this->postJson('/api/v1/admin/privacy/legal-holds', [
            'entity_type' => 'Welfare.Case',
            'entity_id' => $caseId,
            'reference' => 'NPC-2026-004',
            'reason' => 'Complaint under investigation by the National Privacy Commission.',
        ])->assertCreated();

        [$mayPurge, $reason] = app(RetentionPolicy::class)
            ->mayPurge('welfare_case', now()->subYears(20), 'Welfare.Case', $caseId);

        // THE HOLD OUTRANKS THE SCHEDULE, in one direction only: a hold can prevent a deletion
        // and can never cause one.
        $this->assertFalse($mayPurge);
        $this->assertStringContainsString('legal hold', $reason);
    }

    #[Test]
    public function a_hold_on_the_subject_covers_every_record_about_them(): void
    {
        config()->set('privacy.retention.approved', true);

        Sanctum::actingAs($this->dpo());

        $subject = '33333333-3333-3333-3333-333333333333';

        $this->postJson('/api/v1/admin/privacy/legal-holds', [
            'entity_type' => 'ResidentProfile.Resident',
            'subject_id' => $subject,
            'reference' => 'RTC-2026-11',
            'reason' => 'Subpoena.',
        ])->assertCreated();

        /*
         * An investigation into a household's assistance does not know in advance which document
         * will matter, so a subject-level hold covers records of any type about them.
         */
        [$mayPurge] = app(RetentionPolicy::class)->mayPurge(
            'document',
            now()->subYears(20),
            'Files.Document',
            // A uuid, because `legal_holds.entity_id` is one and PostgreSQL type-checks the
            // comparison even in a WHERE clause. The point of the fixture is that this document is
            // NOT the held one — a different uuid says that as clearly as a made-up string did.
            '01a04d5a-0000-7000-8000-0000000000ff',
            $subject,
        );

        $this->assertFalse($mayPurge);
    }

    #[Test]
    public function lifting_a_hold_must_say_why_and_is_audited(): void
    {
        $dpo = $this->dpo();
        Sanctum::actingAs($dpo);

        $hold = $this->postJson('/api/v1/admin/privacy/legal-holds', [
            'entity_type' => 'Welfare.Case',
            'reference' => 'NPC-2026-004',
            'reason' => 'Complaint under investigation.',
        ])->assertCreated()->json('data.id');

        // Lifting is what allows a record to be destroyed.
        $this->postJson("/api/v1/admin/privacy/legal-holds/{$hold}/lift")->assertStatus(422);

        $this->postJson("/api/v1/admin/privacy/legal-holds/{$hold}/lift", [
            'reason' => 'Investigation closed; NPC notified 2026-08-14.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'privacy.legal-hold-lifted',
            'risk' => 'high',
            'actor_subject_id' => (string) $dpo->uuid,
        ]);
    }

    #[Test]
    public function the_retention_schedule_says_it_is_not_law(): void
    {
        Sanctum::actingAs($this->dpo());

        $body = $this->getJson('/api/v1/admin/privacy/retention')->assertOk()->json('data');

        $this->assertFalse($body['approved']);
        $this->assertNotEmpty($body['categories']);
        // Stated in the payload, not only in a docblock, so a console can say it in the interface.
        $this->assertStringContainsString('placeholders', $body['notice']);
        $this->assertStringContainsString('RA 10173', $body['notice']);
    }

    // ── consent is not the basis for most of what this office does ───────────────────

    #[Test]
    public function consent_cannot_be_recorded_for_statutory_processing(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * THE MOST USEFUL REFUSAL IN THE MODULE. Recording statutory processing as "consent" is
         * not a labelling error — it is a promise, because consent implies a right to withdraw.
         * An office that offers withdrawal for processing it is legally obliged to perform must
         * then break the promise or break the law (ADR 0034 §4).
         */
        foreach (['welfare_assistance', 'kyc_verification', 'resident_registry'] as $statutory) {
            $this->postJson('/api/v1/me/privacy/consents', ['purpose' => $statutory])->assertStatus(422);
        }

        // And the genuinely optional ones work.
        $this->postJson('/api/v1/me/privacy/consents', ['purpose' => 'photography_for_publication'])
            ->assertCreated();
    }

    #[Test]
    public function withdrawing_consent_keeps_the_record_and_frees_a_new_one(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/v1/me/privacy/consents', ['purpose' => 'photography_for_publication'])
            ->assertCreated();

        $this->deleteJson('/api/v1/me/privacy/consents/photography_for_publication', [
            'reason' => 'Changed my mind.',
        ])->assertOk();

        /*
         * A TIMESTAMP, NOT A DELETED ROW. "Was this photograph published with permission at the
         * time?" is a question the office must be able to answer after a withdrawal.
         */
        $rows = $this->getJson('/api/v1/me/privacy/consents')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_live']);
        $this->assertNotNull($rows[0]['withdrawn_at']);

        // And consenting again is possible: the derived active key was NULLed.
        $this->postJson('/api/v1/me/privacy/consents', ['purpose' => 'photography_for_publication'])
            ->assertCreated();

        $this->assertCount(2, $this->getJson('/api/v1/me/privacy/consents')->json('data'));
    }

    #[Test]
    public function a_citizen_reaches_only_their_own_consents(): void
    {
        [$owner] = $this->activeCitizenWithResident();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/me/privacy/consents', ['purpose' => 'research_and_statistics'])->assertCreated();

        [$stranger] = $this->activeCitizenWithResident();
        Sanctum::actingAs($stranger);

        // There is no identifier in this contract at all: the query is scoped to the token.
        $this->assertCount(0, $this->getJson('/api/v1/me/privacy/consents')->assertOk()->json('data'));
        $this->deleteJson('/api/v1/me/privacy/consents/research_and_statistics')->assertNotFound();
    }

    #[Test]
    public function the_privacy_notice_is_readable_without_an_account(): void
    {
        Sanctum::actingAs($this->dpo());

        $this->postJson('/api/v1/admin/privacy/notices', [
            'version' => '2026.1',
            'title' => 'Privacy Notice',
            'summary' => 'How the Municipality of Taytay handles your personal data.',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        /*
         * A notice that required an account to read would be one a person could not consult before
         * deciding whether to create an account — which is the exact moment they need it.
         */
        $body = $this->getJson('/api/v1/privacy/notice')->assertOk()->json('data');

        $this->assertSame('2026.1', $body['notice']['version']);
        // The bases are published too: a resident is entitled to know that most of what this
        // office does with their data was not something they were asked to agree to.
        $this->assertSame('legal-obligation', $body['legal_bases']['welfare_assistance']);
    }

    #[Test]
    public function publishing_a_new_notice_supersedes_the_old_one_without_erasing_it(): void
    {
        Sanctum::actingAs($this->dpo());

        foreach (['2026.1', '2026.2'] as $version) {
            $this->postJson('/api/v1/admin/privacy/notices', [
                'version' => $version,
                'title' => 'Privacy Notice',
                'summary' => 'How the Municipality of Taytay handles your personal data.',
            ])->assertCreated();
        }

        $this->assertSame('2026.2', $this->getJson('/api/v1/privacy/notice')->json('data.notice.version'));

        /*
         * The old version survives, superseded rather than removed. An acknowledgement of 2026.1
         * is only meaningful while 2026.1 is still readable exactly as it was shown.
         */
        $this->assertDatabaseCount('privacy_notices', 2);
        $this->assertDatabaseHas('privacy_notices', ['version' => '2026.1']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function writer(): AuditWriter
    {
        return app(AuditWriter::class);
    }

    /**
     * The one role that may read the trail.
     *
     * A real role through the real machinery, not a fixture that grants a permission directly —
     * because the interesting property is that `lgu_admin` does NOT have it, and a test that
     * hand-granted permissions would be unable to notice if that changed.
     */
    private function auditor(): Account
    {
        return $this->reviewer('data_protection_officer');
    }

    private function dpo(): Account
    {
        return $this->reviewer('data_protection_officer');
    }

    /**
     * Real work, so the trail has real entries to scan.
     */
    private function exerciseTheSystem(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help with hospital bills.',
            'consent_reference' => 'ack-audit-scan',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();
    }

    /**
     * Patterns for the identifiers that must never reach the trail.
     *
     * @return array<string, string>
     */
    private function identifierPatterns(): array
    {
        return [
            // PhilSys, in its printed form.
            'philsys number' => '/\b\d{4}-\d{4}-\d{4}\b/',
            'email address' => '/[\w.+-]+@[\w-]+\.[\w.-]+/',
            'philippine mobile' => '/\+63\d{9,10}\b/',
            'date of birth' => '/\b(19|20)\d{2}-\d{2}-\d{2}\b/',
            // A street address, in the form Taytay records use.
            'street address' => '/\b\d+\s+\w+\s+(Street|St\.|Avenue|Ave\.|Road|Rd\.)\b/i',
        ];
    }
}
