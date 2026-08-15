<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A resident's residence in a household, over a period.
 *
 * An open row (`effective_to IS NULL`) means "lives here now". A transfer closes one and
 * opens another; it never edits the first, because "who lived here when the October relief
 * was distributed" must stay answerable after the family moves in November.
 */
final class HouseholdMembership extends Model
{
    protected $table = 'household_memberships';

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
