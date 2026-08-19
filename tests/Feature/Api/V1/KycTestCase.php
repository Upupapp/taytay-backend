<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Application\KycCaseService;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Tests\TestCase;

/**
 * Fixtures shared by the KYC and digital-ID suites.
 *
 * The helpers build state through the real services wherever a lifecycle rule applies, so
 * a test that sets up a "submitted case" is exercising the same transitions a citizen
 * would. Only the leaf fixtures (an account, a pre-existing resident) are inserted
 * directly.
 */
abstract class KycTestCase extends TestCase
{
    /**
     * A request carrying a fresh `Idempotency-Key`, which every money write requires (TAB 08).
     *
     * **`withHeaders` sets a DEFAULT that persists for the rest of the test**, not a header on one
     * call. That is Laravel's behaviour and it is worth stating here, because it silently defeated
     * the first version of the no-key test: `money()` earlier in the method meant every later
     * request carried a key, and an assertion that a keyless request is refused passed a keyed one.
     *
     * Each call still installs a *new* key, so two intents in one test never share one — which is
     * what a real client does, since the key is generated when an officer commits an intent.
     *
     * Use {@see withoutIdempotencyKey()} to assert the refusal.
     */
    protected function money(): self
    {
        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid7()]);
    }

    /** Clears the persisted key so a keyless request can actually be made. */
    protected function withoutIdempotencyKey(): self
    {
        $this->flushHeaders();

        return $this;
    }

    protected function citizen(array $overrides = []): Account
    {
        return Account::factory()->create($overrides);
    }

    /**
     * A staff account holding a role, granted through the canonical assignments table.
     */
    protected function reviewer(string $role = 'lgu_admin'): Account
    {
        $account = Account::factory()->staff()->create();
        $this->grantRole($account, $role);

        return $account;
    }

    /**
     * The claims a citizen submits. Defaults to one person so that matching tests can
     * collide deliberately.
     *
     * @return array<string, mixed>
     */
    protected function claims(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ], $overrides);
    }

    /**
     * A resident already in the canonical registry — the thing a new registration might
     * duplicate.
     */
    protected function existingResident(array $overrides = []): Resident
    {
        $attributes = array_merge([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'sex' => 'female',
            'birth_date' => '1990-01-15',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
            'verification_tier' => VerificationTier::Verified,
            'verified_at' => now(),
        ], $overrides);

        $attributes['identity_fingerprint'] = IdentityFingerprint::forName(
            $attributes['first_name'],
            $attributes['last_name'],
            $attributes['birth_date'],
        );

        return Resident::query()->create($attributes);
    }

    /**
     * A case that has been registered and submitted, so it is sitting in manual review
     * with whatever candidates the matcher found.
     */
    protected function submittedCase(?Account $account = null, array $claimOverrides = []): KycCase
    {
        $account ??= $this->citizen();

        $service = app(KycCaseService::class);
        $case = $service->register($account->uuid, $this->claims($claimOverrides));

        return $service->submit($case, $account->uuid);
    }

    /**
     * A fully approved case with its resident created — the state an account is in once
     * onboarding is finished.
     */
    protected function approvedCaseFor(Account $account): KycCase
    {
        $case = $this->submittedCase($account);

        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/v1/admin/kyc-cases/{$case->uuid}/approve")->assertOk();

        return $case->refresh();
    }

    /**
     * Links an account to a resident, as approval would.
     */
    protected function linkAccountToResident(Account $account, Resident $resident): void
    {
        $account->forceFill(['resident_id' => $resident->uuid])->save();
    }

    protected function barangayId(): int
    {
        return $this->barangay('brgy-san-juan', 'San Juan');
    }

    /**
     * A second barangay, so scope tests have somewhere a clerk is not allowed to reach.
     */
    protected function otherBarangayId(): int
    {
        return $this->barangay('brgy-dolores', 'Dolores');
    }

    protected function barangay(string $code, string $name): int
    {
        $existing = DB::table('barangays')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('barangays')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function activeCitizenWithResident(): array
    {
        $account = Account::factory()->create([
            'status' => AccountStatus::Active,
            'account_type' => AccountType::Citizen,
        ]);
        $resident = $this->existingResident();
        $this->linkAccountToResident($account, $resident);

        return [$account, $resident];
    }
}
