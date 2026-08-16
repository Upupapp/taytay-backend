<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\Recommendation;

/**
 * A social worker's structured findings on a case.
 *
 * `template_code` + `template_version` pin the form as it stood when this was made. Without the
 * version, a later edit to the form silently changes what this assessment appears to have
 * asked, and its answers stop meaning what they meant.
 *
 * @property Recommendation|null $recommendation
 */
final class Assessment extends Model
{
    protected $table = 'assessments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recommendation' => Recommendation::class,
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<AssessmentAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class, 'assessment_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
