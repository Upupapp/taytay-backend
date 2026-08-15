<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One material change to a canonical resident. Append-only.
 *
 * `$timestamps = false` is the append-only guarantee expressed in code as well as in the
 * schema: Eloquent has no `updated_at` to maintain because there is no update.
 */
final class ResidentStatusEvent extends Model
{
    public $timestamps = false;

    protected $table = 'resident_status_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->occurred_at ??= now();
            $model->created_at ??= now();
        });
    }
}
