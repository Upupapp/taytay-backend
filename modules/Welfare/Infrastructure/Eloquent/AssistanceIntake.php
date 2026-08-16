<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What was actually submitted. One per case.
 *
 * Substantively immutable: a correction to the applicant's own account is a new statement on
 * the case timeline, not a rewrite of what they first said. The office has to be able to show
 * what it was told when it decided.
 */
final class AssistanceIntake extends Model
{
    protected $table = 'assistance_intakes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->submitted_at ??= now();
        });
    }

    /** @return BelongsTo<WelfareCase, $this> */
    public function welfareCase(): BelongsTo
    {
        return $this->belongsTo(WelfareCase::class, 'welfare_case_id');
    }
}
