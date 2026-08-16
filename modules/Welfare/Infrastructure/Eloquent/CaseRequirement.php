<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\RequirementApplicability;
use Modules\Welfare\Domain\RequirementObligation;

/**
 * One document slot a case must fill.
 *
 * @property RequirementObligation $obligation
 * @property RequirementApplicability $applicability
 */
final class CaseRequirement extends Model
{
    protected $table = 'welfare_case_requirements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'obligation' => RequirementObligation::class,
            'applicability' => RequirementApplicability::class,
            'applicability_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
