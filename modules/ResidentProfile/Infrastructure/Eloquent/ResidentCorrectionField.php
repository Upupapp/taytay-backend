<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One field a correction request proposes to change.
 *
 * `current_value` is the value at the moment of the request, not a live read. A reviewer
 * looking at a week-old request needs to know what the resident was looking at when they
 * filed it — if it no longer matches the record, something else moved in between and the
 * request deserves a second look rather than a rubber stamp.
 */
final class ResidentCorrectionField extends Model
{
    protected $table = 'resident_correction_fields';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
