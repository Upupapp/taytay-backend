<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccessControl\Domain\Role;
use Modules\Identity\Infrastructure\Eloquent\Account;

abstract class TestCase extends BaseTestCase
{
    /**
     * Grants a role to an account through the canonical `role_assignments` table.
     *
     * Deliberately writes the row rather than stubbing the repository: the point of most
     * of these tests is that authority is resolved server-side from persisted state, and
     * a stub would prove only that the stub works.
     */
    protected function grantRole(Account $account, string $role, ?int $barangayId = null): void
    {
        /*
         * AN UNKNOWN ROLE FAILS LOUDLY, because it used to fail silently and in the worst
         * direction.
         *
         * `role_assignments.role` is a plain string, so granting `social_worker` — a job title
         * this system has no role for — wrote a row that resolved to **no permissions at all**.
         * Every test using it asserted a refusal, so every one of them passed: not because the
         * permission boundary held, but because the actor had nothing. Six tests written across
         * TABs 07, 08 and 17 were weaker than they read.
         *
         * A test asserting a 403 is exactly where this hides, because the wrong answer and the
         * right answer look identical.
         */
        if (! in_array($role, array_map(static fn (Role $r): string => $r->value, Role::cases()), true)) {
            throw new \InvalidArgumentException(
                "There is no role '{$role}'. A test granting one asserts nothing: the account gets "
                .'no permissions, and every refusal it then checks passes for the wrong reason. '
                .'Roles: '.implode(', ', array_map(static fn (Role $r): string => $r->value, Role::cases())),
            );
        }

        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => $account->uuid,
            'role' => $role,
            'scope_type' => $barangayId === null ? 'all-barangays' : 'own-barangay',
            'barangay_id' => $barangayId,
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
