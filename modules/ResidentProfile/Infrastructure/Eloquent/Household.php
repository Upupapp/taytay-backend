<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Who sleeps under one roof.
 *
 * Not a family — see the migration. A household may contain several, and welfare programmes
 * target the two differently.
 */
final class Household extends Model
{
    use SoftDeletes;

    protected $table = 'households';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'profile_completeness' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->code ??= self::generateCode();
        });
    }

    /** @return HasMany<Family, $this> */
    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    /** @return HasMany<HouseholdMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(HouseholdMembership::class);
    }

    /**
     * How many people live here now.
     *
     * Computed, never stored. A cached count drifts the first time a membership is closed by
     * a path that forgets to decrement it, and the drift is invisible because nothing
     * compares the two (ADR 0014 §2).
     */
    public function currentMemberCount(): int
    {
        return $this->memberships()->whereNull('effective_to')->count();
    }

    /**
     * A random, quotable identifier.
     *
     * Random rather than sequential: a sequential code tells any holder how many households
     * the LGU has enrolled, and lets them guess their neighbour's.
     */
    public static function generateCode(): string
    {
        return 'HH-'.strtoupper(Str::random(10));
    }
}
