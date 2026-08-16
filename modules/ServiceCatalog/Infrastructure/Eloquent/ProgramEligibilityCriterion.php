<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\ServiceCatalog\Domain\EligibilityFact;

/**
 * One guidance rule: a fact, a comparison, and the sentence a clerk repeats to the applicant.
 *
 * `citizen_explanation` is NOT NULL by schema and mandatory by validation. A criterion nobody
 * can explain is exactly the opaque denial the master command forbids, and the cheapest way to
 * prevent one is to refuse to store it.
 *
 * @property EligibilityFact $fact
 */
final class ProgramEligibilityCriterion extends Model
{
    protected $table = 'program_eligibility_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fact' => EligibilityFact::class,
            'is_blocking' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
