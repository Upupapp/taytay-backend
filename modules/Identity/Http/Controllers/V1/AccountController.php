<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\ContactVerificationService;
use Modules\Identity\Application\DeviceService;
use Modules\Identity\Application\MultiFactorService;
use Modules\Identity\Application\TokenService;
use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The authenticated account's own surface: who am I, my sessions, my devices, my MFA, my
 * contact details.
 *
 * Every route here is scoped to the caller by construction — the account is resolved from
 * the token, never from a client-supplied identifier — so there is no path on which one
 * person can reach another's sessions or devices (OWASP API1).
 *
 * `GET /me` is where the client learns its **server-resolved** permissions, closing gap
 * G-04: the Angular console currently derives them from a local role map, which drifts.
 * The list is advisory for the UI; the server re-checks every one of them per request.
 */
final class AccountController
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly DeviceService $devices,
        private readonly MultiFactorService $mfa,
        private readonly ContactVerificationService $contacts,
    ) {}

    public function show(Request $request, ActorContext $actor): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        return ApiResponse::item([
            'id' => $account->uuid,
            'account_type' => $account->account_type->value,
            'status' => $account->status->value,
            'display_name' => $account->display_name,
            'email' => $account->email,
            'email_verified' => $account->email_verified_at !== null,
            'mobile_number' => $account->mobile_number,
            'mobile_verified' => $account->mobile_verified_at !== null,
            'mfa_enabled' => $account->confirmedTotpFactor() !== null,
            'last_signed_in_at' => $account->last_signed_in_at?->toIso8601ZuluString(),

            // Server-resolved. The client mirrors this; it never computes it (ADR 0002).
            'permissions' => $actor->permissions,
            'roles' => $actor->roles,

            /*
             * The resident this account may act for, if any — an identifier only.
             *
             * Holding it grants nothing: reading that resident's record is a separate
             * authorization decision made per object. An account with a resident_id and no
             * permission sees no more than one without.
             */
            'resident_id' => $account->resident_id,
        ]);
    }

    public function listSessions(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();
        $current = $account->currentAccessToken();

        return ApiResponse::item(
            $this->tokens->listActive($account)->map(fn ($token): array => [
                'id' => $token->uuid,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601ZuluString(),
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
                'current' => $current !== null && $current->getKey() === $token->getKey(),
            ])->all(),
        );
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        if (! $this->tokens->revokeById($account, $session)) {
            // 404 rather than 403: confirming that a session id exists but belongs to
            // someone else is itself a disclosure (conventions §4).
            throw ResourceNotFoundException::make('That session was not found.');
        }

        return ApiResponse::item(['status' => 'revoked']);
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        return ApiResponse::item(['status' => 'revoked', 'count' => $this->tokens->revokeAll($account, 'requested by holder')]);
    }

    public function listDevices(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        return ApiResponse::item(
            $this->devices->listActive($account)->map(fn ($device): array => [
                'id' => $device->uuid,
                'display_name' => $device->display_name,
                'platform' => $device->platform,
                'last_seen_at' => $device->last_seen_at?->toIso8601ZuluString(),
                // The push token is never returned: it is a sending credential, and the
                // client already has its own copy.
            ])->all(),
        );
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:128'],
            'platform' => ['required', 'string', 'in:ios,android,web,other'],
            'push_token' => ['nullable', 'string', 'max:512'],
        ]);

        /** @var Account $account */
        $account = $request->user();

        $device = $this->devices->register(
            $account,
            $validated['fingerprint'],
            $validated['display_name'],
            $validated['platform'],
            $validated['push_token'] ?? null,
        );

        return ApiResponse::item(['id' => $device->uuid, 'display_name' => $device->display_name], 201);
    }

    public function revokeDevice(Request $request, string $device): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        if (! $this->devices->revoke($account, $device)) {
            throw ResourceNotFoundException::make('That device was not found.');
        }

        return ApiResponse::item(['status' => 'revoked']);
    }

    public function beginMfaEnrolment(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        // Returned once, over the caller's authenticated session, and never logged.
        return ApiResponse::item($this->mfa->beginEnrolment($account), 201);
    }

    public function confirmMfaEnrolment(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);

        /** @var Account $account */
        $account = $request->user();

        return ApiResponse::item([
            'status' => 'enabled',
            // Shown exactly once. There is no endpoint to read them back.
            'recovery_codes' => $this->mfa->confirmEnrolment($account, $validated['code']),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);

        /** @var Account $account */
        $account = $request->user();

        // Re-prove the second factor first: otherwise a hijacked session could mint
        // itself a fresh set of recovery codes and keep access after the password changes.
        if (! $this->mfa->verify($account, $validated['code'])) {
            throw ResourceNotFoundException::make('That code is not valid.');
        }

        return ApiResponse::item(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($account)]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);

        /** @var Account $account */
        $account = $request->user();

        // Disabling protection is itself privileged, so it needs a current second factor.
        if (! $this->mfa->verify($account, $validated['code'])) {
            throw ResourceNotFoundException::make('That code is not valid.');
        }

        $this->mfa->disable($account);

        return ApiResponse::item(['status' => 'disabled']);
    }

    public function requestContactVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:email,mobile'],
        ]);

        /** @var Account $account */
        $account = $request->user();

        $code = $this->contacts->request(
            $account,
            $validated['channel'] === 'email' ? VerificationPurpose::VerifyEmail : VerificationPurpose::VerifyMobile,
        );

        unset($code); // Delivered by Notification; never returned, never logged.

        return ApiResponse::item(['status' => 'accepted'], 202);
    }

    public function confirmContactVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:email,mobile'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        /** @var Account $account */
        $account = $request->user();

        $this->contacts->confirm(
            $account,
            $validated['channel'] === 'email' ? VerificationPurpose::VerifyEmail : VerificationPurpose::VerifyMobile,
            $validated['code'],
        );

        return ApiResponse::item(['status' => 'verified']);
    }
}
