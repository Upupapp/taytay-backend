<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Application\ResidentMergeService;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ServiceProvider;
use Modules\Shared\Application\ActorContext;
use Modules\Welfare\Application\ReferralService;
use Modules\Welfare\Contracts\ReferralBecameOverdue;
use Modules\Welfare\Infrastructure\Eloquent\Referral;
use Modules\Welfare\Jobs\SweepOverdueReferrals;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 16, as tests.
 *
 *  1. **A referral always links to a source case/client.**
 *  2. **Sensitive attachments are not included automatically.**
 *  3. **Overdue referrals can feed Tasks/Notifications.**
 *
 * The second is the one this file spends most of its length on, because it is the criterion that
 * fails silently: nothing breaks when too much is disclosed, and nobody finds out until it
 * matters to somebody.
 */
final class ReferralTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: always linked to a client ────────────────────────────────────────

    #[Test]
    public function a_referral_cannot_be_raised_without_a_client(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/referrals', [
            'destination_name' => 'District hospital',
            'service_requested' => 'Medical social work assessment',
            'reason' => 'Unable to meet hospital bill.',
        ])->assertStatus(422);

        /*
         * A referral with no resident is a disclosure about nobody in particular: it cannot be
         * audited, cannot be answered to a subject-access request, and cannot be repointed when
         * two records turn out to be one person.
         */
        $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) Str::uuid7(),
            'destination_name' => 'District hospital',
            'service_requested' => 'Medical social work assessment',
            'reason' => 'Unable to meet hospital bill.',
        ])->assertNotFound();

        $this->assertSame(0, Referral::query()->count());
    }

    #[Test]
    public function a_referral_may_stand_without_a_case_but_never_on_someone_elses(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->client();

        // No case is legitimate: a family may be referred to a hospital's medical social worker
        // with no assistance request open. Requiring one would force a fictitious case, and a
        // fictitious case distorts every count built on cases afterwards.
        $this->draftFor($resident);

        $otherCase = $this->caseFor($this->client());

        // But a referral attached to another family's case would put a disclosure into a file it
        // does not belong to.
        $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) $resident->uuid,
            'case_id' => $otherCase,
            'destination_name' => 'District hospital',
            'service_requested' => 'Assessment',
            'reason' => 'Needs assistance.',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_merge_repoints_referrals_to_the_surviving_client(): void
    {
        Sanctum::actingAs($this->admin());

        $survivor = $this->existingResident(['first_name' => 'Ref', 'middle_name' => null, 'last_name' => 'Erral']);
        $absorbed = $this->existingResident([
            'first_name' => 'Ref', 'middle_name' => null, 'last_name' => 'Erral',
            'street_address' => '77 Duplicate Street',
        ]);

        $referral = $this->draftFor($absorbed);

        $service = app(ResidentMergeService::class);
        $pair = $service->recordPair($survivor, $absorbed, 'name-and-birth-date', 'exact');
        $pair->forceFill(['decision' => 'same-person', 'decided_at' => now()])->save();
        $service->merge(
            $survivor,
            $absorbed,
            ActorContext::authenticated((string) $this->admin()->uuid),
            'Duplicate.',
            $pair->refresh(),
        );

        /*
         * Referrals are a NEW consumer of resident_id, added after ResidentMergeCoverageTest was
         * written. That test only asserts a module has *a* mechanism, and Welfare already had
         * one — so a forgotten table here would have passed it. This is the behavioural half.
         */
        $this->assertSame(
            (string) $survivor->uuid,
            (string) Referral::query()->where('uuid', $referral)->firstOrFail()->resident_id,
        );
    }

    // ── criterion 2: nothing is disclosed automatically ───────────────────────────────

    #[Test]
    public function a_referral_sheet_carries_only_the_minimum_until_someone_chooses_more(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->client();
        $referral = $this->draftFor($resident);
        $this->recordAuthority($referral);

        $sheet = $this->getJson("/api/v1/admin/referrals/{$referral}/summary")->assertOk()->json('data');

        // Name, reference and reason — enough for the receiving office to know who is coming and
        // why. Nothing else, because nothing else was chosen.
        $labels = array_column($sheet['lines'], 'label');
        $this->assertSame(['Client', 'Referred by'], $labels);

        $body = json_encode($sheet);
        $this->assertStringNotContainsString((string) $resident->street_address, $body);
        $this->assertStringNotContainsString('1985', $body);
    }

    #[Test]
    public function each_extra_field_needs_its_own_stated_reason(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());

        // "Include everything, they can ignore what they don't need" is how a survivor's address
        // reaches a desk that had no reason to hold it.
        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'birth-date',
            'because' => '',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'birth-date',
            'because' => 'The hospital needs it to match their patient record.',
        ])->assertOk();
    }

    #[Test]
    public function a_field_that_can_endanger_the_client_needs_a_second_authorisation(): void
    {
        $referral = null;

        Sanctum::actingAs($this->admin());
        $referral = $this->draftFor($this->client());

        // Front-line staff prepare referrals. Releasing a home address is a protection decision,
        // not an intake one — it is the field an abuser needs.
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'address',
            'because' => 'So they can do a home visit.',
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'vulnerability-sectors',
            'because' => 'Relevant to the referral.',
        ])->assertForbidden();

        // An ordinary field is fine for the same person.
        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'contact-number',
            'because' => 'So the hospital can reach them.',
        ])->assertOk();
    }

    #[Test]
    public function a_withheld_field_is_absent_from_the_sheet_rather_than_marked_withheld(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->client();
        $referral = $this->draftFor($resident);
        $this->recordAuthority($referral);

        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'address',
            'because' => 'For the home visit.',
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/referrals/{$referral}/shared-fields/address")->assertOk();

        $sheet = json_encode($this->getJson("/api/v1/admin/referrals/{$referral}/summary")->assertOk()->json('data'));

        /*
         * "Address: withheld" tells the reader there is an address worth hiding, which for a
         * protection case is itself the disclosure. Omitted, not blanked.
         */
        $this->assertStringNotContainsString('withheld', $sheet);
        $this->assertStringNotContainsString('Home address', $sheet);
    }

    #[Test]
    public function no_document_travels_with_a_referral_unless_someone_attached_it(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);

        $sheet = $this->getJson("/api/v1/admin/referrals/{$referral}/summary")->assertOk()->json('data');

        // The acceptance criterion, in its simplest form: the default is nothing.
        $this->assertSame([], $sheet['attachments']);
    }

    #[Test]
    public function attaching_a_document_requires_the_permission_that_governs_outward_sharing(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());

        /*
         * The SAME permission TAB 15 defined, because it is the same act. Nobody holds
         * `document.share` yet (gap G-26), so referral attachments are refused today —
         * deliberately.
         *
         * A second, quieter permission that happened to do the same thing is how a control that
         * was decided once gets undone by a feature.
         */
        $this->postJson("/api/v1/admin/referrals/{$referral}/attachments", [
            'document_id' => (string) Str::uuid7(),
            'label' => 'Barangay certificate',
            'because' => 'Proof of residence.',
        ])->assertForbidden();
    }

    #[Test]
    public function a_referral_cannot_be_sent_without_a_lawful_basis(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());

        // RA 10173. A referral sent with no recorded basis is a disclosure nobody can justify
        // afterwards, and the justification cannot be reconstructed later.
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")
            ->assertStatus(422)
            ->assertJsonPath('error.details.blockers.0', 'disclosure-basis-required');

        $this->recordAuthority($referral);

        $this->postJson("/api/v1/admin/referrals/{$referral}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');
    }

    #[Test]
    public function a_basis_with_no_note_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());

        // A basis with no note is a checkbox, not a record.
        $this->postJson("/api/v1/admin/referrals/{$referral}/authority", [
            'basis' => 'vital-interest',
            'note' => '   ',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_sheet_states_the_basis_and_carries_a_handling_notice(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral, 'statutory-mandate', 'Required under the VAWC referral protocol.');

        $sheet = $this->getJson("/api/v1/admin/referrals/{$referral}/summary")->assertOk()->json('data');

        // The basis changes what the receiving office may lawfully do with the information —
        // material held on a vital-interest basis is not material the client agreed to have
        // passed on again.
        $this->assertStringContainsString('statutory duty', $sheet['authority_statement']);
        $this->assertStringContainsString('RA 10173', $sheet['handling_notice']);
        $this->assertStringContainsString('Do not forward it', $sheet['handling_notice']);
    }

    #[Test]
    public function producing_a_sheet_is_itself_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->getJson("/api/v1/admin/referrals/{$referral}/summary")->assertOk();

        // Somebody who prints a sheet and never sends it has still produced a document holding a
        // person's information, and that piece of paper exists.
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'referral.summary-composed',
            'entity_id' => $referral,
        ]);
    }

    #[Test]
    public function a_sent_referrals_disclosure_record_is_frozen(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();

        /*
         * The other office already has what it has. Editing afterwards would make the record
         * describe a disclosure that never happened, and the one that did would be the version
         * nobody could reconstruct.
         */
        $this->postJson("/api/v1/admin/referrals/{$referral}/shared-fields", [
            'field' => 'income',
            'because' => 'Second thoughts.',
        ])->assertStatus(409);

        $this->patchJson("/api/v1/admin/referrals/{$referral}", ['reason' => 'Rewritten.'])
            ->assertStatus(409);
    }

    // ── sending is its own decision ───────────────────────────────────────────────────

    #[Test]
    public function front_line_staff_may_prepare_a_referral_but_not_send_it(): void
    {
        Sanctum::actingAs($this->admin());
        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);

        Sanctum::actingAs($this->staff());

        // Drafting is casework and often urgent.
        $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) $this->client()->uuid,
            'destination_name' => 'PESO',
            'service_requested' => 'Job matching',
            'reason' => 'Seeking work.',
        ])->assertCreated();

        // Sending is the one irreversible step: once the sheet is out, this office no longer
        // controls who reads it.
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertForbidden();
    }

    // ── criterion 3: overdue feeds downstream work ────────────────────────────────────

    #[Test]
    public function an_overdue_referral_raises_an_event_for_tasks_and_notifications(): void
    {
        Event::fake([ReferralBecameOverdue::class]);

        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();

        // Routine urgency is a 14-day follow-up; three weeks on, nobody has heard back.
        $raised = app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(21)->toDateString()])
            ->handle(app(ReferralService::class));

        $this->assertSame(1, $raised);

        // The seam the criterion asks for. Tasks arrive in TAB 19 and notifications in TAB 20;
        // this is published now and listened to later.
        Event::assertDispatched(ReferralBecameOverdue::class, function (ReferralBecameOverdue $event) use ($referral): bool {
            return $event->referralUuid === $referral && $event->daysOverdue >= 7;
        });
    }

    #[Test]
    public function hearing_back_takes_a_referral_out_of_the_overdue_queue(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();
        $this->postJson("/api/v1/admin/referrals/{$referral}/status", ['status' => 'acknowledged'])->assertOk();

        // Hearing anything at all discharges the commitment to chase.
        $raised = app(SweepOverdueReferrals::class, ['asOf' => now()->addDays(60)->toDateString()])
            ->handle(app(ReferralService::class));

        $this->assertSame(0, $raised);
    }

    #[Test]
    public function a_draft_never_appears_in_the_overdue_queue(): void
    {
        Sanctum::actingAs($this->admin());

        $this->draftFor($this->client());

        // A follow-up date on something that never left the building would put a draft into a
        // queue of things somebody has to chase another office about.
        $raised = app(SweepOverdueReferrals::class, ['asOf' => now()->addYear()->toDateString()])
            ->handle(app(ReferralService::class));

        $this->assertSame(0, $raised);
    }

    #[Test]
    public function the_queue_puts_overdue_and_urgent_work_first(): void
    {
        Sanctum::actingAs($this->admin());

        $routine = $this->draftFor($this->client(), ['urgency' => 'routine']);
        $urgent = $this->draftFor($this->client(), ['urgency' => 'urgent']);

        foreach ([$routine, $urgent] as $id) {
            $this->recordAuthority($id);
            $this->postJson("/api/v1/admin/referrals/{$id}/send")->assertOk();
        }

        $ordered = $this->getJson('/api/v1/admin/referrals')->assertOk()->json('data');

        // The order a queue is actually worked in.
        $this->assertSame($urgent, $ordered[0]['id']);
    }

    // ── the lifecycle ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_status_cannot_skip_the_lifecycle(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);

        // A draft has not been sent; it cannot have been served.
        $this->postJson("/api/v1/admin/referrals/{$referral}/status", [
            'status' => 'served',
            'outcome' => 'Assisted.',
        ])->assertStatus(409);
    }

    #[Test]
    public function closing_a_referral_requires_saying_what_happened(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();

        // A referral that simply stops is indistinguishable from one everybody forgot.
        $this->postJson("/api/v1/admin/referrals/{$referral}/status", ['status' => 'declined'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/referrals/{$referral}/status", [
            'status' => 'declined',
            'outcome' => 'Outside their catchment area.',
        ])->assertOk();
    }

    #[Test]
    public function waiting_requirements_can_return_to_in_progress(): void
    {
        Sanctum::actingAs($this->admin());

        $referral = $this->draftFor($this->client());
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();
        $this->postJson("/api/v1/admin/referrals/{$referral}/status", ['status' => 'waiting-requirements'])->assertOk();

        // The one loop in the lifecycle, and it exists because families routinely come back with
        // the missing paper.
        $this->postJson("/api/v1/admin/referrals/{$referral}/status", ['status' => 'in-progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in-progress');
    }

    // ── the citizen view ──────────────────────────────────────────────────────────────

    #[Test]
    public function an_applicant_sees_a_status_and_never_the_reason_or_the_notes(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->admin());
        $referral = $this->draftFor($resident, [
            'reason' => 'Suspected neglect by the household head.',
        ]);
        $this->recordAuthority($referral);
        $this->postJson("/api/v1/admin/referrals/{$referral}/send")->assertOk();
        $this->postJson("/api/v1/admin/referrals/{$referral}/notes", [
            'audience' => 'internal',
            'body' => 'Worker has doubts about the account given.',
        ])->assertCreated();

        Sanctum::actingAs($account);
        $body = $this->getJson('/api/v1/me/referrals')->assertOk()->content();

        /*
         * The reason is written for a receiving office in clinical terms a family should not meet
         * as a JSON field, and the internal note is this office talking to itself about them.
         */
        $this->assertStringNotContainsString('Suspected neglect', $body);
        $this->assertStringNotContainsString('doubts', $body);
        $this->assertStringNotContainsString('destination_contact', $body);
        $this->assertStringNotContainsString('outcome', $body);

        $entry = json_decode($body, true)['data']['referrals'][0];
        $this->assertSame('referred', $entry['status']);
        $this->assertArrayHasKey('reference', $entry);
    }

    #[Test]
    public function an_applicant_sees_only_their_own_referrals(): void
    {
        [$account] = $this->activeCitizenWithResident();
        [, $other] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->admin());
        $this->draftFor($other);

        Sanctum::actingAs($account);
        $this->assertCount(0, $this->getJson('/api/v1/me/referrals')->assertOk()->json('data.referrals'));
    }

    // ── the directory ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_provider_with_no_way_to_reach_it_cannot_accept_referrals(): void
    {
        Sanctum::actingAs($this->admin());

        $provider = ServiceProvider::query()->create([
            'name' => 'Nameless partner',
            'destination_type' => 'ngo-partner',
            'status' => 'suspended',
        ]);

        /*
         * "Accepting referrals" with no channel and no contact is the worst state in this table:
         * it invites a worker to route a family somewhere, and produces a referral nobody can
         * follow up.
         */
        $this->postJson("/api/v1/admin/service-providers/{$provider->uuid}/status", ['status' => 'active'])
            ->assertStatus(409);
    }

    #[Test]
    public function a_referral_cannot_be_sent_to_an_office_that_is_not_accepting_them(): void
    {
        Sanctum::actingAs($this->admin());

        $provider = $this->provider();
        $this->postJson("/api/v1/admin/service-providers/{$provider}/status", ['status' => 'suspended'])->assertOk();

        $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) $this->client()->uuid,
            'provider_id' => $provider,
            'service_requested' => 'Assessment',
            'reason' => 'Needs help.',
        ])->assertStatus(409);
    }

    #[Test]
    public function the_destination_is_snapshotted_so_a_renamed_office_does_not_rewrite_history(): void
    {
        Sanctum::actingAs($this->admin());

        $provider = $this->provider();
        $referral = $this->postJson('/api/v1/admin/referrals', [
            'resident_id' => (string) $this->client()->uuid,
            'provider_id' => $provider,
            'service_requested' => 'Assessment',
            'reason' => 'Needs help.',
        ])->assertCreated()->json('data');

        $this->assertSame('Taytay District Hospital', $referral['destination_name']);

        $this->patchJson("/api/v1/admin/service-providers/{$provider}", [
            'name' => 'Rizal Provincial Hospital (renamed)',
        ])->assertOk();

        // A referral is a record of what was sent, to whom, on a date. It must still say where it
        // actually went.
        $this->assertSame(
            'Taytay District Hospital',
            $this->getJson("/api/v1/admin/referrals/{$referral['id']}")->assertOk()->json('data.destination_name'),
        );
    }

    #[Test]
    public function directory_changes_are_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $provider = $this->provider();

        // An entry whose contact details are quietly edited redirects every referral that
        // follows, and leaves no trace anywhere else.
        $this->assertDatabaseHas('audit_entries', [
            'action' => 'provider.created',
            'entity_id' => $provider,
        ]);
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_holds_no_referral_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/referrals')->assertForbidden();
        $this->postJson('/api/v1/admin/referrals', [])->assertForbidden();
        $this->getJson('/api/v1/admin/service-providers')->assertForbidden();
    }

    #[Test]
    public function referral_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/referrals')->assertUnauthorized();
        $this->getJson('/api/v1/me/referrals')->assertUnauthorized();
        $this->getJson('/api/v1/admin/service-providers')->assertUnauthorized();
    }

    #[Test]
    public function only_a_directory_manager_may_edit_the_directory(): void
    {
        Sanctum::actingAs($this->staff());

        // Front-line staff read the directory to prepare referrals; maintaining it is not theirs.
        $this->getJson('/api/v1/admin/service-providers')->assertOk();

        $this->postJson('/api/v1/admin/service-providers', [
            'name' => 'New partner',
            'destination_type' => 'ngo-partner',
            'services_offered' => ['counselling'],
            'channels' => ['phone'],
        ])->assertForbidden();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    private function client(): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => 'Cli'.$n,
            'middle_name' => null,
            'last_name' => 'Ent',
            'birth_date' => '1985-03-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
            'street_address' => $n.' Sampaguita Street',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftFor(Resident $resident, array $overrides = []): string
    {
        return $this->postJson('/api/v1/admin/referrals', $overrides + [
            'resident_id' => (string) $resident->uuid,
            'destination_name' => 'District hospital',
            'destination_type' => 'hospital-msw',
            'service_requested' => 'Medical social work assessment',
            'reason' => 'Unable to meet the hospital bill.',
        ])->assertCreated()->json('data.id');
    }

    private function recordAuthority(
        string $referral,
        string $basis = 'client-consent',
        string $note = 'Told which office would receive this, and agreed.',
    ): void {
        $this->postJson("/api/v1/admin/referrals/{$referral}/authority", [
            'basis' => $basis,
            'note' => $note,
        ])->assertOk();
    }

    private function provider(): string
    {
        return $this->postJson('/api/v1/admin/service-providers', [
            'name' => 'Taytay District Hospital',
            'destination_type' => 'hospital-msw',
            'services_offered' => ['medical social work', 'bill reduction'],
            'channels' => ['phone', 'letter'],
            'contact_person' => 'Ms Reyes',
            'contact_phone' => '8123-4567',
            'usual_response_days' => 5,
        ])->assertCreated()->json('data.id');
    }

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'medical',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }
}
