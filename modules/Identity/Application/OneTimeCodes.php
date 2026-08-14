<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Identity\Infrastructure\Eloquent\VerificationCode;

/**
 * Issues and verifies one-time codes.
 *
 * One implementation serves citizen sign-in and contact verification. Expiry, the attempt
 * cap and single use are therefore written once — two copies of this would drift, and the
 * copy that drifts is the one nobody tested.
 *
 * The plaintext code is returned to the caller exactly once, at issue, so it can be
 * delivered. It is stored only as a SHA-256 hash: a dump of `verification_codes` must not
 * let the holder sign in as anybody.
 */
final class OneTimeCodes
{
    public function __construct(private readonly IdentityAudit $audit) {}

    /**
     * Issues a code, invalidating any outstanding one for the same purpose.
     *
     * Invalidating first matters: leaving several live codes multiplies the guessing
     * surface by the number of times the user pressed "resend".
     *
     * @return string the plaintext code — deliver it, never log it, never return it in an
     *                API response
     */
    public function issue(Account $account, VerificationPurpose $purpose): string
    {
        $code = $this->generateCode();

        DB::transaction(function () use ($account, $purpose, $code): void {
            VerificationCode::query()
                ->where('account_id', $account->id)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            VerificationCode::query()->create([
                'account_id' => $account->id,
                'purpose' => $purpose->value,
                'code_hash' => self::hash($code),
                'expires_at' => now()->addMinutes((int) config('identity.one_time_code.ttl_minutes')),
            ]);
        });

        $this->audit->record($account, 'identity.code-issued', "One-time code issued for {$purpose->value}");

        return $code;
    }

    /**
     * Consumes a code. Returns false for wrong, expired, already-used and
     * too-many-attempts alike — the caller must not tell them apart to the client, or the
     * error message becomes an oracle.
     */
    public function consume(Account $account, VerificationPurpose $purpose, string $submitted): bool
    {
        return DB::transaction(function () use ($account, $purpose, $submitted): bool {
            /** @var VerificationCode|null $code */
            $code = VerificationCode::query()
                ->where('account_id', $account->id)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($code === null || ! $code->isUsable()) {
                return false;
            }

            // Count the attempt before comparing, so an abandoned request still burns one.
            $code->increment('attempts');

            if (! hash_equals((string) $code->code_hash, self::hash($submitted))) {
                if ($code->attempts >= VerificationCode::MAX_ATTEMPTS) {
                    // Burn it rather than leave a nearly-guessed code alive.
                    $code->forceFill(['consumed_at' => now()])->save();
                    $this->audit->record($account, 'identity.code-burned', 'One-time code exhausted by failed attempts');
                }

                return false;
            }

            $code->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    /**
     * Numeric so it can be typed from an SMS, and drawn from a CSPRNG — `rand()` here
     * would make codes predictable from one observed value.
     */
    private function generateCode(): string
    {
        $length = (int) config('identity.one_time_code.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public static function hash(string $value): string
    {
        // SHA-256, not bcrypt: these are high-entropy, short-lived and verified on a hot
        // path. Deliberate, and different from passwords, which are slow-hashed.
        return hash('sha256', $value);
    }
}
