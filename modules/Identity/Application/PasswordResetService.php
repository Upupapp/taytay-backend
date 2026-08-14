<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Identity\Infrastructure\Eloquent\PasswordReset;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Password reset for staff accounts.
 *
 * Citizens have no password — they sign in with a code to their mobile — so there is
 * nothing here to reset for them, and pretending otherwise would create a second
 * credential to protect for no benefit.
 *
 * The token is high-entropy, single-use, time-bound, and stored only as a SHA-256 hash.
 * Requesting a reset never reveals whether the address is registered.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly IdentityAudit $audit,
    ) {}

    /**
     * Issues a reset token, or does nothing when no staff account matches.
     *
     * @return string|null the plaintext token to put in the emailed link — never logged,
     *                     never returned to the requester in an API response
     */
    public function request(string $email, ?string $requestedIp): ?string
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('email', $email)
            ->where('account_type', AccountType::Staff->value)
            ->first();

        if ($account === null || ! $account->canAuthenticate()) {
            $this->audit->recordUnknownSubjectFailure('identity.password-reset-ignored', 'Password reset requested for an unknown or inactive address');

            return null;
        }

        $token = bin2hex(random_bytes((int) config('identity.password_reset.token_bytes')));

        DB::transaction(function () use ($account, $token, $requestedIp): void {
            // Invalidate outstanding requests. Several live tokens multiply the window in
            // which any one of them can be intercepted.
            PasswordReset::query()
                ->where('account_id', $account->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            PasswordReset::query()->create([
                'account_id' => $account->id,
                'token_hash' => OneTimeCodes::hash($token),
                'expires_at' => now()->addMinutes((int) config('identity.password_reset.ttl_minutes')),
                'requested_ip' => $requestedIp,
            ]);
        });

        $this->audit->record($account, 'identity.password-reset-requested', 'Password reset token issued');

        return $token;
    }

    /**
     * Consumes a token and sets a new password.
     *
     * On success every existing session is revoked. If the reset was an attacker, the real
     * holder's sessions die; if it was the holder, an attacker's stolen session dies.
     * Either way the ambiguity ends rather than persisting silently.
     */
    public function reset(string $token, string $newPassword): void
    {
        DB::transaction(function () use ($token, $newPassword): void {
            /** @var PasswordReset|null $reset */
            $reset = PasswordReset::query()
                ->where('token_hash', OneTimeCodes::hash($token))
                ->lockForUpdate()
                ->first();

            // A replayed token and an unknown token get the same answer. The row is kept
            // rather than deleted so the replay is distinguishable in the audit trail,
            // even though it is not distinguishable to the caller.
            if ($reset === null || ! $reset->isUsable()) {
                if ($reset !== null) {
                    $this->audit->record($reset->account, 'identity.password-reset-replayed', 'Reset token reused or expired');
                }

                throw new ApiException(ErrorCode::Unauthenticated, 'That reset link is no longer valid. Please request a new one.');
            }

            /** @var Account $account */
            $account = $reset->account;

            $reset->forceFill(['consumed_at' => now()])->save();

            $account->forceFill([
                'password_hash' => $newPassword,
                'failed_sign_in_count' => 0,
                'locked_until' => null,
            ])->save();

            $this->tokens->revokeAll($account, 'password reset');

            $this->audit->record($account, 'identity.password-reset-completed', 'Password reset and all sessions revoked');
        });
    }
}
