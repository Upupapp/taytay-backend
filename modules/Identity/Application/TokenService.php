<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\ClientChannel;

/**
 * Issues and revokes bearer tokens.
 *
 * Tokens are Sanctum personal access tokens (ADR 0005/0006). Reused rather than
 * reimplemented: Sanctum already stores a SHA-256 hash instead of the token, and a
 * hand-rolled store is a needless opportunity to get hashing, lookup or revocation wrong.
 *
 * The plaintext token exists exactly once, in the response to the request that created it.
 * It is never stored, never logged and cannot be recovered — a lost token is replaced, not
 * looked up.
 *
 * IMPORTANT: an ability on a token is a *coarse capability of the client*, not an
 * authorization decision. `assistance:write` does not mean "may write to this case"; every
 * object-level decision is still made by AccessControl from the actor and the object
 * (ADR 0002). Abilities exist so a token minted for a kiosk cannot be used to run the
 * admin console.
 */
final class TokenService
{
    public function __construct(private readonly IdentityAudit $audit) {}

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function issue(Account $account, ClientChannel $channel, ?string $deviceName = null): array
    {
        $ttl = $account->account_type->requiresMultiFactor()
            ? (int) config('identity.token_ttl_minutes.staff')
            : (int) config('identity.token_ttl_minutes.citizen');

        $expiresAt = now()->addMinutes($ttl);

        // Named for the channel so a person can recognise their own sessions in the
        // session list and revoke the one they do not recognise.
        $name = trim(($deviceName ?? 'Unnamed device').' · '.$channel->value);

        $token = $account->createToken($name, $this->abilitiesFor($account), $expiresAt);

        $account->forceFill([
            'last_signed_in_at' => now(),
            'failed_sign_in_count' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->record($account, 'identity.token-issued', "Token issued for {$channel->value}");

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt];
    }

    /**
     * Revokes the token used to make the current request — an ordinary sign-out.
     */
    public function revokeCurrent(Account $account): void
    {
        $token = $account->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->audit->record($account, 'identity.token-revoked', 'Current session signed out');
    }

    /**
     * Revokes one session by its public identifier.
     *
     * Scoped to the account's own tokens, so a caller cannot revoke somebody else's
     * session by guessing an id — the classic broken-object-authorization defect
     * (OWASP API1).
     */
    public function revokeById(Account $account, string $tokenUuid): bool
    {
        $deleted = $account->tokens()->where('uuid', $tokenUuid)->delete();

        if ($deleted > 0) {
            $this->audit->record($account, 'identity.token-revoked', 'Session revoked by holder');
        }

        return $deleted > 0;
    }

    /**
     * Revokes every session, including the current one.
     *
     * This is the control a person uses when they have lost a phone, and the control the
     * system uses after a password reset.
     */
    public function revokeAll(Account $account, string $reason): int
    {
        $count = $account->tokens()->delete();

        $this->audit->record($account, 'identity.tokens-revoked-all', "All sessions revoked: {$reason}");

        return $count;
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function listActive(Account $account): Collection
    {
        return $account->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * Coarse client capabilities. Staff and citizens get different sets so a citizen
     * token cannot drive a staff-only endpoint even before authorization runs.
     *
     * @return list<string>
     */
    private function abilitiesFor(Account $account): array
    {
        return $account->account_type->requiresMultiFactor()
            ? ['staff']
            : ['citizen'];
    }
}
