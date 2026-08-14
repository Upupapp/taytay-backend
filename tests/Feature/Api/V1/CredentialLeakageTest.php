<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Identity\Application\MultiFactorService;
use Modules\Identity\Application\PasswordResetService;
use Modules\Identity\Infrastructure\Eloquent\Account;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The acceptance criterion "sensitive credentials never appear in logs", asserted rather
 * than asserted-to.
 *
 * Credential leakage is almost never deliberate. It arrives through a debug dump, an
 * exception that stringifies the request, a model serialised into a log line, or an audit
 * summary that helpfully includes "the code was 123456". Each of those is checked here.
 */
final class CredentialLeakageTest extends TestCase
{
    use RefreshDatabase;

    /** Captured log output for the duration of one test. */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('identity-sign-in');
        RateLimiter::clear('identity-code-request');

        Log::listen(function ($message): void {
            $this->logged[] = $message->message.' '.json_encode($message->context);
        });
    }

    /**
     * Asserts against the whole captured log at once, so the assertion still registers
     * when nothing was logged — a helper that silently does nothing on an empty log is a
     * test that passes for the wrong reason.
     */
    private function assertNothingLoggedContains(string $secret, string $what): void
    {
        $this->assertStringNotContainsString(
            $secret,
            implode("\n", $this->logged),
            "{$what} reached the log."
        );
    }

    #[Test]
    public function a_password_never_reaches_the_log_or_the_response(): void
    {
        Account::factory()->staff()->create(['email' => 'leak@taytay.test']);

        $password = 'correct-horse-battery-staple';

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'leak@taytay.test',
            'password' => $password,
        ]);

        $this->assertStringNotContainsString($password, (string) $response->getContent());
        $this->assertNothingLoggedContains($password, 'A password');
    }

    #[Test]
    public function a_failed_password_attempt_does_not_log_the_attempted_password(): void
    {
        Account::factory()->staff()->create(['email' => 'leak2@taytay.test']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'leak2@taytay.test',
            'password' => 'wrong-but-secret-guess',
        ])->assertUnauthorized();

        // The failure path is the one that tends to log "everything we know" while
        // debugging, and the one an attacker triggers most often.
        $this->assertNothingLoggedContains('wrong-but-secret-guess', 'An attempted password');
    }

    #[Test]
    public function a_password_is_never_stored_in_the_clear(): void
    {
        Account::factory()->staff()->create(['email' => 'stored@taytay.test']);

        $stored = (string) DB::table('accounts')->where('email', 'stored@taytay.test')->value('password_hash');

        $this->assertNotSame('correct-horse-battery-staple', $stored);
        // bcrypt, not a fast hash: passwords are the one secret here that is slow-hashed,
        // because they are low-entropy and human-chosen.
        $this->assertStringStartsWith('$2y$', $stored);
    }

    #[Test]
    public function the_account_model_never_serialises_its_password_hash(): void
    {
        $account = Account::factory()->staff()->create();

        // A model that leaks its hash into an array is one `Log::info($account)` away
        // from putting it in a log file.
        $this->assertArrayNotHasKey('password_hash', $account->toArray());
        $this->assertStringNotContainsString('password_hash', (string) json_encode($account));
    }

    #[Test]
    public function a_one_time_code_never_reaches_the_log_or_the_response(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170001111']);

        $response = $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170001111']);

        // The code exists — it was issued and hashed — but nothing outside the delivery
        // channel ever sees it.
        $this->assertSame(1, DB::table('verification_codes')->where('account_id', $account->id)->count());
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', (string) $response->getContent());
        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{6}\b/',
            implode("\n", $this->logged),
            'A one-time code reached the log.'
        );
    }

    #[Test]
    public function a_password_reset_token_never_reaches_the_log_or_the_response(): void
    {
        Account::factory()->staff()->create(['email' => 'resetleak@taytay.test']);

        $response = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'resetleak@taytay.test']);
        $token = (string) app(PasswordResetService::class)->request('resetleak@taytay.test', null);

        $this->assertStringNotContainsString($token, (string) $response->getContent());
        $this->assertNothingLoggedContains($token, 'A reset token');
    }

    #[Test]
    public function a_bearer_token_never_reaches_the_log(): void
    {
        Account::factory()->staff()->create(['email' => 'tokenleak@taytay.test']);

        $token = (string) $this->postJson('/api/v1/auth/tokens', [
            'email' => 'tokenleak@taytay.test',
            'password' => 'correct-horse-battery-staple',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk();

        $this->assertNothingLoggedContains($token, 'A bearer token');
    }

    #[Test]
    public function a_token_is_stored_only_as_a_hash(): void
    {
        $account = Account::factory()->staff()->create();
        $plain = $account->createToken('device', ['staff'])->plainTextToken;

        $stored = (string) DB::table('personal_access_tokens')->where('tokenable_id', $account->id)->value('token');

        // Sanctum stores the SHA-256 of the token, not the token — reused deliberately
        // rather than reimplemented.
        $this->assertStringNotContainsString($stored, $plain);
        $this->assertSame(64, strlen($stored));
    }

    #[Test]
    public function a_totp_secret_and_recovery_codes_never_reach_the_log(): void
    {
        $account = Account::factory()->staff()->create();

        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];
        $codes = app(MultiFactorService::class)->confirmEnrolment($account, (new Google2FA)->getCurrentOtp($secret));

        $this->assertNothingLoggedContains($secret, 'A TOTP secret');

        foreach ($codes as $code) {
            $this->assertNothingLoggedContains($code, 'A recovery code');
        }
    }

    #[Test]
    public function the_totp_secret_is_encrypted_at_rest(): void
    {
        $account = Account::factory()->staff()->create();
        $secret = app(MultiFactorService::class)->beginEnrolment($account)['secret'];

        $stored = (string) DB::table('mfa_factors')->where('account_id', $account->id)->value('secret');

        // Encrypted rather than hashed, because verification needs it back — which makes
        // it the one recoverable authentication secret in the system, and the reason the
        // column is encrypted rather than plain.
        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
    }

    #[Test]
    public function the_audit_trail_records_events_without_recording_secrets(): void
    {
        $account = Account::factory()->create(['mobile_number' => '+639170002222']);

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170002222'])->assertStatus(202);

        $summaries = DB::table('audit_entries')->pluck('summary')->implode(' ');

        // The trail proves a code was issued without becoming a place to read it.
        $this->assertStringContainsString('One-time code issued', $summaries);
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', $summaries);

        // Nor does it record the identifier that was probed: a list of attempted mobile
        // numbers is itself sensitive.
        $this->assertStringNotContainsString('+639170002222', $summaries);
    }
}
