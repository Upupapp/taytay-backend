<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One recorded move through the state machine. Append-only.
 *
 * `$timestamps = false` is the append-only guarantee in code as well as in the schema:
 * Eloquent has no `updated_at` to maintain because there is no update.
 *
 * `reason` is internal and `applicant_message` is what the person is told. Keeping them apart
 * is what stops "claimant's account inconsistent with neighbour statements" being rendered in
 * a citizen app.
 */
final class CaseTransition extends Model
{
    public $timestamps = false;

    protected $table = 'welfare_case_transitions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->occurred_at ??= now();
            $model->created_at ??= now();
        });
    }
}
