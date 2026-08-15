<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\ResidentProfile\Contracts\CorrectionStatus;

/**
 * A resident's request to have their own record corrected.
 *
 * @property CorrectionStatus $status
 */
final class ResidentCorrectionRequest extends Model
{
    protected $table = 'resident_correction_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CorrectionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<ResidentCorrectionField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ResidentCorrectionField::class, 'resident_correction_request_id');
    }
}
