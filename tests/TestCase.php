<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    protected function grantRole(Account $account, string $role, ?string $barangayId = null): void
    {
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
