<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AccessControl\Domain\Role;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Welfare\Infrastructure\Eloquent\CaseAssignment;
use Modules\Welfare\Infrastructure\Eloquent\CaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 11, as tests.
 *
 * The three this file exists for:
 *
 *  1. **Invalid state transitions are rejected server-side** — and rejected *before* the
 *     permission check, so the error cannot be used to map the authorization table.
 *  2. **Every material transition is auditable** — actor, timestamp and reason, immutably.
 *  3. **A citizen cannot infer internal case notes from any payload.** The projection is
 *     additive: it lists what may be shown rather than removing what may not.
 */
final class WelfareCaseTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the state machine ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_illegal_transition_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->openCase();

        // draft cannot jump to approved. The transition map is the authority.
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function legality_is_checked_before_permission(): void
    {
        Sanctum::actingAs($this->staff());
        $case = $this->openCase();

        // This actor holds intake and assess, but nothing that could reach `released`.
        $viewer = Account::factory()->staff()->create();
        $this->grantRole($viewer, 'lgu_staff');
        Sanctum::actingAs($viewer);

        /*
         * The transition is illegal AND the actor could not perform it anyway. It must answer
         * 409, not 403: if permission were checked first, a caller could watch which error
         * comes back and map who holds what from outside (contract matrix §5).
         */
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'released'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function a_legal_transition_still_needs_the_targets_permission(): void
    {
        $case = $this->caseAt('endorsed');

        // lgu_staff may endorse but holds no approval right at all — quite apart from the
        // per-case separation-of-duties rule tested below.
        Sanctum::actingAs($this->staff());

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'approved'])
            ->assertForbidden();
    }

    #[Test]
    public function a_decision_that_will_be_questioned_later_must_carry_its_reason(): void
    {
        $case = $this->caseAt('intake-review');
        Sanctum::actingAs($this->admin());

        // An unexplained rejection is indistinguishable after the fact from an arbitrary one,
        // and it is the applicant who bears that.
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'rejected'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", [
            'to' => 'rejected',
            'reason' => 'Income exceeds the AICS threshold; referred to livelihood programme.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    #[Test]
    public function every_transition_is_recorded_with_actor_and_reason(): void
    {
        $case = $this->caseAt('intake-review');
        $model = WelfareCase::query()->where('uuid', $case)->firstOrFail();

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", [
            'to' => 'returned',
            'reason' => 'Barangay certificate missing.',
        ])->assertOk();

        $transition = CaseTransition::query()
            ->where('welfare_case_id', $model->id)
            ->where('to_status', 'returned')
            ->firstOrFail();

        $this->assertSame('intake-review', $transition->from_status);
        $this->assertSame('Barangay certificate missing.', $transition->reason);
        $this->assertSame((string) $admin->uuid, (string) $transition->actor_subject_id);
        $this->assertNotNull($transition->occurred_at);
    }

    #[Test]
    public function a_terminal_case_accepts_no_further_transitions(): void
    {
        $case = $this->caseAt('intake-review');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", [
            'to' => 'rejected',
            'reason' => 'Duplicate request.',
        ])->assertOk();

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'assessment'])
            ->assertStatus(409);
    }

    #[Test]
    public function the_case_reports_the_transitions_the_server_will_actually_allow(): void
    {
        $case = $this->caseAt('intake-review');
        Sanctum::actingAs($this->admin());

        $available = $this->getJson("/api/v1/admin/cases/{$case}")
            ->assertOk()->json('data.available_transitions');

        // The client renders what it is told rather than deciding for itself (ADR 0007 §4).
        sort($available);
        $this->assertSame(['assessment', 'cancelled', 'rejected', 'returned'], $available);
    }

    // ── separation of duties ──────────────────────────────────────────────────────────

    #[Test]
    public function the_person_who_endorsed_a_case_may_not_approve_it(): void
    {
        $case = $this->caseAt('assessment');

        // One actor holding both permissions — the situation a role-level check alone would
        // wave through.
        $both = Account::factory()->staff()->create();
        $this->grantRole($both, 'lgu_staff');
        $this->grantRole($both, 'lgu_admin');
        Sanctum::actingAs($both);

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'endorsed'])->assertOk();

        /*
         * Approving one's own recommendation is the single-signature path that every audit of
         * a benefits programme looks for first. Enforced per case AND actor, not per role.
         */
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'approved'])
            ->assertForbidden();

        // A different approver is fine.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'approved'])->assertOk();
    }

    #[Test]
    public function no_role_holds_both_approval_and_release(): void
    {
        // Contract matrix §5, asserted over this backend's own catalog rather than inherited
        // from the client's copy of the rule. TAB 18 builds release against a role that holds
        // release and not approve.
        foreach (['resident', 'verifier', 'lgu_staff', 'lgu_admin', 'security_officer'] as $role) {
            $permissions = Role::permissionsFor([$role]);

            $this->assertFalse(
                in_array('request.approve', $permissions, true) && in_array('request.release', $permissions, true),
                "Role `{$role}` may both approve a case and release its money.",
            );
        }
    }

    // ── assignment ────────────────────────────────────────────────────────────────────

    #[Test]
    public function reassignment_closes_the_previous_holder_and_keeps_the_history(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->openCase();
        $model = WelfareCase::query()->where('uuid', $case)->firstOrFail();

        $first = Account::factory()->staff()->create();
        $second = Account::factory()->staff()->create();

        $this->postJson("/api/v1/admin/cases/{$case}/assignment", [
            'assignee_subject_id' => (string) $first->uuid,
        ])->assertOk();

        $this->postJson("/api/v1/admin/cases/{$case}/assignment", [
            'assignee_subject_id' => (string) $second->uuid,
            'team' => 'MSWDO field unit',
        ])->assertOk()->assertJsonPath('data.assigned_to', (string) $second->uuid);

        $history = CaseAssignment::query()->where('welfare_case_id', $model->id)->orderBy('id')->get();

        // "Who was responsible on the 12th" is a different question from "what state was it
        // in", and the one asked first when something has gone wrong.
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->unassigned_at);
        $this->assertNull($history[1]->unassigned_at);
    }

    #[Test]
    public function reassigning_to_the_current_holder_does_not_churn_the_history(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->openCase();
        $model = WelfareCase::query()->where('uuid', $case)->firstOrFail();
        $worker = Account::factory()->staff()->create();

        $this->postJson("/api/v1/admin/cases/{$case}/assignment", ['assignee_subject_id' => (string) $worker->uuid])->assertOk();
        $this->postJson("/api/v1/admin/cases/{$case}/assignment", ['assignee_subject_id' => (string) $worker->uuid])->assertOk();

        $this->assertSame(1, CaseAssignment::query()->where('welfare_case_id', $model->id)->count());
    }

    #[Test]
    public function a_closed_case_cannot_be_assigned(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->caseAt('intake-review');
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", [
            'to' => 'cancelled',
            'reason' => 'Applicant withdrew at the counter.',
        ])->assertOk();

        // Otherwise "my cases" fills with work that will never happen.
        $this->postJson("/api/v1/admin/cases/{$case}/assignment", [
            'assignee_subject_id' => (string) Account::factory()->staff()->create()->uuid,
        ])->assertStatus(409);
    }

    // ── priority is a human judgement, not a derived score ────────────────────────────

    #[Test]
    public function raising_a_case_to_urgent_requires_a_reason(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->openCase();

        // Moving somebody ahead of everyone else waiting needs a name against it.
        $this->postJson("/api/v1/admin/cases/{$case}/priority", ['priority' => 'urgent'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/cases/{$case}/priority", [
            'priority' => 'urgent',
            'reason' => 'No shelter tonight; two infants in the household.',
        ])->assertOk()->assertJsonPath('data.priority', 'urgent');
    }

    #[Test]
    public function the_vulnerability_score_does_not_set_or_change_case_priority(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $resident = $this->existingResident(['first_name' => 'Vul', 'middle_name' => null, 'last_name' => 'Case']);
        $case = $this->openCase($resident);

        // Load the resident up with factors that would score critical.
        foreach (['pwd', 'pregnant', 'solo-parent', 'livelihood-shock'] as $code) {
            $this->postJson("/api/v1/admin/residents/{$resident->uuid}/vulnerability-factors", [
                'factor_code' => $code,
                'severity' => 'critical',
            ])->assertCreated();
        }

        $this->getJson("/api/v1/admin/residents/{$resident->uuid}/vulnerability")
            ->assertOk()
            ->assertJsonPath('data.band', 'critical');

        /*
         * The case is untouched. The score is placeholder weights awaiting MSWDO approval
         * (gap G-20) and declares itself decision-support-only; wiring it into queue order
         * would make an unapproved ordering consequential, and would do it invisibly
         * (ADR 0016 §4).
         */
        $payload = $this->getJson("/api/v1/admin/cases/{$case}")->assertOk()->json('data');

        $this->assertSame('normal', $payload['priority']);
        // Nor is a snapshot embedded in the case file, which would make it read as case data
        // rather than as something a worker chose to consult.
        $this->assertArrayNotHasKey('vulnerability', $payload);
        $this->assertArrayNotHasKey('vulnerability_score', $payload);
    }

    // ── the citizen projection ────────────────────────────────────────────────────────

    #[Test]
    public function internal_stages_collapse_to_one_citizen_status(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $case = $this->caseAt('assessment', $resident);

        Sanctum::actingAs($account);
        $this->getJson("/api/v1/me/cases/{$case}")
            ->assertOk()
            ->assertJsonPath('data.status', 'under-review');

        // Endorsement is the social worker's, not the admin's.
        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => 'endorsed'])->assertOk();

        // `assessment` and `endorsed` both read as `under-review`: which desk holds the file
        // would let the applicant infer the handling social worker.
        Sanctum::actingAs($account);
        $this->getJson("/api/v1/me/cases/{$case}")
            ->assertOk()
            ->assertJsonPath('data.status', 'under-review');
    }

    #[Test]
    public function the_citizen_payload_never_carries_the_internal_reason(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $case = $this->caseAt('intake-review', $resident);
        Sanctum::actingAs($this->admin());

        $internal = 'Claimant account inconsistent with neighbour statements; possible double claim.';

        $this->postJson("/api/v1/admin/cases/{$case}/transitions", [
            'to' => 'rejected',
            'reason' => $internal,
            'applicant_message' => 'We were unable to approve this request at this time.',
        ])->assertOk();

        Sanctum::actingAs($account);
        $body = $this->getJson("/api/v1/me/cases/{$case}")->assertOk()->getContent();

        // The single most consequential leak this design prevents.
        $this->assertStringNotContainsString('neighbour', $body);
        $this->assertStringNotContainsString('double claim', $body);
        $this->assertStringContainsString('unable to approve', $body);
    }

    #[Test]
    public function the_citizen_payload_omits_staff_operational_fields(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $case = $this->caseAt('intake-review', $resident);
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/cases/{$case}/priority", [
            'priority' => 'urgent',
            'reason' => 'Internal triage note.',
        ])->assertOk();

        Sanctum::actingAs($account);
        $payload = $this->getJson("/api/v1/me/cases/{$case}")->assertOk()->json('data');

        // Additive projection: a field is absent until somebody decides it belongs.
        foreach (['priority', 'priority_reason', 'assigned_to', 'needs_home_visit', 'is_escalated', 'opened_by', 'barangay_id'] as $field) {
            $this->assertArrayNotHasKey($field, $payload);
        }
    }

    #[Test]
    public function the_citizen_timeline_shows_only_events_written_for_the_applicant(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $case = $this->caseAt('intake-review', $resident);
        Sanctum::actingAs($this->admin());

        // A staff-only event: priority changes carry no citizen message.
        $this->postJson("/api/v1/admin/cases/{$case}/priority", [
            'priority' => 'high',
        ])->assertOk();

        Sanctum::actingAs($account);
        $timeline = $this->getJson("/api/v1/me/cases/{$case}")->assertOk()->json('data.timeline');

        foreach ($timeline as $entry) {
            $this->assertNotNull($entry['message']);
            $this->assertStringNotContainsString('Priority', (string) $entry['message']);
        }
    }

    #[Test]
    public function a_citizen_may_cancel_their_own_request_only_while_the_state_allows(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();

        $case = $this->caseAt('intake-review', $resident);

        Sanctum::actingAs($account);
        $this->postJson("/api/v1/me/cases/{$case}/cancel", ['reason' => 'No longer needed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Once a social worker is spending time on a file, withdrawing it is a conversation
        // with the office, not a button.
        $second = $this->caseAt('assessment', $resident);

        Sanctum::actingAs($account);
        $this->postJson("/api/v1/me/cases/{$second}/cancel", ['reason' => 'Changed my mind.'])
            ->assertForbidden();
    }

    #[Test]
    public function a_citizen_cannot_reach_another_applicants_case(): void
    {
        [$mine, $myResident] = $this->activeCitizenWithResident();
        [$theirs] = $this->activeCitizenWithResident();

        $case = $this->caseAt('intake-review', $myResident);

        // Ownership is part of the lookup, not a check after it — so another applicant's id
        // resolves to nothing rather than to a 403 that confirms it exists.
        Sanctum::actingAs($theirs);
        $this->getJson("/api/v1/me/cases/{$case}")->assertNotFound();
        $this->postJson("/api/v1/me/cases/{$case}/cancel", ['reason' => 'probe'])->assertNotFound();
    }

    // ── restricted casework ───────────────────────────────────────────────────────────

    #[Test]
    public function a_protective_case_is_invisible_without_the_sensitive_permission(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->openCase(null, 'protective');

        Sanctum::actingAs($this->staff());

        // Knowing a protection case exists for a named person is most of the disclosure, so
        // this is 404 rather than 403, and it is absent from the list and the count.
        $this->getJson("/api/v1/admin/cases/{$case}")->assertNotFound();

        $this->getJson('/api/v1/admin/cases')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0);
    }

    #[Test]
    public function opening_a_protective_case_requires_the_sensitive_permission(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->existingResident(['first_name' => 'Pro', 'middle_name' => null, 'last_name' => 'Tected']);

        // Opening a protection case is itself a protection decision.
        $this->postJson('/api/v1/admin/cases', [
            'resident_id' => (string) $resident->uuid,
            'type' => 'protective',
        ])->assertForbidden();
    }

    // ── scope and authentication ──────────────────────────────────────────────────────

    #[Test]
    public function a_case_outside_the_callers_barangay_reads_as_not_found(): void
    {
        Sanctum::actingAs($this->admin());

        $other = $this->existingResident([
            'first_name' => 'Else', 'middle_name' => null, 'last_name' => 'Where',
            'barangay_id' => $this->otherBarangayId(),
        ]);
        $case = $this->openCase($other);

        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_admin', $this->barangayId());
        Sanctum::actingAs($clerk);

        $this->getJson("/api/v1/admin/cases/{$case}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/admin/cases')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function case_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/cases')->assertUnauthorized();
        $this->getJson('/api/v1/me/cases')->assertUnauthorized();
    }

    #[Test]
    public function a_citizen_holds_no_staff_case_capability(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/admin/cases')->assertForbidden();
        $this->postJson('/api/v1/admin/cases', [])->assertForbidden();
    }

    #[Test]
    public function opening_a_case_file_is_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $case = $this->openCase();

        $this->getJson("/api/v1/admin/cases/{$case}")->assertOk();

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'case.viewed',
            'entity_id' => $case,
            'actor_subject_id' => (string) $admin->uuid,
        ]);
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

    /**
     * Opens a case and returns its uuid. Requires a signed-in actor with `request.create`.
     */
    private function openCase(?Resident $resident = null, string $type = 'assistance'): string
    {
        static $n = 0;
        $n++;

        $resident ??= $this->existingResident([
            'first_name' => 'Case'.$n,
            'middle_name' => null,
            'last_name' => 'Subject',
            'birth_date' => '1988-02-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT),
        ]);

        return $this->postJson('/api/v1/admin/cases', [
            'resident_id' => (string) $resident->uuid,
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    /**
     * Drives a case through the machine to a given state.
     *
     * Signed in as `lgu_staff` throughout, because that is the role which actually holds
     * intake, assess and endorse. `lgu_admin` deliberately does NOT hold endorse — the MSWDO
     * head approves what social workers recommend rather than writing the recommendation and
     * then signing it — so driving the fixture as an admin would fail at `endorsed`, which is
     * separation of duties working rather than a broken helper.
     *
     * Leaves the caseworker signed in. Each test signs in as whoever it needs afterwards.
     */
    private function caseAt(string $status, ?Resident $resident = null): string
    {
        Sanctum::actingAs($this->staff());

        $case = $this->openCase($resident);

        $path = [
            'submitted' => ['submitted'],
            'intake-review' => ['submitted', 'intake-review'],
            'assessment' => ['submitted', 'intake-review', 'assessment'],
            'endorsed' => ['submitted', 'intake-review', 'assessment', 'endorsed'],
        ][$status] ?? [];

        foreach ($path as $step) {
            $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => $step])->assertOk();
        }

        return $case;
    }
}
