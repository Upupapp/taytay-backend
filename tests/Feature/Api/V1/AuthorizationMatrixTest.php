<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * The authorization matrix (ADR 0012, TAB 07).
 *
 * TAB 06 shipped `role_assignments.scope_type` as a column nothing read: a barangay clerk
 * carried a scope in the database and could still open every case in the municipality.
 * These tests are the standing proof that the gap stays closed.
 *
 * They deliberately attack rather than describe. Each one is a thing a real caller could
 * try with nothing but curl and a guessed identifier:
 *
 *  - hold the permission but not the scope;
 *  - change the HTTP verb to reach the same record through a different door;
 *  - guess an identifier from another barangay;
 *  - keep using a token issued before the role was taken away;
 *  - ask for the whole list and see whose rows come back.
 *
 * A test here that starts passing for the wrong reason — because a route was renamed, say
 * — would be worse than useless, so each asserts on a concrete record and status.
 */
final class AuthorizationMatrixTest extends KycTestCase
{
    use RefreshDatabase;

    // ── roles differ in capability ────────────────────────────────────────────────────

    #[Test]
    public function different_roles_receive_different_backend_capabilities(): void
    {
        $case = $this->submittedCase();

        // Staff may review a case but not decide it.
        Sanctum::actingAs($this->reviewer('lgu_staff'));
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertOk();
        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")->assertForbidden();

        // An admin may do both.
        $this->actAsFresh($this->reviewer('lgu_admin'));
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertOk();

        // A security officer provisions people and touches no resident data at all —
        // separation of duties, not a weaker admin.
        $this->actAsFresh($this->reviewer('security_officer'));
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();
        $this->getJson('/api/v1/staff')->assertOk();

        // A citizen holds no staff capability whatsoever.
        $this->actAsFresh($this->citizen());
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    #[Test]
    public function an_account_with_no_role_reaches_nothing(): void
    {
        // Deny by default: authenticating proves who you are and grants nothing (ADR 0009).
        $this->actAsFresh(Account::factory()->staff()->create());

        $this->getJson('/api/v1/admin/kyc-cases')->assertForbidden();
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    // ── barangay scope ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_barangay_scoped_reviewer_sees_only_their_own_barangay_in_the_queue(): void
    {
        $mine = $this->submittedCase(null, ['barangay_id' => $this->barangayId()]);
        $theirs = $this->submittedCase(
            $this->citizen(),
            ['first_name' => 'Jose', 'last_name' => 'Rizal', 'barangay_id' => $this->otherBarangayId()],
        );

        $this->actAsFresh($this->scopedReviewer($this->barangayId()));

        $ids = $this->getJson('/api/v1/admin/kyc-cases')->assertOk()->json('data.*.id');

        $this->assertContains($mine->uuid, $ids);
        $this->assertNotContains($theirs->uuid, $ids);

        // The pagination total must agree with the rows: a count that includes invisible
        // records tells the caller how many exist elsewhere.
        $this->assertSame(1, $this->getJson('/api/v1/admin/kyc-cases')->json('meta.pagination.total'));
    }

    #[Test]
    public function guessing_an_identifier_from_another_barangay_returns_not_found_not_forbidden(): void
    {
        $theirs = $this->submittedCase(null, ['barangay_id' => $this->otherBarangayId()]);

        $this->actAsFresh($this->scopedReviewer($this->barangayId()));

        $response = $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertNotFound();

        // 403 would confirm the record exists. Repeated over guessed ids that is a
        // directory of every applicant in the municipality (OWASP API1).
        $this->assertSame('NOT_FOUND', $response->json('error.code'));

        // And identical to a genuinely absent record, byte for byte in the meaningful part.
        $absent = $this->getJson('/api/v1/admin/kyc-cases/'.Str::uuid7())->assertNotFound();
        $this->assertSame($absent->json('error.code'), $response->json('error.code'));
        $this->assertSame($absent->json('error.message'), $response->json('error.message'));
    }

    #[Test]
    public function changing_the_http_method_does_not_bypass_scope(): void
    {
        $theirs = $this->submittedCase(null, ['barangay_id' => $this->otherBarangayId()]);
        $status = $theirs->status->value;

        $this->actAsFresh($this->scopedReviewer($this->barangayId(), 'lgu_admin'));

        // Every verb the case exposes, including the ones that write. Authorization lives
        // in the record loader, so adding a route cannot open a new door by accident.
        $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertNotFound();
        $this->postJson("/api/v1/admin/kyc-cases/{$theirs->uuid}/rescreen")->assertNotFound();
        $this->postJson("/api/v1/admin/kyc-cases/{$theirs->uuid}/approve")->assertNotFound();
        $this->postJson("/api/v1/admin/kyc-cases/{$theirs->uuid}/reject", ['reason' => 'x'])->assertNotFound();
        $this->postJson(
            "/api/v1/admin/kyc-cases/{$theirs->uuid}/request-information",
            ['message' => 'x'],
        )->assertNotFound();

        // Nothing was written by any of them.
        $this->assertSame($status, $theirs->refresh()->status->value);
    }

    #[Test]
    public function an_explicit_grant_is_the_only_way_to_widen_a_barangay_scope(): void
    {
        $theirs = $this->submittedCase(null, ['barangay_id' => $this->otherBarangayId()]);
        $clerk = $this->scopedReviewer($this->barangayId());

        $this->actAsFresh($clerk);
        $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertNotFound();

        // An admin who can reach that barangay grants it, with a reason.
        $this->actAsFresh($this->reviewer('security_officer'));
        $this->postJson("/api/v1/staff/{$clerk->uuid}/barangays", [
            'barangay_id' => $this->otherBarangayId(),
            'reason' => 'Covering Dolores while its clerk is on leave',
        ])->assertOk();

        $this->actAsFresh($clerk);
        $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertOk();

        // And withdrawing it closes the door again on the next request, not at token expiry.
        $this->actAsFresh($this->reviewer('security_officer'));
        $this->deleteJson("/api/v1/staff/{$clerk->uuid}/barangays/{$this->otherBarangayId()}")->assertOk();

        $this->actAsFresh($clerk);
        $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertNotFound();
    }

    #[Test]
    public function an_expired_grant_stops_applying_without_anyone_revoking_it(): void
    {
        $theirs = $this->submittedCase(null, ['barangay_id' => $this->otherBarangayId()]);
        $clerk = $this->scopedReviewer($this->barangayId());

        DB::table('staff_barangay_grants')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => $clerk->uuid,
            'barangay_id' => $this->otherBarangayId(),
            'reason' => 'Temporary cover, already ended',
            'granted_by' => (string) Str::uuid7(),
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actAsFresh($clerk);
        $this->getJson("/api/v1/admin/kyc-cases/{$theirs->uuid}")->assertNotFound();
    }

    // ── assigned-case scope ───────────────────────────────────────────────────────────

    #[Test]
    public function an_assigned_cases_reviewer_reaches_only_the_cases_assigned_to_them(): void
    {
        $reviewer = Account::factory()->staff()->create();
        $this->grantRoleWithScope($reviewer, 'lgu_staff', 'assigned-cases', $this->barangayId());

        $mine = $this->submittedCase();
        $unassigned = $this->submittedCase($this->citizen(), ['first_name' => 'Jose', 'last_name' => 'Rizal']);

        $mine->forceFill(['assigned_to' => $reviewer->uuid])->save();

        $this->actAsFresh($reviewer);

        $ids = $this->getJson('/api/v1/admin/kyc-cases')->assertOk()->json('data.*.id');
        $this->assertSame([$mine->uuid], $ids);

        $this->getJson("/api/v1/admin/kyc-cases/{$mine->uuid}")->assertOk();
        $this->getJson("/api/v1/admin/kyc-cases/{$unassigned->uuid}")->assertNotFound();
    }

    // ── credentials are scoped too ────────────────────────────────────────────────────

    #[Test]
    public function a_barangay_scoped_admin_cannot_issue_a_credential_for_another_barangay(): void
    {
        config(['credential.digital_id.enabled' => true]);

        $outsider = $this->existingResident(['barangay_id' => $this->otherBarangayId()]);
        $insider = $this->existingResident([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'barangay_id' => $this->barangayId(),
        ]);

        $this->actAsFresh($this->scopedReviewer($this->barangayId(), 'lgu_admin'));

        // Holding `credential.manage` is not the same as it applying to everyone.
        $this->postJson('/api/v1/admin/credentials', ['resident_id' => $outsider->uuid])->assertNotFound();
        $this->postJson('/api/v1/admin/credentials', ['resident_id' => $insider->uuid])->assertCreated();
    }

    // ── authority follows current database state ──────────────────────────────────────

    #[Test]
    public function a_token_issued_before_a_role_was_revoked_no_longer_carries_it(): void
    {
        $case = $this->submittedCase();
        $reviewer = $this->reviewer('lgu_admin');

        $this->actAsFresh($reviewer);
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertOk();

        // The token is untouched; only the database changed.
        DB::table('role_assignments')->where('subject_id', $reviewer->uuid)->update(['valid_until' => now()->subMinute()]);

        $this->actAsFresh($reviewer);
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();
    }

    #[Test]
    public function a_suspended_staff_account_carries_no_authority_even_with_a_valid_token(): void
    {
        $case = $this->submittedCase();
        $reviewer = $this->reviewer('lgu_admin');

        $reviewer->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->actAsFresh($reviewer);
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();
    }

    #[Test]
    public function the_schema_refuses_a_scope_value_the_catalog_does_not_know(): void
    {
        $reviewer = Account::factory()->staff()->create();

        // Defence in depth, and the outer layer of it. ScopeResolver already fails closed
        // on an unrecognised scope (ScopeResolverTest), but the row cannot be written in
        // the first place — so a hand-edited assignment or a half-finished migration
        // cannot leave an actor holding a scope nothing evaluates.
        $this->expectException(QueryException::class);

        $this->grantRoleWithScope($reviewer, 'lgu_admin', 'district-wide', $this->barangayId());
    }

    // ── the citizen side is unaffected by staff scope ─────────────────────────────────

    #[Test]
    public function a_citizen_still_reaches_their_own_case_regardless_of_barangay_scope(): void
    {
        $citizen = $this->citizen();
        $case = $this->submittedCase($citizen, ['barangay_id' => $this->otherBarangayId()]);

        $this->actAsFresh($citizen);

        // `/me` routes resolve the record from the token, so there is no scope to apply and
        // no identifier to tamper with.
        $this->getJson('/api/v1/me/kyc')->assertOk()->assertJsonPath('data.id', $case->uuid);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────

    /**
     * A reviewer bound to one barangay.
     */
    private function scopedReviewer(int $barangayId, string $role = 'lgu_staff'): Account
    {
        $account = Account::factory()->staff()->create();
        $this->grantRole($account, $role, $barangayId);

        return $account;
    }

    private function grantRoleWithScope(Account $account, string $role, string $scopeType, ?int $barangayId): void
    {
        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => $account->uuid,
            'role' => $role,
            'scope_type' => $scopeType,
            'barangay_id' => $barangayId,
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Switches actor mid-test.
     *
     * Laravel memoises the resolved user on the guard for the lifetime of the test, so
     * without forgetting it the second `actingAs` is ignored and the assertions that
     * follow silently test the first actor. Harness artifact, not production behaviour.
     */
    private function actAsFresh(Account $account): void
    {
        Auth::forgetGuards();
        Sanctum::actingAs($account);
    }
}
