<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\AccessControl\Application\StaffProvisioningService;
use Modules\AccessControl\Contracts\Permission;
use Modules\AccessControl\Domain\Role;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\DataScope;
use Modules\Shared\Exceptions\AuthorizationDeniedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Staff provisioning (ADR 0012).
 *
 * Provisioning is the endpoint that hands out every other permission in the system, so the
 * interesting tests are not "can an admin create staff" but the four ways that power gets
 * turned on itself:
 *
 *  - handing out administrative authority you do not hold;
 *  - granting yourself anything;
 *  - granting a barangay you cannot reach;
 *  - slipping an extra field past the validator.
 *
 * Each is asserted against persisted state, not just a status code — a 403 with the row
 * written anyway is the failure mode worth catching.
 */
final class StaffProvisioningTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the happy path, and what it deliberately does not do ──────────────────────────

    #[Test]
    public function creating_a_staff_account_grants_no_authority_at_all(): void
    {
        $this->actAsFresh($this->reviewer('security_officer'));

        $response = $this->postJson('/api/v1/staff', [
            'email' => 'clerk@taytay.example',
            'display_name' => 'Ana Clerk',
        ])->assertCreated();

        $response->assertJsonPath('data.authority.roles', []);
        $response->assertJsonPath('data.authority.permissions', []);
        $response->assertJsonPath('data.authority.scope.type', 'none');

        // Pending, not active: they still have to set a password through the reset flow,
        // so this endpoint never handles or returns a credential.
        $response->assertJsonPath('data.status', 'pending');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('password_hash', $response->json('data'));
    }

    #[Test]
    public function creating_the_same_staff_member_twice_is_idempotent(): void
    {
        $this->actAsFresh($this->reviewer('security_officer'));

        $payload = ['email' => 'clerk@taytay.example', 'display_name' => 'Ana Clerk'];

        $first = $this->postJson('/api/v1/staff', $payload)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/staff', $payload)->assertCreated()->json('data.id');

        // A dropped connection and a retry must not produce two accounts for one person —
        // two subject ids means an audit trail split across both.
        $this->assertSame($first, $second);
        $this->assertSame(1, Account::query()->where('email', 'clerk@taytay.example')->count());
    }

    #[Test]
    public function assigning_a_role_twice_updates_the_scope_rather_than_duplicating_it(): void
    {
        $clerk = Account::factory()->staff()->create();

        $this->actAsFresh($this->reviewer('security_officer'));

        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->barangayId(),
        ])->assertOk();

        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->otherBarangayId(),
        ])->assertOk()->assertJsonPath('data.authority.scope.barangay_ids', [$this->otherBarangayId()]);

        $this->assertSame(1, DB::table('role_assignments')
            ->where('subject_id', $clerk->uuid)->where('role', 'lgu_staff')->count());
    }

    #[Test]
    public function revoking_a_role_ends_it_without_deleting_the_evidence(): void
    {
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());

        $this->actAsFresh($this->reviewer('security_officer'));

        $this->deleteJson("/api/v1/staff/{$clerk->uuid}/roles/lgu_staff")
            ->assertOk()
            ->assertJsonPath('data.authority.roles', [])
            ->assertJsonPath('data.authority.scope.type', 'none');

        // The row survives: it is the answer to "who could approve this, in March?"
        $row = DB::table('role_assignments')->where('subject_id', $clerk->uuid)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->valid_until);
    }

    // ── privilege escalation ──────────────────────────────────────────────────────────

    #[Test]
    public function a_provisioner_cannot_hand_out_administrative_authority_they_lack(): void
    {
        $clerk = Account::factory()->staff()->create();

        // An actor holding `staff.manage` but not `staff.view`. Constructed directly
        // because the catalog has no such role today — which is the point: the guard has to
        // hold for permission combinations nobody has thought of yet, not just for the four
        // roles that happen to exist.
        $actor = ActorContext::authenticated(
            subjectId: (string) Str::uuid7(),
            roles: ['partial_provisioner'],
            permissions: [Permission::StaffManage->value],
            scope: DataScope::municipality(),
        );

        $this->expectException(AuthorizationDeniedException::class);

        try {
            app(StaffProvisioningService::class)
                ->assignRole($actor, $clerk->uuid, Role::SecurityOfficer, 'all-barangays', null);
        } finally {
            // The escalation loop this closes: grant a colleague the power to provision,
            // have them grant you back whatever you were refused.
            $this->assertSame(0, DB::table('role_assignments')->where('subject_id', $clerk->uuid)->count());
        }
    }

    #[Test]
    public function a_provisioner_may_appoint_operational_staff_without_holding_those_permissions(): void
    {
        $clerk = Account::factory()->staff()->create();
        $officer = $this->reviewer('security_officer');

        $this->actAsFresh($officer);

        // Separation of duties, working as intended: the officer can appoint a KYC
        // reviewer and still cannot review anything themselves. Requiring provisioners to
        // personally hold every permission they grant would mean only a super-admin could
        // staff the office — the concentration of power this design exists to avoid.
        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->barangayId(),
        ])->assertOk()->assertJsonPath('data.authority.roles', ['lgu_staff']);

        $case = $this->submittedCase();

        $this->actAsFresh($officer);
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertForbidden();

        $this->actAsFresh($clerk);
        $this->getJson("/api/v1/admin/kyc-cases/{$case->uuid}")->assertOk();
    }

    #[Test]
    public function nobody_can_provision_their_own_account(): void
    {
        $officer = $this->reviewer('security_officer');

        $this->actAsFresh($officer);

        // Self-service is refused outright, even for a role they already hold: an
        // administrator who can widen their own scope has no scope.
        $this->postJson("/api/v1/staff/{$officer->uuid}/roles", [
            'role' => 'security_officer',
            'scope_type' => 'all-barangays',
        ])->assertForbidden();

        $this->postJson("/api/v1/staff/{$officer->uuid}/barangays", [
            'barangay_id' => $this->barangayId(),
            'reason' => 'convenience',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/staff/{$officer->uuid}")->assertForbidden();

        $this->assertSame(0, DB::table('staff_barangay_grants')->where('subject_id', $officer->uuid)->count());
        $this->assertSame(AccountStatus::Active->value, $officer->refresh()->status->value);
    }

    #[Test]
    public function a_barangay_scoped_provisioner_cannot_hand_out_reach_they_lack(): void
    {
        $officer = Account::factory()->staff()->create();
        $this->grantRole($officer, 'security_officer', $this->barangayId());

        $clerk = Account::factory()->staff()->create();

        $this->actAsFresh($officer);

        // Neither by granting a barangay directly...
        $this->postJson("/api/v1/staff/{$clerk->uuid}/barangays", [
            'barangay_id' => $this->otherBarangayId(),
            'reason' => 'no legitimate reason',
        ])->assertForbidden();

        // ...nor by assigning a role scoped to it...
        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'security_officer',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->otherBarangayId(),
        ])->assertForbidden();

        // ...nor by promoting somebody to municipality-wide.
        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'security_officer',
            'scope_type' => 'all-barangays',
        ])->assertForbidden();

        $this->assertSame(0, DB::table('staff_barangay_grants')->where('subject_id', $clerk->uuid)->count());
        $this->assertSame(0, DB::table('role_assignments')->where('subject_id', $clerk->uuid)->count());
    }

    #[Test]
    public function extra_body_fields_cannot_smuggle_authority(): void
    {
        $clerk = Account::factory()->staff()->create();

        $this->actAsFresh($this->reviewer('security_officer'));

        // Everything here is a field the validator does not name. If any of it reached a
        // write, "create a staff account" would become "create an administrator".
        $created = $this->postJson('/api/v1/staff', [
            'email' => 'clerk@taytay.example',
            'display_name' => 'Ana Clerk',
            'account_type' => 'citizen',
            'status' => 'active',
            'roles' => ['lgu_admin'],
            'permissions' => ['kyc.approve'],
            'is_admin' => true,
            'password_hash' => 'anything',
            'resident_id' => $clerk->uuid,
        ])->assertCreated();

        $created->assertJsonPath('data.authority.permissions', []);
        $created->assertJsonPath('data.status', 'pending');

        $account = Account::query()->where('email', 'clerk@taytay.example')->firstOrFail();
        $this->assertSame('staff', $account->account_type->value);
        $this->assertNull($account->resident_id);
        $this->assertNull($account->password_hash);
    }

    #[Test]
    public function a_role_outside_the_catalog_is_refused(): void
    {
        $clerk = Account::factory()->staff()->create();

        $this->actAsFresh($this->reviewer('security_officer'));

        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'super_admin',
            'scope_type' => 'all-barangays',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('role_assignments')->where('subject_id', $clerk->uuid)->count());
    }

    // ── deactivation ──────────────────────────────────────────────────────────────────

    #[Test]
    public function deactivating_staff_kills_their_live_tokens_immediately(): void
    {
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        $clerk->createToken('console')->plainTextToken;

        $this->actAsFresh($this->reviewer('security_officer'));
        $this->deleteJson("/api/v1/staff/{$clerk->uuid}")->assertOk();

        $this->assertSame(AccountStatus::Deactivated->value, $clerk->refresh()->status->value);
        $this->assertSame(0, $clerk->tokens()->count());

        // And even a token that somehow survived would carry nothing.
        $this->actAsFresh($clerk);
        $this->getJson('/api/v1/admin/kyc-cases')->assertForbidden();
    }

    // ── authorization on the provisioning routes themselves ───────────────────────────

    #[Test]
    public function every_staff_route_denies_a_caller_without_the_permission(): void
    {
        $clerk = Account::factory()->staff()->create();

        // An lgu_admin holds staff.view but deliberately not staff.manage: reading who
        // holds what is not the same responsibility as changing it.
        $this->actAsFresh($this->reviewer('lgu_admin'));

        $this->getJson('/api/v1/staff')->assertOk();
        $this->postJson('/api/v1/staff', ['email' => 'x@y.example', 'display_name' => 'X'])->assertForbidden();
        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", ['role' => 'lgu_staff', 'scope_type' => 'all-barangays'])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$clerk->uuid}/roles/lgu_staff")->assertForbidden();
        $this->postJson("/api/v1/staff/{$clerk->uuid}/barangays", ['barangay_id' => $this->barangayId(), 'reason' => 'r'])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$clerk->uuid}/barangays/{$this->barangayId()}")->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$clerk->uuid}")->assertForbidden();
    }

    #[Test]
    public function an_unauthorized_caller_cannot_tell_an_existing_staff_id_from_a_fictional_one(): void
    {
        $clerk = Account::factory()->staff()->create();

        $this->actAsFresh($this->citizen());

        // Both 403: if the real id 403'd and the fake one 404'd, the difference would map
        // the staff directory for anyone with a token.
        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", ['role' => 'lgu_staff', 'scope_type' => 'all-barangays'])
            ->assertForbidden();
        $this->postJson('/api/v1/staff/01000000-0000-7000-8000-000000000000/roles', ['role' => 'lgu_staff', 'scope_type' => 'all-barangays'])
            ->assertForbidden();
    }

    #[Test]
    public function every_staff_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/staff')->assertUnauthorized();
        $this->postJson('/api/v1/staff')->assertUnauthorized();
        $this->getJson('/api/v1/staff/authority-catalog')->assertUnauthorized();
    }

    // ── audit ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function every_authority_change_leaves_an_audit_record_naming_the_subject(): void
    {
        $clerk = Account::factory()->staff()->create();
        $officer = $this->reviewer('security_officer');

        $this->actAsFresh($officer);

        $this->postJson("/api/v1/staff/{$clerk->uuid}/roles", [
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $this->barangayId(),
        ])->assertOk();

        $this->postJson("/api/v1/staff/{$clerk->uuid}/barangays", [
            'barangay_id' => $this->otherBarangayId(),
            'reason' => 'Covering Dolores',
        ])->assertOk();

        $this->deleteJson("/api/v1/staff/{$clerk->uuid}/roles/lgu_staff")->assertOk();

        $entries = DB::table('audit_entries')->where('entity_id', $clerk->uuid)->get();

        $this->assertEqualsCanonicalizing(
            ['access.role-assigned', 'access.barangay-granted', 'access.role-revoked'],
            $entries->pluck('action')->all(),
        );

        foreach ($entries as $entry) {
            // Who changed it, and to whom.
            $this->assertSame($officer->uuid, $entry->actor_subject_id);
            $this->assertSame($clerk->uuid, $entry->entity_id);
            $this->assertNotNull($entry->request_id);

            // Never the person: no name, no email address in the trail (Article 5.5).
            $this->assertStringNotContainsString((string) $clerk->display_name, (string) $entry->summary);
            $this->assertStringNotContainsString((string) $clerk->email, (string) $entry->summary);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────

    /**
     * Laravel memoises the resolved user on the guard for the lifetime of a test, so a
     * second `actingAs` is otherwise ignored. Harness artifact, not production behaviour.
     */
    private function actAsFresh(Account $account): void
    {
        Auth::forgetGuards();
        Sanctum::actingAs($account);
    }
}
