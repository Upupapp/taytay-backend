<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Identity\Application\OneTimeCodes;
use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sign-in surface, and the things it must refuse.
 *
 * Most of these are negative tests on purpose. An authentication endpoint that works is
 * easy; one that refuses correctly — without leaking which part was wrong — is the job.
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests deliberately hammer the sign-in routes; the limiter is asserted on
        // its own below rather than tripping every other case.
        RateLimiter::clear('identity-sign-in');
    }

    // ── staff: password ───────────────────────────────────────────────────────────────

    #[Test]
    public function staff_can_sign_in_with_a_password(): void
    {
        Account::factory()->staff()->create(['email' => 'officer@taytay.test']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'officer@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ], ['X-Client-Channel' => 'admin-console']);

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_address_fail_identically(): void
    {
        Account::factory()->staff()->create(['email' => 'officer@taytay.test']);

        $wrongPassword = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'officer@taytay.test',
            'password' => 'not-the-password',
        ]);

        $unknownAccount = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'nobody@taytay.test',
            'password' => 'not-the-password',
        ]);

        // Identical status, code and message. Any difference here is an account-existence
        // oracle, and for a VAWC survivor "does this person have an account" is a safety
        // question, not a privacy nicety.
        $wrongPassword->assertUnauthorized();
        $unknownAccount->assertUnauthorized();
        $this->assertSame($wrongPassword->json('error.code'), $unknownAccount->json('error.code'));
        $this->assertSame($wrongPassword->json('error.message'), $unknownAccount->json('error.message'));
    }

    #[Test]
    public function a_citizen_account_cannot_sign_in_through_the_staff_password_route(): void
    {
        Account::factory()->create(['email' => 'resident@taytay.test', 'password_hash' => 'correct-horse-battery-staple']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'resident@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        Account::factory()->staff()->suspended()->create(['email' => 'suspended@taytay.test']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'suspended@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_locked_account_cannot_sign_in_even_with_the_right_password(): void
    {
        Account::factory()->staff()->locked()->create(['email' => 'locked@taytay.test']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'locked@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->assertUnauthorized();
    }

    #[Test]
    public function repeated_failures_lock_the_account(): void
    {
        $account = Account::factory()->staff()->create(['email' => 'target@taytay.test']);

        for ($attempt = 0; $attempt < (int) config('identity.lockout.max_failed_attempts'); $attempt++) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 'target@taytay.test', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->assertNotNull($account->fresh()?->locked_until);

        // A timed lockout, not permanent — a permanent block would be a denial-of-service
        // primitive against any staff member whose address is known.
        $this->assertTrue($account->fresh()?->locked_until->isFuture());
    }

    // ── citizen: one-time code ────────────────────────────────────────────────────────

    #[Test]
    public function requesting_a_code_answers_identically_for_registered_and_unknown_numbers(): void
    {
        Account::factory()->create(['mobile_number' => '+639170000001']);

        $registered = $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000001']);
        RateLimiter::clear('identity-code-request');
        $unknown = $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639179999999']);

        $registered->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($registered->json('data'), $unknown->json('data'));
    }

    #[Test]
    public function the_response_never_contains_the_code(): void
    {
        Account::factory()->create(['mobile_number' => '+639170000002']);

        $body = (string) $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000002'])->getContent();

        $code = DB::table('verification_codes')->latest('id')->first();

        $this->assertNotNull($code, 'A code should have been issued.');
        // Only the hash is stored, and nothing resembling a code is returned.
        $this->assertSame(64, strlen((string) $code->code_hash));
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', $body);
    }

    #[Test]
    public function a_citizen_can_sign_in_with_a_valid_code(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170000003', 'mobile_verified_at' => null]);
        $code = app(OneTimeCodes::class)->issue($account, VerificationPurpose::SignIn);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile_number' => '+639170000003',
            'code' => $code,
        ], ['X-Client-Channel' => 'citizen-mobile'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        // Signing in with a code sent to the number proves control of it.
        $this->assertNotNull($account->fresh()?->mobile_verified_at);
    }

    #[Test]
    public function a_code_cannot_be_used_twice(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170000004']);
        $code = app(OneTimeCodes::class)->issue($account, VerificationPurpose::SignIn);

        $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000004', 'code' => $code])
            ->assertCreated();

        // Replay must fail: a code observed over SMS is single-use.
        $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000004', 'code' => $code])
            ->assertUnauthorized();
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170000005']);
        $code = app(OneTimeCodes::class)->issue($account, VerificationPurpose::SignIn);

        $this->travel((int) config('identity.one_time_code.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000005', 'code' => $code])
            ->assertUnauthorized();
    }

    #[Test]
    public function guessing_burns_the_code(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170000006']);
        $code = app(OneTimeCodes::class)->issue($account, VerificationPurpose::SignIn);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000006', 'code' => '000000'])
                ->assertUnauthorized();
        }

        // Even the correct code is now dead — the attempt cap burns it rather than
        // leaving a nearly-guessed code alive.
        $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000006', 'code' => $code])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_code_issued_to_verify_an_email_cannot_be_used_to_sign_in(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170000007', 'email' => 'r@taytay.test']);
        $code = app(OneTimeCodes::class)->issue($account, VerificationPurpose::VerifyEmail);

        // Purpose is part of the lookup, so codes are single-purpose by construction.
        $this->postJson('/api/v1/auth/otp/verify', ['mobile_number' => '+639170000007', 'code' => $code])
            ->assertUnauthorized();
    }

    // ── rate limiting ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_code_request_endpoint_is_rate_limited(): void
    {
        Account::factory()->create(['mobile_number' => '+639170000008']);

        $limit = (int) config('identity.rate_limits.code_request');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000008'])->assertStatus(202);
        }

        // Sending SMS costs money and annoys a real person's phone, so this limit is
        // tighter than the sign-in one.
        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000008'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // ── authentication is not authorization ───────────────────────────────────────────

    #[Test]
    public function a_freshly_authenticated_account_holds_no_permissions(): void
    {
        Account::factory()->staff()->create(['email' => 'new@taytay.test']);

        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'new@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->json('data.token');

        // The acceptance criterion, stated as a test: signing in proves who you are and
        // grants nothing. Authority comes from role assignments resolved server-side.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.roles', []);
    }

    #[Test]
    public function authentication_alone_does_not_widen_what_a_caller_can_see(): void
    {
        Account::factory()->staff()->create(['email' => 'plain@taytay.test']);

        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'plain@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->json('data.token');

        // Six published catalog entries — the same as an anonymous caller sees. A token
        // is not a key to the data behind it.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/services?per_page=100')
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }
}
