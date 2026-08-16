<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One movement of a release, with who and why. Append-only.
 *
 * Money is the one place where "what happened to this record" must be reconstructable without
 * inference, so movements are rows rather than a status column and a hope.
 */
final class ReleaseTransition extends Model
{
    protected $table = 'release_transitions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->occurred_at ??= now();
            $model->created_at ??= now();
        });
    }
}
