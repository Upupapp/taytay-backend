<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\ResidentProfile\Contracts\VerificationTier;

/**
 * The canonical record of a person the LGU serves.
 *
 * Not an account, and not a KYC case. Nothing here is written by registration — a resident
 * exists only because a reviewer created or linked one (ADR 0010).
 *
 * @property string $uuid
 * @property VerificationTier $verification_tier
 */
final class Resident extends Model
{
    use SoftDeletes;

    protected $table = 'residents';

    protected $guarded = ['id'];

    /**
     * Never serialised by accident. `philsys_last_four` is encrypted at rest and must be
     * asked for explicitly, by a code path that has already made an authorization
     * decision — not swept up by a `toArray()` somewhere.
     *
     * @var list<string>
     */
    protected $hidden = ['philsys_last_four', 'identity_fingerprint'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'verification_tier' => VerificationTier::class,
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
            'philsys_last_four' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $resident): void {
            $resident->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<ResidentSector, $this> */
    public function sectors(): HasMany
    {
        return $this->hasMany(ResidentSector::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }

    /**
     * The name a verifier is shown — given name plus family name, no middle name and no
     * suffix. Enough for a human to match a face to a card, and no more (ADR 0011 §3).
     */
    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isVerified(): bool
    {
        return $this->verification_tier === VerificationTier::Verified;
    }
}
