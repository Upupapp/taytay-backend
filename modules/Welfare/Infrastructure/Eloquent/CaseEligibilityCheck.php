<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An eligibility check that was actually run against a case. Append-only.
 *
 * The audit requirement made real: the guidance version used is pinned at the moment it ran, so
 * a decision defended two years later can be re-derived against the rules that actually applied
 * rather than against today's.
 *
 * `$timestamps = false` is the append-only guarantee in code as well as schema. A check that
 * could be edited would be worthless as the evidence it exists to be.
 */
final class CaseEligibilityCheck extends Model
{
    public $timestamps = false;

    protected $table = 'welfare_case_eligibility_checks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evaluated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->evaluated_at ??= now();
            $model->created_at ??= now();
        });
    }

    /** @return HasMany<CaseEligibilityResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(CaseEligibilityResult::class, 'welfare_case_eligibility_check_id');
    }
}
