<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A name this resident has also been known by, from a merge or a name correction.
 *
 * Exists so search still finds the person under the old name. A registry that forgets
 * former names quietly manufactures duplicates: the clerk searches, finds nothing, and
 * enrols the same human being a second time.
 */
final class ResidentAlias extends Model
{
    public $timestamps = false;

    protected $table = 'resident_aliases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->recorded_at ??= now();
            $model->created_at ??= now();
        });
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
}
