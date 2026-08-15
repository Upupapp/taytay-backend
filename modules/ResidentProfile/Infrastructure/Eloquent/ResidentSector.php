<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A sectoral tag on a resident (senior citizen, PWD, solo parent, VAWC survivor, …).
 *
 * Its own table rather than a JSON column: sectors are filtered, counted and reported on,
 * and two of them (`vawc-survivor`, `cicl`) gate access to the whole record — none of
 * which a JSON blob can index or constrain (ADR 0008 §13).
 */
final class ResidentSector extends Model
{
    protected $table = 'resident_sectors';

    protected $guarded = ['id'];

    /** Sectors whose mere membership is sensitive personal information. */
    public const SENSITIVE = ['vawc-survivor', 'cicl'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public static function isSensitive(string $sector): bool
    {
        return in_array($sector, self::SENSITIVE, true);
    }
}
