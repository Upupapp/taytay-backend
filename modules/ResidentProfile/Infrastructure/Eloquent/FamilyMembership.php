<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A resident's membership of a family, over a period. Same effective-dated shape as
 * {@see HouseholdMembership}, and separate because family and household are separate facts.
 */
final class FamilyMembership extends Model
{
    protected $table = 'family_memberships';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->effective_from ??= now()->toDateString();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    public function isOpen(): bool
    {
        return $this->effective_to === null;
    }
}
