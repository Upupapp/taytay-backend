<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data — the real jurisdiction list, wanted in every environment.
        $this->call(BarangaySeeder::class);

        // Development convenience only. Never seed a fake account into a real environment.
        //
        // firstOrCreate, not create: seeding must be idempotent so it can run on every
        // deploy alongside the reference data above. `create` made a second run fail on
        // the unique email, which would have blocked the barangay seeder behind it.
        if (app()->environment('local', 'testing')) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => bcrypt(Str::random(32))],
            );
        }
    }
}
