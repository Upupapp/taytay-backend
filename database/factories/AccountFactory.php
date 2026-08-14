<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Infrastructure\Eloquent\Account;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * A citizen by default — the overwhelming majority of accounts, and the one with the
     * fewest privileges, so a test that forgets to be explicit gets the safe case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'account_type' => AccountType::Citizen,
            'status' => AccountStatus::Active,
            'display_name' => fake()->name(),
            'mobile_number' => '+639'.fake()->unique()->numerify('#########'),
            'email' => null,
            'password_hash' => null,
            'mobile_verified_at' => now(),
        ];
    }

    public function staff(): self
    {
        return $this->state(fn (): array => [
            'account_type' => AccountType::Staff,
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => null,
            // Hashed by the model's `hashed` cast.
            'password_hash' => 'correct-horse-battery-staple',
            'email_verified_at' => now(),
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Pending]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Suspended]);
    }

    public function locked(): self
    {
        return $this->state(fn (): array => ['locked_until' => now()->addHour()]);
    }
}
