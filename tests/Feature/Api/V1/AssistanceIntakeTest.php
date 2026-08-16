<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Welfare\Infrastructure\Eloquent\Assessment;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceDraft;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceIntake;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 12, as tests.
 *
 * The three this file exists for:
 *
 *  1. **The same request originates from citizen web, mobile or the counter without duplicate
 *     domain logic** — one submission path, provenance recorded, rules identical.
 *  2. **A retried mobile submission does not create a duplicate request.**
 *  3. **Recommendation and approval remain distinct** — completing an assessment approves
 *     nothing, and cannot.
 */
final class AssistanceIntakeTest extends KycTestCase
{
    use RefreshDatabase;

    // ── one path, many channels ───────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_submission_and_a_counter_intake_produce_the_same_case_shape(): void
    {
        // Citizen route.
        [$account, $resident] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->openDraft();
        $citizenCase = $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")
            ->assertCreated()->json('data.id');

        // Counter route.
        Sanctum::actingAs($this->staff());
        $counterCase = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $this->otherResident()->uuid,
            'category' => 'food',
            'narrative' => 'Household has no food stocks after the storm.',
        ])->assertCreated()->json('data.case_id');

        $a = WelfareCase::query()->where('uuid', $citizenCase)->firstOrFail();
        $b = WelfareCase::query()->where('uuid', $counterCase)->firstOrFail();

        // Same lifecycle position, same type mapping — the channel is provenance, not a rule.
        $this->assertSame('submitted', $a->status->value);
        $this->assertSame('submitted', $b->status->value);
        $this->assertSame($a->type->value, $b->type->value);

        // …and the provenance itself is preserved, because a clerk who saw the applicant and a
        // form typed at home are different evidential positions.
        $this->assertSame('citizen-web', AssistanceIntake::query()->where('welfare_case_id', $a->id)->value('source'));
        $this->assertSame('walk-in', AssistanceIntake::query()->where('welfare_case_id', $b->id)->value('source'));
    }

    #[Test]
    public function a_citizen_client_cannot_claim_counter_provenance(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        // Sent deliberately. A client asserting `walk-in` would be manufacturing evidence that
        // a clerk saw the person; the source is derived from the channel and nothing else.
        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Need help.',
            'consent_reference' => 'ack-1',
            'source' => 'walk-in',
        ])->assertCreated()->json('data');

        $this->assertSame('citizen-web', $draft['source']);
    }

    #[Test]
    public function a_citizen_cannot_file_a_request_against_another_resident(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        // There is no resident id in this contract at all — it is resolved from the token, so
        // there is nothing to tamper with.
        $draftId = $this->openDraft();
        $draft = AssistanceDraft::query()->where('uuid', $draftId)->firstOrFail();

        $residentId = $this->accountResidentId($account);
        $this->assertSame($residentId, (string) $draft->resident_id);
    }

    // ── drafts ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function opening_a_draft_twice_resumes_the_same_one(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $first = $this->openDraft();
        $second = $this->openDraft();

        // Two open drafts are two half-finished stories about the same need, and the applicant
        // submits whichever one they happen to be looking at.
        $this->assertSame($first, $second);
        $this->assertSame(1, AssistanceDraft::query()->count());
    }

    #[Test]
    public function a_draft_belongs_to_its_owner_alone(): void
    {
        [$mine] = $this->activeCitizenWithResident();
        [$theirs] = $this->activeCitizenWithResident();

        Sanctum::actingAs($mine);
        $draft = $this->openDraft();

        // Ownership is in the WHERE clause, so another caller is answered exactly as if the
        // draft did not exist — a draft holds a narrative not yet chosen for submission.
        Sanctum::actingAs($theirs);
        $this->patchJson("/api/v1/me/assistance/drafts/{$draft}", ['narrative' => 'tampered'])->assertNotFound();
        $this->deleteJson("/api/v1/me/assistance/drafts/{$draft}")->assertNotFound();
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertNotFound();
    }

    #[Test]
    public function an_expired_draft_is_refused_rather_than_silently_resurrected(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->openDraft();
        AssistanceDraft::query()->where('uuid', $draft)->update(['expires_at' => now()->subDay()]);

        // The retention clock is a privacy commitment; a clock that resets whenever somebody
        // returns is not a retention policy.
        $this->patchJson("/api/v1/me/assistance/drafts/{$draft}", ['narrative' => 'resumed'])->assertStatus(409);
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertStatus(409);
    }

    #[Test]
    public function a_self_service_submission_requires_a_privacy_notice_acknowledgement(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Need help with food.',
        ])->assertCreated()->json('data.id');

        /*
         * An unattended submission has no witness. The acknowledgement is the only evidence
         * that RA 10173's transparency obligation was met at all.
         */
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertStatus(422);

        $this->patchJson("/api/v1/me/assistance/drafts/{$draft}", [
            'consent_reference' => 'ack-2026-08',
            'privacy_notice_version' => '1.0',
        ])->assertOk();

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();
    }

    #[Test]
    public function an_incomplete_draft_cannot_be_submitted(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'consent_reference' => 'ack-1',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertStatus(422);
        $this->assertSame(0, WelfareCase::query()->count());
    }

    #[Test]
    public function a_discarded_draft_is_really_deleted(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->openDraft();
        $this->deleteJson("/api/v1/me/assistance/drafts/{$draft}")->assertOk();

        /*
         * A real delete, unusually for this codebase. Nobody acted on it, no decision rests on
         * it, and it holds personal data the applicant explicitly decided not to give —
         * keeping it "for the audit trail" would retain data whose only justification was a
         * request that was never made.
         */
        $this->assertSame(0, AssistanceDraft::query()->count());
    }

    // ── idempotency ───────────────────────────────────────────────────────────────────

    #[Test]
    public function a_retried_submission_with_the_same_key_does_not_create_a_second_case(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->openDraft();

        $first = $this->withHeaders(['Idempotency-Key' => 'mobile-retry-1'])
            ->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")
            ->assertCreated()->json('data');

        // The replay carries the original status too — the client cannot tell it from the
        // first response, which is the whole point.
        $second = $this->withHeaders(['Idempotency-Key' => 'mobile-retry-1'])
            ->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")
            ->assertCreated()->json('data');

        /*
         * The scenario: the request reached the server, the response was lost, the app retried.
         * Without this, the second attempt opens a second case — two files for one person,
         * worked independently, discovered at payout.
         */
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, WelfareCase::query()->count());
        $this->assertSame(1, AssistanceIntake::query()->count());
    }

    #[Test]
    public function reusing_an_idempotency_key_with_a_different_body_is_refused(): void
    {
        Sanctum::actingAs($this->staff());

        $this->withHeaders(['Idempotency-Key' => 'counter-1'])
            ->postJson('/api/v1/admin/assistance-intakes', [
                'resident_id' => (string) $this->otherResident()->uuid,
                'category' => 'food',
                'narrative' => 'First request.',
            ])->assertCreated();

        // A key reused for a different payload is a client bug. Answering it with the old
        // result would silently discard the new request.
        $this->withHeaders(['Idempotency-Key' => 'counter-1'])
            ->postJson('/api/v1/admin/assistance-intakes', [
                'resident_id' => (string) $this->otherResident('Second')->uuid,
                'category' => 'medical',
                'narrative' => 'A different request entirely.',
            ])->assertStatus(409);
    }

    #[Test]
    public function one_callers_idempotency_key_cannot_replay_anothers_response(): void
    {
        Sanctum::actingAs($this->staff());
        $this->withHeaders(['Idempotency-Key' => 'shared-key'])
            ->postJson('/api/v1/admin/assistance-intakes', [
                'resident_id' => (string) $this->otherResident()->uuid,
                'category' => 'food',
                'narrative' => 'First staff request.',
            ])->assertCreated();

        // Scoped by subject: a second caller using the same key gets their own operation, not
        // somebody else's cached response.
        $other = Account::factory()->staff()->create();
        $this->grantRole($other, 'lgu_staff');
        Sanctum::actingAs($other);

        $this->withHeaders(['Idempotency-Key' => 'shared-key'])
            ->postJson('/api/v1/admin/assistance-intakes', [
                'resident_id' => (string) $this->otherResident('Third')->uuid,
                'category' => 'food',
                'narrative' => 'A different caller entirely.',
            ])->assertCreated();

        $this->assertSame(2, WelfareCase::query()->count());
    }

    #[Test]
    public function resubmitting_an_already_submitted_draft_reports_the_case_rather_than_an_error(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $draft = $this->openDraft();
        $case = $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated()->json('data.id');

        /*
         * A client whose response was lost and whose key has since expired is not making a
         * mistake — it is asking what happened. A 409 would leave it showing a failure for a
         * request that in fact succeeded, and the applicant would file again.
         */
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")
            ->assertOk()
            ->assertJsonPath('data.id', $case);

        $this->assertSame(1, WelfareCase::query()->count());
    }

    // ── assessment recommends; it never approves ──────────────────────────────────────

    #[Test]
    public function completing_an_assessment_does_not_move_the_case(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();
        $this->answerAll($case);

        $payload = $this->postJson("/api/v1/admin/cases/{$case}/assessment/complete", [
            'recommendation' => 'recommend-approve',
            'findings' => 'Household verified; no other assistance in the last year.',
        ])->assertOk()->json('data');

        /*
         * The invariant this whole module is arranged around. A recommendation is a
         * professional opinion; an approval commits public money. If completing an assessment
         * moved the case, a social worker's judgement would silently become a decision nobody
         * with approval authority ever made.
         */
        $this->assertSame('endorsed', $payload['suggested_next_status']);
        $this->assertSame(
            'assessment',
            WelfareCase::query()->where('uuid', $case)->firstOrFail()->status->value,
        );
    }

    #[Test]
    public function a_recommendation_to_deny_never_suggests_rejection(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();
        $this->answerAll($case);

        $payload = $this->postJson("/api/v1/admin/cases/{$case}/assessment/complete", [
            'recommendation' => 'recommend-deny',
            'reason' => 'Income above threshold and no dependants recorded.',
        ])->assertOk()->json('data');

        // A refusal is a decision with its own permission and its own mandatory reason. An
        // assessor recommending denial does not make one.
        $this->assertNull($payload['suggested_next_status']);
    }

    #[Test]
    public function a_recommendation_to_deny_must_state_why(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();
        $this->answerAll($case);

        // The applicant will be told a decision followed from this. "The assessor recommended
        // refusal" with no basis is not something anybody can appeal or a supervisor review.
        $this->postJson("/api/v1/admin/cases/{$case}/assessment/complete", [
            'recommendation' => 'recommend-deny',
        ])->assertStatus(422);
    }

    #[Test]
    public function an_assessment_cannot_be_signed_with_required_answers_missing(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();

        /*
         * Months later, an assessment missing its required answers reads exactly like one
         * where the assessor concluded "none" or "no risk". The difference matters when
         * somebody is asking why a case was refused.
         */
        $this->postJson("/api/v1/admin/cases/{$case}/assessment/complete", [
            'recommendation' => 'recommend-approve',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_template_version_is_pinned_at_open_and_survives_a_config_change(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();

        // A later edit to the form must not appear to have altered what this assessment asked.
        config(['assessment.templates.aics-general.version' => '2099.01.1']);

        $this->assertSame(
            '2026.08.1',
            Assessment::query()->orderByDesc('id')->firstOrFail()->template_version,
        );
    }

    #[Test]
    public function a_completed_assessment_cannot_be_edited(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();
        $this->answerAll($case);
        $this->postJson("/api/v1/admin/cases/{$case}/assessment/complete", [
            'recommendation' => 'recommend-approve',
        ])->assertOk();

        // Completed findings are evidence. Editing them would change what an approver was
        // shown when they decided.
        $this->patchJson("/api/v1/admin/cases/{$case}/assessment", [
            'answers' => ['presenting_problem' => 'rewritten after the fact'],
        ])->assertNotFound();
    }

    #[Test]
    public function an_answer_outside_a_choice_list_is_refused(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])->assertCreated();

        $this->patchJson("/api/v1/admin/cases/{$case}/assessment", [
            'answers' => ['immediate_risk' => 'catastrophic'],
        ])->assertStatus(422);
    }

    #[Test]
    public function opening_an_assessment_twice_returns_the_one_in_progress(): void
    {
        $case = $this->caseInAssessment();

        Sanctum::actingAs($this->staff());
        $a = $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])
            ->assertCreated()->json('data.id');
        $b = $this->postJson("/api/v1/admin/cases/{$case}/assessment", ['template_code' => 'aics-general'])
            ->assertCreated()->json('data.id');

        // Two open assessments are two competing sets of findings, and nothing says which the
        // approver should read.
        $this->assertSame($a, $b);
        $this->assertSame(1, Assessment::query()->count());
    }

    // ── prior history is narrow, and staff-only ───────────────────────────────────────

    #[Test]
    public function prior_case_history_carries_no_narratives(): void
    {
        Sanctum::actingAs($this->staff());

        $resident = $this->otherResident('Repeat');

        $first = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'A very private account of our circumstances.',
        ])->assertCreated()->json('data.case_id');

        $second = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'medical',
            'narrative' => 'Another private account.',
        ])->assertCreated()->json('data.case_id');

        $body = $this->getJson("/api/v1/admin/cases/{$second}/prior-cases")->assertOk()->getContent();

        // An assessor needs to know this person has come twice. Reading what they said each
        // time is a separate, separately audited decision.
        $this->assertStringContainsString($first, $body);
        $this->assertStringNotContainsString('very private account', $body);
    }

    #[Test]
    public function a_citizen_cannot_reach_staff_intake_or_assessment(): void
    {
        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/admin/assistance-intakes', [])->assertForbidden();
        $this->getJson('/api/v1/admin/assessment-templates')->assertForbidden();
    }

    #[Test]
    public function intake_and_draft_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/me/assistance/drafts')->assertUnauthorized();
        $this->postJson('/api/v1/admin/assistance-intakes', [])->assertUnauthorized();
    }

    #[Test]
    public function submitting_an_intake_is_audited_without_repeating_the_narrative(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $this->otherResident()->uuid,
            'category' => 'food',
            'narrative' => 'We have not eaten properly since my husband lost his job.',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_entries', ['action' => 'intake.submitted']);

        // A trail repeating the applicant's account becomes a second, less-guarded copy of it.
        $summaries = DB::table('audit_entries')->pluck('summary')->implode(' ');
        $this->assertStringNotContainsString('husband', $summaries);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    private function otherResident(string $name = 'Other'): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => $name.$n,
            'middle_name' => null,
            'last_name' => 'Applicant',
            'birth_date' => '1987-03-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    private function accountResidentId(Account $account): string
    {
        return (string) $account->refresh()->resident_id;
    }

    /**
     * A complete, submittable draft.
     */
    private function openDraft(): string
    {
        return $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Our household needs food assistance this month.',
            'consent_reference' => 'ack-2026-08',
            'privacy_notice_version' => '1.0',
        ])->assertCreated()->json('data.id');
    }

    /**
     * A case driven to `assessment`, ready for a social worker.
     */
    private function caseInAssessment(): string
    {
        Sanctum::actingAs($this->staff());

        $case = $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $this->otherResident('Assess')->uuid,
            'category' => 'food',
            'narrative' => 'Household needs assistance.',
        ])->assertCreated()->json('data.case_id');

        foreach (['intake-review', 'assessment'] as $step) {
            $this->postJson("/api/v1/admin/cases/{$case}/transitions", ['to' => $step])->assertOk();
        }

        return $case;
    }

    private function answerAll(string $case): void
    {
        $this->patchJson("/api/v1/admin/cases/{$case}/assessment", [
            'answers' => [
                'household_income_bracket' => 'below-5000',
                'income_earners' => '1',
                'presenting_problem' => 'Lost livelihood after the storm.',
                'immediate_risk' => 'none',
            ],
        ])->assertOk();
    }
}
