<?php

declare(strict_types=1);

namespace Modules\Credential\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Credential\Application\CredentialService;
use Modules\Credential\Infrastructure\Eloquent\Credential;
use Modules\Identity\Application\AccountDirectory;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Digital ID: the holder's own credential and QR, staff issuance, and verification.
 *
 * The whole surface is feature-flagged off by default and reports 404 when disabled — a
 * feature that is not live should look absent rather than forbidden (ADR 0011).
 */
final class CredentialController
{
    public function __construct(
        private readonly CredentialService $credentials,
        private readonly AuthorizationService $authorization,
        private readonly AccountDirectory $accounts,
        private readonly ResidentDirectory $residents,
    ) {}

    /**
     * The holder's own credential.
     *
     * Resolved from the account's linked resident, never from a supplied identifier, so
     * there is no path on which one person reaches another's card.
     */
    public function showOwn(Request $request, ActorContext $actor): JsonResponse
    {
        CredentialService::assertEnabled();

        $credential = $this->ownCredentialOrFail($actor);

        return ApiResponse::item([
            'id' => $credential->uuid,
            'serial' => $credential->serial,
            'status' => $credential->status->value,
            'issued_at' => $credential->issued_at?->toIso8601ZuluString(),
            'expires_at' => $credential->expires_at?->toIso8601ZuluString(),
        ]);
    }

    /**
     * Mints a short-lived QR payload.
     *
     * Generated per request and never stored: a payload sitting in a table is one that can
     * be stolen from it, and whose expiry is decided by whoever reads the row.
     */
    public function mintQr(Request $request, ActorContext $actor): JsonResponse
    {
        CredentialService::assertEnabled();

        $minted = $this->credentials->mintQrPayload($this->ownCredentialOrFail($actor));

        return ApiResponse::item([
            'payload' => $minted['payload'],
            'expires_at' => $minted['expires_at']->toIso8601ZuluString(),
            'expires_in_seconds' => (int) config('credential.qr.ttl_seconds'),
        ]);
    }

    /**
     * Verifies a scanned payload.
     *
     * Authenticated so that scans are attributable, but requires no permission: a verifier
     * device at a counter is not staff, and the answer it receives is deliberately too
     * thin to be worth attacking for.
     */
    public function verify(Request $request, ActorContext $actor): JsonResponse
    {
        CredentialService::assertEnabled();

        $validated = $request->validate([
            'payload' => ['required', 'string', 'max:2048'],
        ]);

        $result = $this->credentials->verify($validated['payload'], $actor->subjectId);

        /*
         * THE MINIMAL RESPONSE (ADR 0011 §3).
         *
         * A verdict, the serial that was checked, and — only when valid — the holder's
         * given and family name so a human can match the face in front of them.
         *
         * Absent by design: birth date, address, barangay, sectors, income, household,
         * case history, account, and any identifier that could be used to look those up.
         * A kiosk operator is not an LGU case worker.
         */
        $payload = [
            'outcome' => $result['outcome']->value,
            'valid' => $result['outcome']->isValid(),
        ];

        if (isset($result['credential'])) {
            $payload['serial'] = $result['credential']->serial;
            $payload['expires_at'] = $result['credential']->expires_at?->toIso8601ZuluString();
        }

        if (isset($result['holder_name'])) {
            $payload['holder_name'] = $result['holder_name'];
        }

        return ApiResponse::item($payload);
    }

    /**
     * Staff issue a credential to a verified resident.
     */
    public function issue(Request $request, ActorContext $actor): JsonResponse
    {
        CredentialService::assertEnabled();
        $this->authorization->authorize($actor, Permission::CredentialManage);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
        ]);

        $resident = $this->residents->summaryFor($validated['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident record was not found.');
        }

        // Scope, not just permission: holding `credential.manage` does not make every
        // resident in the municipality yours to issue for. Out of scope reads as NOT
        // FOUND so a guessed id cannot confirm the record exists (ADR 0012).
        $this->authorization->authorizeBarangay(
            $actor,
            $resident->barangayId,
            'That resident record was not found.',
        );

        $credential = $this->credentials->issue($resident, $actor->subjectId);

        return ApiResponse::item([
            'id' => $credential->uuid,
            'serial' => $credential->serial,
            'status' => $credential->status->value,
            'expires_at' => $credential->expires_at?->toIso8601ZuluString(),
        ], 201);
    }

    public function revoke(Request $request, ActorContext $actor, string $credential): JsonResponse
    {
        CredentialService::assertEnabled();
        $this->authorization->authorize($actor, Permission::CredentialManage);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var Credential|null $model */
        $model = Credential::query()->where('uuid', $credential)->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That credential was not found.');
        }

        // Revocation is scoped too. Revoking somebody else's barangay's card is a denial
        // of service against a resident the actor has no business touching.
        $holder = $this->residents->summaryFor((string) $model->resident_id);
        $this->authorization->authorizeBarangay(
            $actor,
            $holder?->barangayId,
            'That credential was not found.',
        );

        $this->credentials->revoke($model, $validated['reason'], $actor->subjectId);

        return ApiResponse::item(['status' => 'revoked']);
    }

    private function ownCredentialOrFail(ActorContext $actor): Credential
    {
        $residentId = $this->accounts->residentIdFor((string) $actor->subjectId);

        /** @var Credential|null $credential */
        $credential = $residentId === null ? null : Credential::query()
            ->where('resident_id', $residentId)
            ->orderByDesc('id')
            ->first();

        if ($credential === null) {
            throw ResourceNotFoundException::make('You do not have a digital ID.');
        }

        return $credential;
    }
}
