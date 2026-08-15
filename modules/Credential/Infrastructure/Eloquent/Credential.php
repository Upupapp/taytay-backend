<?php

declare(strict_types=1);

namespace Modules\Credential\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Credential\Contracts\CredentialStatus;
use Modules\Credential\Contracts\VerificationOutcome;

/**
 * A digital ID.
 *
 * Holds a serial, a status and validity dates — never a copy of the resident record. The
 * holder's details are fetched from the one canonical resident row at verification time
 * and pruned to the minimum (ADR 0011 §3).
 *
 * @property CredentialStatus $status
 */
final class Credential extends Model
{
    protected $table = 'credentials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === CredentialStatus::Active && ! $this->hasExpired();
    }

    /**
     * Expiry is computed from the date rather than trusted from the status column: a
     * credential that lapsed overnight is expired whether or not a job has run to say so.
     */
    public function currentOutcome(): VerificationOutcome
    {
        return match (true) {
            $this->status === CredentialStatus::Revoked => VerificationOutcome::Revoked,
            $this->status === CredentialStatus::Suspended => VerificationOutcome::Suspended,
            $this->hasExpired() => VerificationOutcome::Expired,
            $this->status === CredentialStatus::Active => VerificationOutcome::Valid,
            default => VerificationOutcome::Malformed,
        };
    }
}
