<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccessControl\Domain\Role;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\DataScope;

/**
 * Bootstraps the first provisioner.
 *
 * **The permission catalog is not seeded, deliberately.** It lives in
 * `Modules\AccessControl\Contracts\Permission` and the role→permission mapping in
 * `Role::permissions()`, so there is exactly one source of truth. A `permissions` table
 * would be a second one, and the first time the two disagreed the database would win a
 * question the code is supposed to answer (ADR 0012). Clients that need the vocabulary read
 * it from `GET /api/v1/staff/authority-catalog`, which is generated from the enum.
 *
 * What genuinely needs seeding is the chicken-and-egg problem: provisioning requires
 * `staff.manage`, and nobody holds it on a fresh database. This grants exactly one
 * bootstrap security officer — and only in local/testing, because a known privileged login
 * on a system holding welfare records is not a convenience.
 *
 * Real environments bootstrap through a one-off operator action against the production
 * database, recorded in the runbook. That is deliberately more friction than running a
 * seeder.
 */
final class AccessControlSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        /** @var Account|null $account */
        $account = Account::query()
            ->where('email', 'staff@example.test')
            ->where('account_type', AccountType::Staff)
            ->first();

        if ($account === null) {
            return;
        }

        // Idempotent: seeding runs on every local rebuild, and a second run must update
        // rather than fail on the (subject_id, role) unique key.
        DB::table('role_assignments')->updateOrInsert(
            ['subject_id' => $account->uuid, 'role' => Role::SecurityOfficer->value],
            [
                'uuid' => (string) Str::uuid7(),
                'scope_type' => DataScope::ALL_BARANGAYS,
                'barangay_id' => null,
                'granted_by' => null,
                'valid_from' => now(),
                'valid_until' => null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
