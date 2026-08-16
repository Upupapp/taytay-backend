<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One answer to one question.
 *
 * A row rather than a key in a JSON blob, so that "how many assessed households reported no
 * income earner this quarter" is an indexed query — which is the question these forms exist to
 * answer (ADR 0008 §13).
 */
final class AssessmentAnswer extends Model
{
    protected $table = 'assessment_answers';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
