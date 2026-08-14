<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Application\MultiFactorService;
use Modules\Identity\Application\OneTimeCodes;
use Modules\Identity\Application\PasswordResetService;
use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Session revocation, devices, MFA enrolment and recovery, password reset, and contact
 * verification — the lifecycle after the first sign-in.
 *
 * The recurring theme is that every credential here must be revocable, single-use where
 * appropriate, and scoped to its owner. A caller must never be able to reach another
 * account's session or device by supplying an identifier.
 */
final class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('identity-sign-in');
        RateLimiter::clear('identity-code-request');
    }

    // ── sessions ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_holder_can_list_and_revoke_their_own_sessions(): void
    {
        $account = Account::factory()->staff()->create();
        $token = $account->createToken('phone', ['staff'])->plainTextToken;
        $account->createToken('laptop', ['staff']);

        $sessions = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/sessions')->assertOk()->json('data');

        $this->assertCount(2, $sessions);

        $other = collect($sessions)->firstWhere('current', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/me/sessions/'.$other['id'])
            ->assertOk();

        $this->assertSame(1, $account->tokens()->count());
    }

    #[Test]
    public function a_revoked_token_stops_working_immediately(): void
    {
        $account = Account::factory()->staff()->create();
        $token = $account->createToken('laptop', ['staff'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/tokens/current')->assertOk();

        // The guard memoises the resolved user for the application instance, and a test
        // reuses one instance across requests. Real requests are separate processes; this
        // forces the same fresh resolution so the assertion tests revocation rather than
        // the test harness's cache.
        Auth::forgetGuards();

        // The acceptance criterion: tokens can be revoked, and revocation is immediate
        // rather than waiting for expiry.
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertSame(0, $account->tokens()->count());
    }

    #[Test]
    public function revoking_all_sessions_kills_every_token(): void
    {
        $account = Account::factory()->staff()->create();
        $token = $account->createToken('a', ['staff'])->plainTextToken;
        $account->createToken('b', ['staff']);
        $account->createToken('c', ['staff']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/me/sessions/revoke-all')->assertOk();

        // This is the control a person uses when a phone is lost, so it must include the
        // session making the request.
        $this->assertSame(0, $account->tokens()->count());
    }

    #[Test]
    public function one_account_cannot_revoke_another_accounts_session(): void
    {
        $victim = Account::factory()->staff()->create();
        $victimToken = $victim->createToken('victim', ['staff']);

        $attacker = Account::factory()->staff()->create();
        $attackerToken = $attacker->createToken('attacker', ['staff'])->plainTextToken;

        // 404, not 403: confirming the session exists but belongs to someone else is
        // itself a disclosure (conventions §4).
        $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->deleteJson('/api/v1/me/sessions/'.$victimToken->accessToken->uuid)
            ->assertNotFound();

        $this->assertSame(1, $victim->tokens()->count());
    }

    // ── devices ───────────────────────────────────────────────────────────────────────

    #[Test]
    public function registering_the_same_device_twice_updates_one_row(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $payload = ['fingerprint' => 'install-abc', 'display_name' => 'Pixel', 'platform' => 'android'];

        $this->postJson('/api/v1/me/devices', $payload)->assertCreated();
        $this->postJson('/api/v1/me/devices', $payload + ['display_name' => 'Pixel 9'])->assertCreated();

        // A client that re-registers on every launch must not accumulate hundreds of rows.
        $this->assertSame(1, DB::table('devices')->where('account_id', $account->id)->count());
    }

    #[Test]
    public function the_device_fingerprint_and_push_token_are_never_stored_in_the_clear(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/devices', [
            'fingerprint' => 'install-secret',
            'display_name' => 'Pixel',
            'platform' => 'android',
            'push_token' => 'fcm-token-value',
        ])->assertCreated();

        $row = DB::table('devices')->where('account_id', $account->id)->first();

        // Fingerprint hashed — in the clear it is a cross-account tracking key.
        $this->assertNotSame('install-secret', $row->fingerprint_hash);
        $this->assertSame(64, strlen((string) $row->fingerprint_hash));
        // Push token encrypted — it authorises sending to that device.
        $this->assertNotSame('fcm-token-value', $row->push_token);
    }

    #[Test]
    public function revoking_a_device_clears_its_push_token(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $deviceId = $this->postJson('/api/v1/me/devices', [
            'fingerprint' => 'install-xyz',
            'display_name' => 'Old phone',
            'platform' => 'android',
            'push_token' => 'fcm-token-value',
        ])->json('data.id');

        $this->deleteJson('/api/v1/me/devices/'.$deviceId)->assertOk();

        // A revoked device that still holds a token still receives notifications about a
        // person's case, so clearing it matters as much as the flag.
        $row = DB::table('devices')->where('account_id', $account->id)->first();
        $this->assertNull($row->push_token);
        $this->assertNotNull($row->revoked_at);
    }

    // ── multi-factor ──────────────────────────────────────────────────────────────────

    #[Test]
    public function staff_can_enrol_confirm_and_use_totp(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'mfa@taytay.test']);
        Sanctum::actingAs($account);

        $secret = $this->postJson('/api/v1/me/mfa')->assertCreated()->json('data.secret');

        $codes = $this->postJson('/api/v1/me/mfa/confirm', [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount((int) config('identity.mfa.recovery_code_count'), $codes);

        // Now the password alone is no longer enough.
        RateLimiter::clear('identity-sign-in');
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'mfa@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()->assertJsonPath('data.status', 'mfa-required');
    }

    #[Test]
    public function the_second_factor_completes_the_sign_in(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'mfa2@taytay.test']);
        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];
        app(MultiFactorService::class)->confirmEnrolment($account, (new Google2FA)->getCurrentOtp($secret));

        $challenge = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'mfa2@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->json('data.challenge');

        $this->postJson('/api/v1/auth/tokens/mfa', [
            'challenge' => $challenge,
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertCreated()->assertJsonStructure(['data' => ['token']]);
    }

    #[Test]
    public function a_challenge_cannot_be_replayed(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'mfa3@taytay.test']);
        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];
        app(MultiFactorService::class)->confirmEnrolment($account, (new Google2FA)->getCurrentOtp($secret));

        $challenge = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'mfa3@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->json('data.challenge');

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/tokens/mfa', ['challenge' => $challenge, 'code' => $code])->assertCreated();

        // One challenge, one token. A captured challenge must not mint a second session.
        $this->postJson('/api/v1/auth/tokens/mfa', ['challenge' => $challenge, 'code' => $code])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_recovery_code_works_once_and_only_once(): void
    {
        $account = Account::factory()->staff()->create();
        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];
        $codes = app(MultiFactorService::class)->confirmEnrolment($account, (new Google2FA)->getCurrentOtp($secret));

        $this->assertTrue(app(MultiFactorService::class)->verify($account, $codes[0]));
        // Recovery codes are the fallback when the authenticator is lost; reuse would
        // turn a one-time escape hatch into a standing credential.
        $this->assertFalse(app(MultiFactorService::class)->verify($account, $codes[0]));
    }

    #[Test]
    public function recovery_codes_are_stored_only_as_hashes(): void
    {
        $account = Account::factory()->staff()->create();
        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];
        $codes = app(MultiFactorService::class)->confirmEnrolment($account, (new Google2FA)->getCurrentOtp($secret));

        $stored = DB::table('mfa_recovery_codes')->where('account_id', $account->id)->pluck('code_hash')->all();

        foreach ($codes as $code) {
            $this->assertNotContains($code, $stored, 'A recovery code was stored in the clear.');
        }
    }

    #[Test]
    public function a_citizen_cannot_enrol_in_staff_mfa(): void
    {
        Sanctum::actingAs(Account::factory()->create());

        // Enforced in the service, so the rule lives in one place rather than being
        // duplicated in the route file where it could drift.
        $this->postJson('/api/v1/me/mfa')->assertForbidden();
    }

    // ── password reset ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_reset_token_works_once_and_revokes_every_session(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'reset@taytay.test']);
        $account->createToken('old-session', ['staff']);

        $token = app(PasswordResetService::class)->request('reset@taytay.test', '127.0.0.1');
        $this->assertNotNull($token);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'password' => 'a-much-longer-passphrase',
            'password_confirmation' => 'a-much-longer-passphrase',
        ])->assertOk();

        // If the reset was an attacker, the holder's sessions die; if it was the holder,
        // an attacker's stolen session dies. Either way the ambiguity ends.
        $this->assertSame(0, $account->tokens()->count());

        RateLimiter::clear('identity-sign-in');
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'reset@taytay.test',
            'password' => 'a-much-longer-passphrase',
        ])->assertCreated();
    }

    #[Test]
    public function a_reset_token_cannot_be_replayed(): void
    {
        Account::factory()->staff()->create(['email' => 'replay@taytay.test']);
        $token = app(PasswordResetService::class)->request('replay@taytay.test', null);

        $payload = [
            'token' => (string) $token,
            'password' => 'a-much-longer-passphrase',
            'password_confirmation' => 'a-much-longer-passphrase',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnauthorized();
    }

    #[Test]
    public function an_expired_reset_token_is_refused(): void
    {
        Account::factory()->staff()->create(['email' => 'expired@taytay.test']);
        $token = app(PasswordResetService::class)->request('expired@taytay.test', null);

        $this->travel((int) config('identity.password_reset.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => (string) $token,
            'password' => 'a-much-longer-passphrase',
            'password_confirmation' => 'a-much-longer-passphrase',
        ])->assertUnauthorized();
    }

    #[Test]
    public function requesting_a_reset_never_reveals_whether_the_address_exists(): void
    {
        Account::factory()->staff()->create(['email' => 'known@taytay.test']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'known@taytay.test']);
        RateLimiter::clear('identity-code-request');
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@taytay.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json('data'), $unknown->json('data'));
    }

    #[Test]
    public function a_reset_token_is_stored_only_as_a_hash(): void
    {
        Account::factory()->staff()->create(['email' => 'hash@taytay.test']);
        $token = (string) app(PasswordResetService::class)->request('hash@taytay.test', null);

        $stored = (string) DB::table('password_resets')->latest('id')->first()->token_hash;

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    // ── contact verification ──────────────────────────────────────────────────────────

    #[Test]
    public function a_holder_can_verify_their_email(): void
    {
        $account = Account::factory()->staff()->create(['email_verified_at' => null]);
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/me/contact/verify', ['channel' => 'email'])->assertStatus(202);

        $code = app(OneTimeCodes::class)
            ->issue($account, VerificationPurpose::VerifyEmail);

        $this->postJson('/api/v1/me/contact/verify/confirm', ['channel' => 'email', 'code' => $code])
            ->assertOk();

        $this->assertNotNull($account->fresh()?->email_verified_at);
    }

    #[Test]
    public function verifying_a_channel_the_account_does_not_have_is_refused(): void
    {
        // A citizen account has no email; issuing a code would silently deliver nowhere.
        Sanctum::actingAs(Account::factory()->create(['email' => null]));

        $this->postJson('/api/v1/me/contact/verify', ['channel' => 'email'])->assertStatus(409);
    }
}
