<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Identity\Infrastructure\Eloquent\MfaFactor;
use Modules\Identity\Infrastructure\Eloquent\MfaRecoveryCode;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment, verification and recovery for staff accounts.
 *
 * Staff read other people's welfare records, so a stolen password must not be enough
 * (ADR 0006 accepts an in-memory token partly on the strength of this). Citizens are not
 * enrolled: they authenticate with a code sent to their phone, which is already a
 * possession factor, and adding TOTP would push people off the service entirely.
 *
 * TOTP is RFC 6238 via `pragmarx/google2fa` rather than hand-rolled — the algorithm is
 * simple, but base32 handling and drift windows are where implementations quietly go
 * wrong.
 */
final class MultiFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly IdentityAudit $audit,
    ) {}

    /**
     * Starts enrolment. The factor is created UNCONFIRMED and does not gate sign-in until
     * the holder proves they can generate a code — otherwise a mistyped setup locks a
     * caseworker out of their own account.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginEnrolment(Account $account): array
    {
        if (! $account->requiresMultiFactor()) {
            throw new ApiException(ErrorCode::Forbidden, 'Multi-factor authentication applies to staff accounts.');
        }

        $secret = $this->google2fa->generateSecretKey();

        MfaFactor::query()->updateOrCreate(
            ['account_id' => $account->id, 'type' => 'totp'],
            ['secret' => $secret, 'confirmed_at' => null, 'last_used_timestep' => null],
        );

        $this->audit->record($account, 'identity.mfa-enrolment-started', 'TOTP enrolment started');

        return [
            'secret' => $secret,
            // The provisioning URI carries the secret, so it is returned once to the
            // enrolling user over their authenticated session and never logged.
            'otpauth_uri' => $this->google2fa->getQRCodeUrl(
                (string) config('identity.mfa.issuer'),
                (string) ($account->email ?? $account->uuid),
                $secret,
            ),
        ];
    }

    /**
     * Confirms enrolment and returns the one-time recovery codes.
     *
     * @return list<string> plaintext recovery codes — shown once, stored only as hashes
     */
    public function confirmEnrolment(Account $account, string $code): array
    {
        /** @var MfaFactor|null $factor */
        $factor = $account->mfaFactors()->where('type', 'totp')->first();

        if ($factor === null) {
            throw new ApiException(ErrorCode::Conflict, 'Start enrolment before confirming it.');
        }

        if ($this->verifyTotp($factor, $code) === null) {
            throw new ApiException(ErrorCode::Unauthenticated, 'That code is not valid.');
        }

        $factor->forceFill(['confirmed_at' => now()])->save();

        $this->audit->record($account, 'identity.mfa-enabled', 'TOTP confirmed and enabled');

        return $this->regenerateRecoveryCodes($account);
    }

    /**
     * Verifies a second factor: a TOTP code, or a recovery code as fallback.
     */
    public function verify(Account $account, string $code): bool
    {
        /** @var MfaFactor|null $factor */
        $factor = $account->confirmedTotpFactor();

        if ($factor !== null) {
            $timestep = $this->verifyTotp($factor, $code);

            if ($timestep !== null) {
                // Record the accepted time step so the same code cannot be replayed
                // inside its own validity window (RFC 6238 §5.2).
                $factor->forceFill(['last_used_at' => now(), 'last_used_timestep' => $timestep])->save();
                $this->audit->record($account, 'identity.mfa-verified', 'Second factor accepted (TOTP)');

                return true;
            }
        }

        return $this->consumeRecoveryCode($account, $code);
    }

    /**
     * Replaces every recovery code. Used at enrolment and whenever a holder suspects the
     * old list is compromised. Old codes are deleted, not merely marked — a recovery code
     * that still exists is a live credential.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Account $account): array
    {
        return DB::transaction(function () use ($account): array {
            MfaRecoveryCode::query()->where('account_id', $account->id)->delete();

            $codes = [];

            for ($i = 0; $i < (int) config('identity.mfa.recovery_code_count'); $i++) {
                $code = strtoupper(Str::random(5).'-'.Str::random(5));
                $codes[] = $code;

                MfaRecoveryCode::query()->create([
                    'account_id' => $account->id,
                    'code_hash' => OneTimeCodes::hash($code),
                ]);
            }

            $this->audit->record($account, 'identity.mfa-recovery-codes-issued', 'Recovery codes regenerated');

            return $codes;
        });
    }

    /**
     * Removing a factor is itself a privileged act — it lowers the account's protection —
     * so it is audited and requires a currently valid second factor at the HTTP layer.
     */
    public function disable(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $account->mfaFactors()->delete();
            MfaRecoveryCode::query()->where('account_id', $account->id)->delete();
        });

        $this->audit->record($account, 'identity.mfa-disabled', 'Multi-factor authentication disabled');
    }

    /**
     * @return int|null the accepted time step, or null when the code is wrong or replayed
     */
    private function verifyTotp(MfaFactor $factor, string $code): ?int
    {
        $window = (int) config('identity.mfa.window');
        $secret = (string) $factor->secret;

        $result = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            $factor->last_used_timestep === null ? null : (int) $factor->last_used_timestep,
            $window,
        );

        /*
         * verifyKeyNewer has three return shapes, and treating them as one silently
         * rejects every first-ever code:
         *   false — wrong, or from a time step already used (the replay guard);
         *   int   — accepted, and this is the time step it came from;
         *   true  — accepted, but there was no previous time step to compare against.
         *
         * The `true` case is the first successful use after enrolment. It still needs a
         * time step recorded, or the replay guard never engages.
         */
        if ($result === false) {
            return null;
        }

        return is_int($result) ? $result : (int) floor(time() / 30);
    }

    private function consumeRecoveryCode(Account $account, string $code): bool
    {
        return DB::transaction(function () use ($account, $code): bool {
            /** @var MfaRecoveryCode|null $recovery */
            $recovery = MfaRecoveryCode::query()
                ->where('account_id', $account->id)
                ->where('code_hash', OneTimeCodes::hash($code))
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if ($recovery === null) {
                return false;
            }

            $recovery->forceFill(['used_at' => now()])->save();

            $this->audit->record($account, 'identity.mfa-recovery-used', 'Second factor accepted (recovery code)');

            return true;
        });
    }
}
