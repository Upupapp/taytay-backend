<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Two canonical rows that may be the same person.
 *
 * The pair is stored with its primary keys normalised — smaller id in `lower_resident_id`
 * — so (A,B) and (B,A) are one row. Two rows for one question is how two reviewers reach
 * opposite conclusions about the same pair and both believe they were the only one looking.
 */
final class ResidentDuplicatePair extends Model
{
    protected $table = 'resident_duplicate_pairs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function isUndecided(): bool
    {
        return $this->decision === 'undecided';
    }

    public function isSamePerson(): bool
    {
        return $this->decision === 'same-person';
    }

    /**
     * Normalises a pair of primary keys into the order the unique key expects.
     *
     * Every writer must go through here. A single caller that inserts the raw argument
     * order defeats the constraint and reintroduces the duplicate-question problem.
     *
     * @return array{int, int}
     */
    public static function normalise(int $residentIdA, int $residentIdB): array
    {
        return $residentIdA <= $residentIdB
            ? [$residentIdA, $residentIdB]
            : [$residentIdB, $residentIdA];
    }
}
