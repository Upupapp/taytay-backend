<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One criterion's outcome within a check.
 *
 * Carries the observed value alongside the result, so an outcome can be checked rather than
 * trusted — which is the difference between guidance and an oracle.
 */
final class CaseEligibilityResult extends Model
{
    public $timestamps = false;

    protected $table = 'welfare_case_eligibility_results';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_blocking' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->created_at ??= now();
        });
    }
}
