<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Infrastructure\Eloquent\Account;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Reference data — the real jurisdiction list, wanted in every environment.
        $this->call(BarangaySeeder::class);

        // Development convenience only. Never seed an account into a real environment: a
        // known staff login on a system holding welfare records is not a convenience.
        //
        // firstOrCreate, not create: seeding must be idempotent so it can run on every
        // deploy alongside the reference data above.
        if (app()->environment('local', 'testing')) {
            Account::firstOrCreate(
                ['email' => 'staff@example.test'],
                [
                    'uuid' => (string) Str::uuid7(),
                    'account_type' => AccountType::Staff,
                    'status' => AccountStatus::Active,
                    'display_name' => 'Local Staff',
                    // Random, not a known string. A developer resets it locally rather
                    // than inheriting a password that might survive into a demo.
                    'password_hash' => Str::random(40),
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
