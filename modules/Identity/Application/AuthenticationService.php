<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\CacheKey;
use Modules\Shared\Contracts\TransactionalDelivery;
use Modules\Shared\Contracts\TransactionalMessage;
use Modules\Shared\Contracts\TransactionalSender;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * The sign-in flows, one per account type.
 *
 * Staff: email + password, then TOTP. Citizens: mobile + one-time code, which is already a
 * possession factor.
 *
 * THREE RULES RUN THROUGH ALL OF IT.
 *
 * 1. **Nothing here decides what the actor may see.** A successful sign-in produces a
 *    token and nothing else. Whether that actor may open a resident, a case or a payout
 *    is decided per object by AccessControl (ADR 0002). Authentication answers "who", not
 *    "may they".
 * 2. **No response distinguishes an unknown account from a wrong secret.** Every failure
 *    is the same message and the same status, and a password is verified against a dummy
 *    hash when no account matched, so the timing does not answer the question the message
 *    refuses to.
 * 3. **No secret is ever logged, returned, or put in an exception message.**
 */
final class AuthenticationService
{
    /**
     * A bcrypt hash of a value nobody knows, used to burn the same CPU time when no
     * account matched. Without it, "no such account" returns measurably faster than
     * "wrong password" and the endpoint becomes an account-existence oracle.
     */
    private const TIMING_EQUALISER = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly OneTimeCodes $codes,
        private readonly MultiFactorService $mfa,
        private readonly IdentityAudit $audit,
        /**
         * The one way out of this module (L-18, F16).
         *
         * An interface from `Notification/Contracts`, never its `Infrastructure/` — Article 2.1.
         * Identity does not know or care whether an SMS provider exists; it knows whether the
         * code left.
         */
        private readonly TransactionalSender $sender,
    ) {}

    /**
     * Staff sign-in, step one.
     *
     * Returns either an issued token, or an MFA challenge when the account has a confirmed
     * factor. The challenge is a short-lived opaque handle held in the cache — never a
     * partially-privileged token, because a token that "only" needs MFA is still a token.
     *
     * @return array{status: 'authenticated'|'mfa-required'|'mfa-enrolment-required', token?: string, expires_at?: Carbon, challenge?: string}
     */
    public function signInWithPassword(string $email, string $password, ClientChannel $channel, ?string $deviceName = null): array
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('email', $email)
            ->where('account_type', AccountType::Staff->value)
            ->first();

        if ($account === null) {
            // Equalise timing, then fail exactly as a wrong password does.
            Hash::check($password, self::TIMING_EQUALISER);
            $this->audit->recordUnknownSubjectFailure('identity.sign-in-failed', 'Password sign-in failed for an unknown or non-staff address');

            throw $this->invalidCredentials();
        }

        if (! $account->canAuthenticate()) {
            $this->audit->record($account, 'identity.sign-in-blocked', 'Sign-in refused: account not active or locked');

            // Same error as a wrong password: telling a caller that an account exists but
            // is locked confirms the account exists.
            throw $this->invalidCredentials();
        }

        if (! Hash::check($password, $account->getAuthPassword())) {
            $this->registerFailure($account);

            throw $this->invalidCredentials();
        }

        if ($account->requiresMultiFactor()) {
            if ($account->confirmedTotpFactor() !== null) {
                return ['status' => 'mfa-required', 'challenge' => $this->issueChallenge($account, $channel, $deviceName)];
            }

            /*
             * REQUIRED, AND NOT ENROLLED.
             *
             * This branch used to fall through to a full session: the condition
             * was `requiresMultiFactor() && confirmedTotpFactor() !== null`, so
             * an account that had simply never enrolled signed in on a password
             * alone. That made the second factor **opt-in by enrolment** — a
             * second factor staff may decline is a second factor the office
             * does not have.
             *
             * Refusing outright would be a lockout rather than a control:
             * `POST me/mfa` is itself authenticated, so an office with nobody
             * enrolled would have no route to compliance. Instead the account
             * gets a token that reaches enrolment and nothing else, enforced by
             * `EnforceTokenAbilities` rather than by the client behaving.
             */
            $this->audit->record(
                $account,
                'identity.mfa-enrolment-required',
                'Sign-in restricted to second-factor enrolment: the account requires a second factor and has none',
            );

            return ['status' => 'mfa-enrolment-required']
                + $this->tokens->issueForMfaEnrolment($account, $channel, $deviceName);
        }

        return ['status' => 'authenticated'] + $this->tokens->issue($account, $channel, $deviceName);
    }

    /**
     * Staff sign-in, step two: a TOTP code or a recovery code against a live challenge.
     *
     * @return array{status: 'authenticated', token: string, expires_at: Carbon}
     */
    public function completeMultiFactorChallenge(string $challenge, string $code, ClientChannel $channel): array
    {
        $payload = Cache::get($this->challengeKey($challenge));

        if (! is_array($payload)) {
            throw new ApiException(ErrorCode::Unauthenticated, 'That sign-in attempt has expired. Please sign in again.');
        }

        /** @var Account|null $account */
        $account = Account::query()->find($payload['account_id'] ?? 0);

        if ($account === null || ! $account->canAuthenticate()) {
            Cache::forget($this->challengeKey($challenge));

            throw $this->invalidCredentials();
        }

        if (! $this->mfa->verify($account, $code)) {
            $this->registerFailure($account);

            throw new ApiException(ErrorCode::Unauthenticated, 'That code is not valid.');
        }

        // One challenge, one token. Burn it so a captured challenge cannot be replayed.
        Cache::forget($this->challengeKey($challenge));

        return ['status' => 'authenticated'] + $this->tokens->issue($account, $channel, $payload['device_name'] ?? null);
    }

    /**
     * Citizen sign-in, step one: send a code to a registered mobile number.
     *
     * Returns the plaintext code for the caller to deliver, or null when no account
     * matched. The HTTP layer responds identically either way — a different response for
     * an unregistered number turns this endpoint into a "is this person a resident here"
     * lookup, which for a VAWC survivor is a safety issue, not a privacy nicety.
     */
    /**
     * Issues a sign-in code **and sends it**.
     *
     * ---
     *
     * **RETURNS THE OUTCOME, NEVER THE CODE.** This used to return the code itself and rely on
     * `AuthenticationController` doing `unset($code)` — correct, and the wrong place for the
     * guarantee. A secret that leaves the module it was minted in is a secret that eventually
     * reaches a log, a response body or a test fixture, because the discipline that keeps it
     * from doing so lives in a different file from the mint. Now it cannot: the code exists in
     * one local variable, is handed to the sender, and goes out of scope.
     *
     * **F16 was here.** The code was issued, hashed, recorded and discarded, and nothing carried
     * it to a person — so no resident could sign in to this platform at all, which made every
     * other feature unreachable. The gap was a missing seam rather than a missing adapter; see
     * [TransactionalSender].
     *
     * **The answer is the same for an unknown number.** A `skipped` result for a number this
     * platform does not hold is indistinguishable from a `skipped` result for one it does, and
     * the caller returns 202 either way. Anything else turns sign-in into a lookup for whether
     * somebody holds an account here.
     */
    public function requestSignInCode(string $mobileNumber): TransactionalDelivery
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('mobile_number', $mobileNumber)
            ->where('account_type', AccountType::Citizen->value)
            ->first();

        if ($account === null || ! $account->canAuthenticate()) {
            $this->audit->recordUnknownSubjectFailure('identity.code-request-ignored', 'Sign-in code requested for an unknown or inactive mobile number');

            return TransactionalDelivery::skipped('No active citizen account holds that number.');
        }

        $code = $this->codes->issue($account, VerificationPurpose::SignIn);

        $result = $this->sender->send(new TransactionalMessage(
            recipient: $mobileNumber,
            purpose: 'sign-in-code',
            /*
             * Names the municipality, states the expiry, and tells the reader what to do if they
             * did not ask — the three things that make a code message hard to use for phishing.
             * No link: a one-time code arriving with a tappable URL trains residents to tap
             * links in texts claiming to be from their LGU.
             */
            text: sprintf(
                'Your Taytay LGU sign-in code is %s. It expires in %d minutes. If you did not ask to sign in, ignore this message.',
                $code,
                (int) config('identity.one_time_code.ttl_minutes'),
            ),
        ));

        /*
         * Recorded whichever way it went. "A code was issued but not delivered" is precisely the
         * state F16 described, and it survived an entire integration sequence because nothing
         * wrote it down anywhere an operator would look.
         */
        $this->audit->record(
            $account,
            $result->wasSent() ? 'identity.code-sent' : 'identity.code-undelivered',
            sprintf('Sign-in code delivery via %s: %s', $this->sender->name(), $result->status),
        );

        return $result;
    }

    /**
     * Citizen sign-in, step two.
     *
     * @return array{status: 'authenticated', token: string, expires_at: Carbon}
     */
    public function verifySignInCode(string $mobileNumber, string $code, ClientChannel $channel, ?string $deviceName = null): array
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('mobile_number', $mobileNumber)
            ->where('account_type', AccountType::Citizen->value)
            ->first();

        if ($account === null || ! $account->canAuthenticate() || ! $this->codes->consume($account, VerificationPurpose::SignIn, $code)) {
            if ($account !== null) {
                $this->registerFailure($account);
            }

            throw $this->invalidCredentials();
        }

        // Signing in with a code to the registered number proves control of it.
        if ($account->mobile_verified_at === null) {
            $account->forceFill(['mobile_verified_at' => now()])->save();
        }

        return ['status' => 'authenticated'] + $this->tokens->issue($account, $channel, $deviceName);
    }

    /**
     * Counts a failure and locks the account once the threshold is crossed.
     *
     * A timed lockout, not a permanent one: a permanent block would be a
     * denial-of-service primitive against any staff member whose email address is known.
     */
    private function registerFailure(Account $account): void
    {
        $account->increment('failed_sign_in_count');

        if ($account->failed_sign_in_count >= (int) config('identity.lockout.max_failed_attempts')) {
            $account->forceFill([
                'locked_until' => now()->addMinutes((int) config('identity.lockout.minutes')),
                'failed_sign_in_count' => 0,
            ])->save();

            $this->audit->record($account, 'identity.account-locked', 'Account locked after repeated failed sign-ins');

            return;
        }

        $account->save();
        $this->audit->record($account, 'identity.sign-in-failed', 'Failed sign-in attempt');
    }

    private function issueChallenge(Account $account, ClientChannel $channel, ?string $deviceName): string
    {
        $challenge = Str::random(64);

        Cache::put(
            $this->challengeKey($challenge),
            ['account_id' => $account->id, 'channel' => $channel->value, 'device_name' => $deviceName],
            now()->addMinutes((int) config('identity.mfa.challenge_ttl_minutes')),
        );

        $this->audit->record($account, 'identity.mfa-challenged', 'Password accepted; awaiting second factor');

        return $challenge;
    }

    /**
     * Built through `CacheKey` like every other cache key in this system (ADR 0036 §4).
     *
     * Neither public nor actor-scoped: at the moment this is read **nobody is authenticated yet**,
     * which is the point of a challenge. It is keyed by the presented token, hashed — so the store
     * never holds the value a caller presents, and anyone who could read the cache still could not
     * complete somebody else's second factor.
     */
    private function challengeKey(string $challenge): string
    {
        return CacheKey::forOpaqueToken('identity.mfa-challenge', $challenge);
    }

    private function invalidCredentials(): ApiException
    {
        // One message for every failure mode. The client cannot tell "no such account"
        // from "wrong password" from "locked", and neither can an attacker enumerating.
        return new ApiException(ErrorCode::Unauthenticated, 'Those sign-in details are not valid.');
    }
}
