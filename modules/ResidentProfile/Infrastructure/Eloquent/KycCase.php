<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\ResidentProfile\Contracts\KycStatus;

/**
 * An onboarding case: someone claims to be a particular person, and it is being checked.
 *
 * The claim lives here, not in `residents`, until a reviewer accepts it. That separation
 * is the whole point — writing an unverified assertion into the canonical record is how it
 * quietly becomes official data.
 *
 * @property KycStatus $status
 * @property string $uuid
 */
final class KycCase extends Model
{
    protected $table = 'kyc_cases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'claimed_birth_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $case): void {
            $case->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<KycDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_case_id');
    }

    /** @return HasMany<ResidentMatchCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(ResidentMatchCandidate::class, 'kyc_case_id');
    }

    /** @return HasMany<KycCaseTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(KycCaseTransition::class, 'kyc_case_id');
    }

    public function claimedFullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->claimed_first_name,
            $this->claimed_middle_name,
            $this->claimed_last_name,
            $this->claimed_suffix,
        ])));
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }
}
