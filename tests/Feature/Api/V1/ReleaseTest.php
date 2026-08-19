<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 18, as tests.
 *
 *  1. **A retry cannot create a duplicate release.**
 *  2. **A released record traces to an approved case, programme and beneficiary.**
 *  3. **Money is stored as fixed-precision — integer centavos, never a float.**
 *
 * Plus the control the master command asks for in prose and which is the one that catches a
 * deliberate misuse rather than an accident: the person who approved the case may not release its
 * money.
 */
final class ReleaseTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: a retry cannot duplicate ─────────────────────────────────────────

    #[Test]
    public function replaying_a_confirmation_with_the_same_key_does_not_release_twice(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());

        $key = (string) Str::uuid7();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/releases/{$release}/confirmation", [
                'acknowledged_by_name' => 'Maria Santos',
                'acknowledgement_method' => 'signature',
            ])->assertOk()->json('data');

        /*
         * A payout table has a weak connection and a queue behind it. The client retries, and
         * without the key this second request finds a `released` record and errors — or worse,
         * on a different shape, records a second release for a family that received once.
         */
        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/releases/{$release}/confirmation", [
                'acknowledged_by_name' => 'Maria Santos',
                'acknowledgement_method' => 'signature',
            ])->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertSame(1, Release::query()->where('status', 'released')->count());

        // And exactly one movement was recorded, not two.
        $this->assertSame(1, DB::table('release_transitions')->where('to_status', 'released')->count());
    }

    #[Test]
    public function a_second_confirmation_without_a_key_is_refused_by_the_state_machine(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());

        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertOk();

        /*
         * The key protects a retry; this protects two staff at two tables at the same
         * distribution, each holding their own key. Both load the record showing `ready` and both
         * click — the row lock and the re-read inside the transaction mean only one wins.
         */
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertStatus(409);

        $this->assertSame(1, DB::table('release_transitions')->where('to_status', 'released')->count());
    }

    #[Test]
    public function one_case_cannot_hold_two_releases_of_the_same_instalment(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();

        $first = $this->prepare($case);
        $second = $this->prepare($case);

        // A schedule is legitimate — that is sequence 1 and 2 — but two rows claiming to be the
        // same instalment is the shape a double payment takes, and the unique key forbids it.
        $this->assertSame(1, Release::query()->where('uuid', $first)->value('sequence'));
        $this->assertSame(2, Release::query()->where('uuid', $second)->value('sequence'));

        $this->expectException(QueryException::class);

        Release::query()->create([
            'welfare_case_id' => Release::query()->where('uuid', $first)->value('welfare_case_id'),
            'resident_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'kind' => 'cash',
            'amount_centavos' => 100,
            'release_mode' => 'cash-pickup',
            'status' => 'ready',
        ]);
    }

    // ── criterion 2: the trace ────────────────────────────────────────────────────────

    #[Test]
    public function a_release_can_only_be_prepared_against_an_approved_case(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->client();
        $case = $this->caseFor($resident);

        /*
         * Allowing one against a case still under assessment would let money be scheduled before
         * anybody decided it should be.
         */
        $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'cash',
            'amount_centavos' => 500000,
            'release_mode' => 'cash-pickup',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_release_carries_its_case_beneficiary_programme_and_approver(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();
        $release = $this->prepare($case, ['program_id' => (string) $this->program()->uuid]);

        $body = $this->getJson("/api/v1/admin/releases/{$release}")->assertOk()->json('data');

        $this->assertNotEmpty($body['resident_id']);
        $this->assertSame('AICS', $body['program_code']);
        $this->assertNotEmpty($body['approval_reference']);

        // The approver is snapshotted at preparation, so a later change on the case cannot
        // rewrite who authorised this specific payment.
        $this->assertNotNull(Release::query()->where('uuid', $release)->value('approved_by'));
    }

    // ── criterion 3: money is exact ───────────────────────────────────────────────────

    #[Test]
    public function money_is_stored_as_integer_centavos(): void
    {
        Sanctum::actingAs($this->admin());

        $release = $this->prepare($this->approvedCase(), ['amount_centavos' => 123456]);

        $stored = Release::query()->where('uuid', $release)->value('amount_centavos');

        // Exact, and never through a float — a peso figure that has been through a float is one
        // nobody can reconcile.
        $this->assertSame(123456, (int) $stored);

        $body = $this->getJson("/api/v1/admin/releases/{$release}")->assertOk()->json('data');
        $this->assertSame(123456, $body['amount_centavos']);
        $this->assertSame('PHP', $body['currency']);

        // Never a formatted string: formatting is the client's, and a server that formats money
        // has decided a locale on somebody's behalf.
        $this->assertIsInt($body['amount_centavos']);
    }

    #[Test]
    public function a_cash_release_needs_an_amount_and_an_in_kind_one_must_not_have_one(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();

        $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'cash',
            'release_mode' => 'cash-pickup',
        ])->assertStatus(422);

        /*
         * A relief pack has a notional value, and recording it as an amount would put a peso
         * figure against a family that received rice — which then appears in every total as
         * though cash had been handed over.
         */
        $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'in-kind',
            'in_kind_description' => 'One family food pack',
            'amount_centavos' => 50000,
            'release_mode' => 'in-kind-pickup',
        ])->assertStatus(422);

        $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
            'kind' => 'in-kind',
            'in_kind_description' => 'One family food pack',
            'release_mode' => 'in-kind-pickup',
        ])->assertCreated()->assertJsonPath('data.amount_centavos', null);
    }

    #[Test]
    public function a_manifest_total_counts_cash_only(): void
    {
        Sanctum::actingAs($this->admin());

        $batch = $this->money()->postJson('/api/v1/admin/release-batches', [
            'name' => 'AICS payout, Barangay Dolores',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])->assertCreated()->json('data.id');

        $cash = $this->prepare($this->approvedCase(), ['amount_centavos' => 250000]);
        $inKind = $this->prepare($this->approvedCase(), [
            'kind' => 'in-kind',
            'in_kind_description' => 'One family food pack',
            'release_mode' => 'in-kind-pickup',
            'amount_centavos' => null,
        ]);

        foreach ([$cash, $inKind] as $release) {
            $this->money()->postJson("/api/v1/admin/release-batches/{$batch}/releases", ['release_id' => $release])
                ->assertOk();
        }

        $manifest = $this->getJson("/api/v1/admin/release-batches/{$batch}/manifest")->assertOk()->json('data');

        $this->assertSame(2, $manifest['total_count']);
        // The food pack contributes nothing to a peso total.
        $this->assertSame(250000, $manifest['total_cash_centavos']);
    }

    // ── segregation of duties ─────────────────────────────────────────────────────────

    #[Test]
    public function the_person_who_approved_the_case_cannot_release_its_money(): void
    {
        $approver = $this->admin();

        Sanctum::actingAs($approver);
        $release = $this->prepare($this->approvedCase($approver));

        /*
         * Two roles is the design; ONE PERSON HOLDING BOTH is the failure, and it arrives the
         * moment somebody is granted a second role to cover a colleague's leave. So the check is
         * on the person, not only on the permission.
         */
        $this->grantRole($approver, 'disbursing_officer', $this->barangayId());
        Sanctum::actingAs($approver->refresh());

        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertForbidden();

        // Anybody else with the role may.
        Sanctum::actingAs($this->disburser());
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertOk();
    }

    #[Test]
    public function an_approver_holds_no_release_permission_by_default(): void
    {
        Sanctum::actingAs($this->admin());

        $release = $this->prepare($this->approvedCase());

        // `lgu_admin` approves and does not release. Until TAB 18 nobody held `request.release`
        // at all, which was correct while there was nothing to release.
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertForbidden();
    }

    #[Test]
    public function a_disbursing_officer_cannot_approve_a_case_or_prepare_a_release_alone(): void
    {
        // A case sitting at `endorsed` — genuinely awaiting approval, so the permission check is
        // what refuses rather than the state machine.
        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($this->client());

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($this->disburser());

        // They may see what was approved — somebody handing over a payment has to.
        $this->getJson('/api/v1/admin/releases')->assertOk();

        /*
         * But a disbursing officer who could also approve would be a single signature between an
         * empty case file and money leaving the building.
         */
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])->assertForbidden();
        $this->postJson('/api/v1/admin/enrollments', [])->assertForbidden();
    }

    // ── the lifecycle ─────────────────────────────────────────────────────────────────

    #[Test]
    public function released_and_completed_are_different_claims(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])
            ->assertOk()->assertJsonPath('data.status', 'released');

        /*
         * "We handed it over" and "they have it" are not the same claim, and only one is ever
         * true first — a cheque given to a relative who has not confirmed, a transfer sent but
         * not landed.
         */
        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    #[Test]
    public function a_released_record_cannot_be_rewound(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertOk();

        // Money has moved. A release sent in error is completed and then corrected by a new
        // record, never un-moved by a status change.
        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", [
            'status' => 'cancelled',
            'reason' => 'Sent in error.',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_failed_release_returns_to_the_queue_because_the_family_is_still_owed(): void
    {
        Sanctum::actingAs($this->admin());
        $release = $this->prepare($this->approvedCase());

        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", [
            'status' => 'failed',
            'reason' => 'Beneficiary did not attend the payout.',
        ])->assertOk();

        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", ['status' => 'ready'])
            ->assertOk()->assertJsonPath('data.status', 'ready');
    }

    #[Test]
    public function every_outcome_that_is_not_the_happy_path_must_say_why(): void
    {
        Sanctum::actingAs($this->admin());
        $release = $this->prepare($this->approvedCase());

        // A failed release with no reason is indistinguishable from one nobody attempted, and the
        // family is owed an answer either way.
        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", ['status' => 'failed'])
            ->assertStatus(422);
    }

    #[Test]
    public function who_actually_collected_is_recorded(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());

        // Frequently not the beneficiary: an elderly person sends a daughter, a bedridden patient
        // sends a neighbour. Recording only "released" loses the fact a dispute turns on.
        $body = $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [
            'acknowledged_by_name' => 'Ana Cruz',
            'acknowledged_relationship' => 'daughter',
            'acknowledgement_method' => 'signature',
        ])->assertOk()->json('data');

        $this->assertSame('Ana Cruz', $body['acknowledged_by_name']);
        $this->assertSame('daughter', $body['acknowledged_relationship']);
        $this->assertSame('signature', $body['acknowledgement_method']);
    }

    #[Test]
    public function no_biometric_is_stored(): void
    {
        $release = $this->preparedRelease();

        Sanctum::actingAs($this->disburser());
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [
            'acknowledgement_method' => 'thumbmark',
        ])->assertOk();

        /*
         * The METHOD only. The mark itself stays on the paper manifest — a thumbprint image in
         * this database would be biometric data held for a purpose that does not need it.
         */
        foreach (array_keys(Release::query()->where('uuid', $release)->firstOrFail()->getAttributes()) as $column) {
            $this->assertStringNotContainsStringIgnoringCase('image', $column);
            $this->assertStringNotContainsStringIgnoringCase('signature_data', $column);
            $this->assertStringNotContainsStringIgnoringCase('biometric', $column);
        }
    }

    // ── the history TAB 14 left a slot for ────────────────────────────────────────────

    #[Test]
    public function assistance_history_now_reports_what_was_actually_released(): void
    {
        $release = $this->preparedRelease(250000);

        Sanctum::actingAs($this->disburser());
        $this->money()->postJson("/api/v1/admin/releases/{$release}/confirmation", [])->assertOk();

        $residentId = (string) Release::query()->where('uuid', $release)->value('resident_id');

        Sanctum::actingAs($this->admin());
        $granted = $this->getJson("/api/v1/admin/residents/{$residentId}/assistance-history")
            ->assertOk()->json('data.granted.0');

        // The slot TAB 14 published as null. The shape did not have to change, which was the
        // point of leaving it present.
        $this->assertSame(250000, $granted['released_amount_centavos']);
        $this->assertSame('PHP', $granted['currency']);
    }

    #[Test]
    public function history_reports_nothing_released_when_a_payout_failed(): void
    {
        Sanctum::actingAs($this->admin());
        $case = $this->approvedCase();
        $release = $this->prepare($case, ['amount_centavos' => 250000]);

        $this->money()->postJson("/api/v1/admin/releases/{$release}/status", [
            'status' => 'failed',
            'reason' => 'Beneficiary did not attend.',
        ])->assertOk();

        $residentId = (string) Release::query()->where('uuid', $release)->value('resident_id');

        $granted = $this->getJson("/api/v1/admin/residents/{$residentId}/assistance-history")
            ->assertOk()->json('data.granted.0');

        /*
         * Summed from releases that HAPPENED, not from what was approved. Reporting the approved
         * figure would tell a family they were given money they never saw.
         */
        $this->assertSame(0, $granted['released_amount_centavos']);
    }

    // ── authorization ─────────────────────────────────────────────────────────────────

    #[Test]
    public function release_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/releases')->assertUnauthorized();
        $this->postJson('/api/v1/admin/release-batches', [])->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_reaches_none_of_this(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/releases')->assertForbidden();
        $this->postJson('/api/v1/admin/release-batches', [])->assertForbidden();
    }

    #[Test]
    public function a_release_says_who_approved_it_and_whether_that_is_you(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $case = $this->approvedCase($admin);
        $release = $this->prepare($case);

        $mine = $this->getJson("/api/v1/admin/releases/{$release}")->assertOk()->json('data');

        $this->assertSame((string) $admin->uuid, $mine['approved_by']);
        $this->assertTrue($mine['self_release'], 'The screen must be able to warn before the officer commits, not after.');

        Sanctum::actingAs($this->disburser());

        $theirs = $this->getJson("/api/v1/admin/releases/{$release}")->assertOk()->json('data');

        $this->assertFalse($theirs['self_release'], 'Derived per caller — a client comparing identifiers can get it wrong, and the cost is a warning that never appears.');
    }

    #[Test]
    public function a_distribution_run_reports_counts_and_never_a_status_of_its_own(): void
    {
        Sanctum::actingAs($this->admin());

        $batch = $this->money()->postJson('/api/v1/admin/release-batches', [
            'name' => 'Saturday distribution',
            'scheduled_for' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data');

        $listed = $this->getJson('/api/v1/admin/release-batches')->assertOk()->json('data.0');
        $detail = $this->getJson("/api/v1/admin/release-batches/{$batch['id']}")->assertOk()->json('data');

        $this->assertSame($batch['id'], $listed['id']);
        $this->assertSame($batch['id'], $detail['id']);

        // DL-90: "partially complete" hides the two people still waiting.
        foreach (['progress', 'completion', 'percent_complete'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $detail);
        }
    }

    /**
     * TAB 08 step 9. *"A figure nobody can reconcile is a figure nobody trusts."*
     */
    #[Test]
    public function reconciliation_totals_tie_and_count_goods_beside_the_money(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();
        $this->prepare($case, ['amount_centavos' => 250000]);
        $this->prepare($case, ['kind' => 'in-kind', 'in_kind_description' => 'One sack of rice', 'amount_centavos' => null]);

        Sanctum::actingAs($this->disburser());

        $data = $this->getJson('/api/v1/admin/releases/reconciliation')->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['line_count']);
        $this->assertSame(250000, $data['totals']['centavos'], 'An in-kind release contributes nothing to a peso figure.');
        $this->assertSame(1, $data['totals']['in_kind_count'], 'It is counted beside the money rather than summed in as zero.');

        // The parts must add up to the whole, or the view fails at the one job it has.
        $this->assertSame(
            $data['totals']['line_count'],
            array_sum(array_column($data['by_status'], 'line_count')),
        );
        $this->assertSame(
            $data['totals']['centavos'],
            array_sum(array_column($data['by_program'], 'centavos')),
        );
    }

    #[Test]
    public function the_reconciliation_view_is_refused_to_someone_who_may_not_release(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/admin/releases/reconciliation')->assertForbidden();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    // ── TAB 08: money discipline ─────────────────────────────────────────────────────

    #[Test]
    public function every_money_write_refuses_a_request_with_no_idempotency_key(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();

        /*
         * The service treats a missing key as "no protection, carry on" — the right default for an
         * ordinary write, and the wrong one here, because what an unprotected retry produces is a
         * second payout to a real family. So this surface refuses it rather than being silently
         * unprotected: a client that forgets the header finds out on its first request in
         * development, not on a bad connection at a payout table.
         */
        $this->withoutIdempotencyKey()
            ->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
                'kind' => 'cash',
                'amount_centavos' => 500000,
                'release_mode' => 'cash-pickup',
            ])->assertStatus(422);

        $release = $this->prepare($case);

        $this->withoutIdempotencyKey()
            ->postJson("/api/v1/admin/releases/{$release}/status", [
                'status' => 'deferred',
                'reason' => 'Funds had not arrived.',
            ])->assertStatus(422);

        $this->withoutIdempotencyKey()
            ->postJson('/api/v1/admin/release-batches', [
                'name' => 'Saturday distribution',
                'scheduled_for' => now()->addDay()->toDateString(),
            ])->assertStatus(422);
    }

    #[Test]
    public function the_same_key_and_the_same_body_replays_rather_than_scheduling_twice(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();
        $key = (string) Str::uuid7();
        $body = ['kind' => 'cash', 'amount_centavos' => 500000, 'release_mode' => 'cash-pickup'];

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/assistance-requests/{$case}/releases", $body)
            ->assertCreated()->json('data');

        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/assistance-requests/{$case}/releases", $body)
            ->assertSuccessful()->json('data');

        $this->assertSame($first['id'], $second['id'], 'The stored response is replayed; the operation is not run again.');
        $this->assertSame(1, Release::query()->count(), 'One intent, one row.');
    }

    /**
     * The case that matters more than the replay.
     *
     * A client reusing a key with a changed amount is confused or being tampered with, and quietly
     * replaying the old response would tell it a payout of ₱5,000 succeeded when it asked for
     * ₱50,000.
     */
    #[Test]
    public function the_same_key_with_a_different_body_is_refused_rather_than_replayed(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();
        $key = (string) Str::uuid7();

        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
                'kind' => 'cash', 'amount_centavos' => 500000, 'release_mode' => 'cash-pickup',
            ])->assertCreated();

        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/assistance-requests/{$case}/releases", [
                'kind' => 'cash', 'amount_centavos' => 5000000, 'release_mode' => 'cash-pickup',
            ])->assertStatus(409);

        $this->assertSame(1, Release::query()->count());
    }

    /**
     * The attack the command asks for by name: one actor holding both permissions, expecting refusal.
     *
     * Separated permissions do not separate **people**. An administrator holds approval and release
     * by definition, and on a small office's bad day one person is available. So the control cannot
     * be "which permissions does this actor hold" — it has to be "is this the same human who
     * approved it", and only the server can answer that.
     */
    #[Test]
    public function the_approver_of_a_request_cannot_release_its_own_payout(): void
    {
        /*
         * ONE ACCOUNT HOLDING BOTH ROLES, which is the only way to satisfy the command's premise.
         *
         * `lgu_admin` holds approval and NOT release — the role split already prevents the simple
         * case, and `disbursing_officer` exists precisely so that releasing is somebody else's job.
         * So the attack has to be an office that grants one person both roles, which is exactly
         * what a small office on a bad day does.
         *
         * That is why the control cannot be "which permissions does this actor hold". It has to be
         * "is this the same human who approved it", and only the server can answer that.
         */
        $both = Account::factory()->staff()->create();
        $this->grantRole($both, 'lgu_admin', $this->barangayId());
        $this->grantRole($both, 'disbursing_officer', $this->barangayId());

        Sanctum::actingAs($both);

        $case = $this->approvedCase($both);
        $release = $this->prepare($case);

        $refusal = $this->money()
            ->postJson("/api/v1/admin/releases/{$release}/confirmation", [])
            ->assertForbidden();

        /*
         * The command also asks that the refusal be legible, not just correct. A disbursing officer
         * who is told "forbidden" reaches for the administrator; one who is told they approved this
         * themselves goes and finds a colleague, which is the outcome the rule wants.
         */
        $this->assertStringContainsString('approved', (string) $refusal->json('error.message'));

        $this->assertSame(0, DB::table('release_transitions')->where('to_status', 'released')->count());
    }

    /**
     * The command: *"the API must refuse it rather than storing a blank."*
     *
     * A blank reason is worse than a missing one, because it reads later as though somebody
     * answered.
     */
    #[Test]
    public function a_state_that_requires_a_reason_refuses_a_blank_one(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->approvedCase();
        $release = $this->prepare($case);

        $this->money()
            ->postJson("/api/v1/admin/releases/{$release}/status", ['status' => 'deferred', 'reason' => '   '])
            ->assertStatus(422);

        $this->money()
            ->postJson("/api/v1/admin/releases/{$release}/status", ['status' => 'deferred'])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('release_transitions')->where('to_status', 'deferred')->count());
    }

    /**
     * The command: *"Test a value that a float would round wrongly."*
     *
     * ₱1,234,567.89 is 123456789 centavos. Through a float, 1234567.89 × 100 is 123456788.99999999,
     * and truncation makes that 123456788 — one centavo short, on a figure a disbursing officer
     * will be asked to reconcile.
     */
    #[Test]
    public function a_peso_figure_never_passes_through_a_float(): void
    {
        $centavos = 123456789;

        $release = $this->preparedRelease($centavos);

        $stored = (int) DB::table('releases')->where('uuid', $release)->value('amount_centavos');

        $this->assertSame($centavos, $stored);
        $this->assertSame($centavos, (int) $this->getJson("/api/v1/admin/releases/{$release}")->assertOk()->json('data.amount_centavos'));

        // What the naive path would have produced, kept as the thing being avoided.
        $this->assertNotSame($centavos, (int) ((float) ($centavos / 100) * 100));
    }

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    /**
     * Memoised per test, not per process: a `static` here would survive RefreshDatabase's
     * rollback and hand the next test an Account whose row no longer exists.
     */
    private ?Account $disburser = null;

    private function disburser(): Account
    {
        if ($this->disburser === null) {
            $this->disburser = Account::factory()->staff()->create();
            $this->grantRole($this->disburser, 'disbursing_officer', $this->barangayId());
        }

        return $this->disburser;
    }

    private function client(): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => 'Rel'.$n,
            'middle_name' => null,
            'last_name' => 'Ease',
            'birth_date' => '1976-09-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
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
            ['program_id' => $program->id, 'code' => 'valid-id', 'template_version' => '1'],
            [
                'label' => 'Valid identification',
                'obligation' => 'required',
                'citizen_instructions' => 'Bring any government-issued ID.',
            ],
        );

        return $program;
    }

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'financial',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }

    /**
     * A case driven to `approved`, respecting separation of duties on the way.
     */
    private function approvedCase(?Account $approver = null): string
    {
        $approver ??= $this->admin();

        Sanctum::actingAs($this->staff());
        $case = $this->caseFor($this->client());

        foreach (['intake-review', 'assessment', 'endorsed'] as $step) {
            $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => $step])->assertOk();
        }

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'approved'])->assertOk();

        return $case;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function prepare(string $case, array $overrides = []): string
    {
        return $this->money()->postJson("/api/v1/admin/assistance-requests/{$case}/releases", array_filter($overrides + [
            'kind' => 'cash',
            'amount_centavos' => 500000,
            'release_mode' => 'cash-pickup',
            'funding_source' => 'MSWDO trust fund',
        ], static fn (mixed $value): bool => $value !== null))->assertCreated()->json('data.id');
    }

    private function preparedRelease(int $centavos = 500000): string
    {
        Sanctum::actingAs($this->admin());

        return $this->prepare($this->approvedCase(), ['amount_centavos' => $centavos]);
    }
}
