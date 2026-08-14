<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Eloquent;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Contracts\AccountType;

/**
 * The authenticatable account.
 *
 * An account is a way to sign in. It is **not** a resident, and holding one grants no
 * access to any resident or case record — that is decided per object by AccessControl
 * (ADR 0002). `resident_id` links to ResidentProfile by identifier only, with no foreign
 * key and no relationship method, because a cross-module Eloquent relation would let any
 * caller sidestep that decision with a single `->resident` (CLAUDE.md Article 2.2).
 *
 * @property string $uuid
 * @property AccountType $account_type
 * @property AccountStatus $status
 */
final class Account extends Authenticatable
{
    /** @use HasFactory<AccountFactory> */
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'accounts';

    protected $guarded = ['id'];

    /**
     * Never serialised, never dumped, never in a stack trace's model state.
     *
     * @var list<string>
     */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'last_signed_in_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    /**
     * Laravel guesses a factory from the model's namespace, which only works for models
     * under `App\Models`. Modules live elsewhere by design (ADR 0001), so the mapping is
     * declared rather than inferred.
     */
    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $account): void {
            // v7 keeps the unique index appending at its right-hand edge (ADR 0008 §1).
            $account->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * Sanctum and the auth guard read the password from here. Named `password_hash` in the
     * schema so nothing in a query log or an error message can be mistaken for a password.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<MfaFactor, $this> */
    public function mfaFactors(): HasMany
    {
        return $this->hasMany(MfaFactor::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Deny by default: an account must be explicitly active and not locked out.
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate() && ! $this->isLocked() && $this->deleted_at === null;
    }

    public function requiresMultiFactor(): bool
    {
        return $this->account_type->requiresMultiFactor();
    }

    public function confirmedTotpFactor(): ?MfaFactor
    {
        return $this->mfaFactors()
            ->where('type', 'totp')
            ->whereNotNull('confirmed_at')
            ->first();
    }
}
