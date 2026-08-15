<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A family unit inside a household.
 *
 * Several per household is the normal case in Taytay, not an edge case: relief goods are
 * distributed per household and conditional cash grants per family, so the two counts must
 * be separately correct.
 */
final class Family extends Model
{
    use SoftDeletes;

    protected $table = 'families';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->code ??= 'FAM-'.strtoupper(Str::random(10));
        });
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<FamilyMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function currentMemberCount(): int
    {
        return $this->memberships()->whereNull('effective_to')->count();
    }
}
