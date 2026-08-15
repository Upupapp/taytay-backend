<?php

declare(strict_types=1);

namespace Modules\Credential\Application;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Credential\Contracts\CredentialStatus;
use Modules\Credential\Contracts\VerificationOutcome;
use Modules\Credential\Infrastructure\Eloquent\Credential;
use Modules\Credential\Infrastructure\Eloquent\CredentialVerification;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ResidentProfile\Application\ResidentProfileAudit;
use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Issues, revokes and verifies digital ID credentials (ADR 0011).
 *
 * The whole module is behind `credential.digital_id.enabled` and is **off by default**: a
 * digital ID is optional to the service, and every resident must be able to receive
 * assistance without one.
 *
 * The verification response is deliberately thin — a status, a serial, validity dates and
 * the holder's display name. A verifier at a counter needs to know that the card is valid
 * and that the face in front of them matches a name. They do not need an address, a
 * sector, an income or a case history, and a kiosk operator is not an LGU case worker.
 */
final class CredentialService
{
    public function __construct(
        private readonly QrPayloadCodec $codec,
        private readonly ResidentDirectory $residents,
        private readonly ResidentProfileAudit $audit,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('credential.digital_id.enabled');
    }

    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            // 404 rather than 403: a disabled feature should look absent, not forbidden.
            throw new ApiException(ErrorCode::NotFound, 'Digital ID is not available.');
        }
    }

    /**
     * Issues a credential to a verified resident.
     *
     * Idempotent: a resident who already holds a live credential gets it back rather than
     * a second one, so a retried request cannot leave two valid cards in circulation.
     */
    public function issue(ResidentSummary $resident, ?string $issuedBy): Credential
    {
        self::assertEnabled();

        /*
         * A credential asserts identity to third parties, so it requires full verification
         * (ADR 0010 §4). Partial verification is deliberately enough to *receive
         * assistance* — the LGU must not make help conditional on paperwork a person
         * cannot produce — but not enough to be handed an identity document.
         */
        if (! $resident->isVerified()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'A digital ID can only be issued to a fully verified resident record.',
            );
        }

        return DB::transaction(function () use ($resident, $issuedBy): Credential {
            /** @var Credential|null $existing */
            $existing = Credential::query()
                ->where('resident_id', $resident->id)
                ->whereIn('status', [CredentialStatus::Issued->value, CredentialStatus::Active->value])
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->hasExpired()) {
                return $existing;
            }

            $credential = Credential::query()->create([
                'resident_id' => $resident->id,
                'serial' => self::generateSerial(),
                'status' => CredentialStatus::Active,
                'issued_at' => now(),
                'activated_at' => now(),
                'expires_at' => now()->addDays((int) config('credential.digital_id.validity_days')),
                'signing_key_id' => $this->codec->activeKeyId(),
                'issued_by' => $issuedBy,
            ]);

            $this->audit->record($issuedBy, 'credential.issued', 'Digital ID issued', $credential->uuid);

            return $credential;
        });
    }

    /**
     * Mints a short-lived QR payload for the holder's own credential.
     *
     * Generated per request rather than stored: a payload that lives in the database is a
     * payload that can be stolen from it, and one whose expiry is decided by whoever reads
     * the row rather than by the clock.
     *
     * @return array{payload: string, expires_at: Carbon}
     */
    public function mintQrPayload(Credential $credential): array
    {
        self::assertEnabled();

        if (! $credential->isUsable()) {
            throw new ApiException(ErrorCode::Conflict, 'That credential is not currently valid.');
        }

        $minted = $this->codec->encode($credential->serial, $credential->signing_key_id);

        return ['payload' => $minted['payload'], 'expires_at' => $minted['expires_at']];
    }

    /**
     * Verifies a scanned payload and returns the minimum a verifier needs.
     *
     * Every outcome is recorded, including failures: a forged or replayed scan is exactly
     * the event worth having in the audit trail.
     *
     * @return array{outcome: VerificationOutcome, credential?: Credential, holder_name?: string}
     */
    public function verify(string $payload, ?string $verifierSubjectId): array
    {
        self::assertEnabled();

        $decoded = $this->codec->decode($payload);

        if ($decoded === null) {
            $this->recordVerification(null, VerificationOutcome::SignatureInvalid, null, $verifierSubjectId);

            return ['outcome' => VerificationOutcome::SignatureInvalid];
        }

        if ($decoded['expires_at']->isPast()) {
            $this->recordVerification(null, VerificationOutcome::Expired, $decoded['nonce'], $verifierSubjectId);

            return ['outcome' => VerificationOutcome::Expired];
        }

        /** @var Credential|null $credential */
        $credential = Credential::query()->where('serial', $decoded['serial'])->first();

        if ($credential === null) {
            $this->recordVerification(null, VerificationOutcome::Malformed, $decoded['nonce'], $verifierSubjectId);

            return ['outcome' => VerificationOutcome::Malformed];
        }

        /*
         * Spend the nonce. The unique index is the enforcement, not a prior SELECT: two
         * simultaneous scans of the same photographed code must not both succeed, and only
         * the database can arbitrate that race.
         */
        try {
            $this->recordVerification($credential, VerificationOutcome::Valid, $decoded['nonce'], $verifierSubjectId);
        } catch (QueryException) {
            $this->recordVerification($credential, VerificationOutcome::Replayed, null, $verifierSubjectId);

            return ['outcome' => VerificationOutcome::Replayed];
        }

        $outcome = $credential->currentOutcome();

        if ($outcome !== VerificationOutcome::Valid) {
            return ['outcome' => $outcome];
        }

        $resident = $this->residents->summaryFor((string) $credential->resident_id);

        return [
            'outcome' => VerificationOutcome::Valid,
            'credential' => $credential,
            // Given name + family name only. Enough for a human to match a face to a card,
            // and nothing that describes the person's circumstances.
            'holder_name' => $resident?->displayName ?? 'Unknown holder',
        ];
    }

    public function revoke(Credential $credential, string $reason, ?string $actorSubjectId): Credential
    {
        self::assertEnabled();

        $credential->forceFill([
            'status' => CredentialStatus::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $this->audit->record($actorSubjectId, 'credential.revoked', 'Digital ID revoked', $credential->uuid);

        return $credential;
    }

    private function recordVerification(
        ?Credential $credential,
        VerificationOutcome $outcome,
        ?string $nonce,
        ?string $verifierSubjectId,
    ): void {
        CredentialVerification::query()->create([
            'credential_id' => $credential?->id,
            'outcome' => $outcome->value,
            'nonce_hash' => $nonce === null ? null : hash('sha256', $nonce),
            'verifier_subject_id' => $verifierSubjectId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Random, not sequential. A sequential serial tells any holder how many IDs the LGU
     * has issued and lets them guess their neighbour's.
     */
    private static function generateSerial(): string
    {
        return 'TAY-'.strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
    }
}
