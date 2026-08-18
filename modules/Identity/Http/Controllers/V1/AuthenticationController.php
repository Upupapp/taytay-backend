<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Identity\Application\AuthenticationService;
use Modules\Identity\Application\PasswordResetService;
use Modules\Identity\Application\TokenService;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Http\ApiResponse;

/**
 * Unauthenticated sign-in surface, plus sign-out.
 *
 * Controllers here do what CLAUDE.md Article 3.2 allows and nothing else: validate shape,
 * call the application service, shape the response. Every decision — whether an account
 * exists, whether a secret matched, whether MFA is needed — is made in the service, once,
 * for every channel.
 *
 * Note what is absent: no endpoint here returns anything about a resident or a case.
 * Authentication produces a token; what that token may reach is decided per object,
 * later, by AccessControl (ADR 0002).
 */
final class AuthenticationController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly TokenService $tokens,
        private readonly PasswordResetService $passwordResets,
    ) {}

    /**
     * Staff sign-in. Returns a token, or an MFA challenge when a second factor is enrolled.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['nullable', 'string', 'max:128'],
        ]);

        $result = $this->authentication->signInWithPassword(
            $validated['email'],
            $validated['password'],
            self::channel($request),
            $validated['device_name'] ?? null,
        );

        if ($result['status'] === 'mfa-enrolment-required') {
            /*
             * 200, and a token — but a restricted one. The password was correct
             * and the account requires a second factor it has never enrolled, so
             * the only thing this session may do is enrol one.
             */
            return ApiResponse::item(
                ['status' => 'mfa-enrolment-required'] + self::tokenPayload($result),
            );
        }

        if ($result['status'] === 'mfa-required') {
            // 200, not 401: the password was correct. The client must present a second
            // factor against this challenge.
            return ApiResponse::item([
                'status' => 'mfa-required',
                'challenge' => $result['challenge'],
                'expires_in_minutes' => (int) config('identity.mfa.challenge_ttl_minutes'),
            ]);
        }

        return ApiResponse::item(self::tokenPayload($result), 201);
    }

    /**
     * Staff sign-in, second step.
     */
    public function completeMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $result = $this->authentication->completeMultiFactorChallenge(
            $validated['challenge'],
            $validated['code'],
            self::channel($request),
        );

        return ApiResponse::item(self::tokenPayload($result), 201);
    }

    /**
     * Citizen sign-in, step one.
     *
     * Always 202, whether or not the number is registered. A different response would turn
     * this into a "does this person hold an account here" lookup.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string', 'max:32'],
        ]);

        /*
         * The outcome is deliberately ignored, and the code never comes back here at all.
         *
         * It used to: this called a method that returned the code and then did `unset($code)` to
         * throw it away. That worked, and put the guarantee in the wrong file — the mint was in
         * `Identity/Application` and the discipline was in a controller, so nothing stopped a
         * future reader from returning it, logging it, or asserting on it in a test.
         * `requestSignInCode` now sends it and hands back only whether it left.
         *
         * And *that* is ignored on purpose. A response that differed when delivery was skipped
         * would answer the question this endpoint exists to refuse: does this person hold an
         * account here. The delivery outcome is in the audit trail, where an operator can see it
         * and an attacker cannot.
         */
        $this->authentication->requestSignInCode($validated['mobile_number']);

        return ApiResponse::item(
            ['status' => 'accepted', 'message' => 'If that number is registered, a code has been sent to it.'],
            202,
        );
    }

    /**
     * Citizen sign-in, step two.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:16'],
            'device_name' => ['nullable', 'string', 'max:128'],
        ]);

        $result = $this->authentication->verifySignInCode(
            $validated['mobile_number'],
            $validated['code'],
            self::channel($request),
            $validated['device_name'] ?? null,
        );

        return ApiResponse::item(self::tokenPayload($result), 201);
    }

    /**
     * Sign out — revokes the token that made this request.
     */
    public function destroyCurrent(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        $this->tokens->revokeCurrent($account);

        return ApiResponse::item(['status' => 'signed-out']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
        ]);

        $token = $this->passwordResets->request($validated['email'], $request->ip());

        // Same caveat as the sign-in code: delivery belongs to Notification. The token is
        // never returned and never logged.
        unset($token);

        return ApiResponse::item(
            ['status' => 'accepted', 'message' => 'If that address belongs to a staff account, a reset link has been sent.'],
            202,
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:256'],
            // Length over composition rules, per NIST SP 800-63B: long passphrases beat
            // short passwords with mandated symbol classes, which mostly produce
            // predictable substitutions.
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        $this->passwordResets->reset($validated['token'], $validated['password']);

        return ApiResponse::item(['status' => 'password-reset']);
    }

    /**
     * @param  array{token?: string, expires_at?: Carbon}  $result
     * @return array<string, mixed>
     */
    private static function tokenPayload(array $result): array
    {
        return [
            'token' => $result['token'] ?? null,
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']?->toIso8601ZuluString(),
        ];
    }

    private static function channel(Request $request): ClientChannel
    {
        $channel = $request->attributes->get('client_channel');

        return $channel instanceof ClientChannel ? $channel : ClientChannel::Unknown;
    }
}
