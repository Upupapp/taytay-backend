<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An executed merge. Append-only.
 *
 * The reassignment counts are stored rather than recomputed because after the merge there
 * is nothing left to count: every row that pointed at the absorbed resident now points at
 * the survivor, so "how many credentials moved" is unanswerable from the current state.
 */
final class ResidentMerge extends Model
{
    public $timestamps = false;

    protected $table = 'resident_merges';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
            'created_at' => 'datetime',
            'reassigned_accounts' => 'integer',
            'reassigned_credentials' => 'integer',
            'reassigned_kyc_cases' => 'integer',
            'reassigned_sectors' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->merged_at ??= now();
            $model->created_at ??= now();
        });
    }
}
