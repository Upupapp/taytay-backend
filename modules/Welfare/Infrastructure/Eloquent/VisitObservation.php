<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\ObservationKind;

/**
 * One thing recorded on a visit, carrying whose claim it is.
 *
 * Append-only. A visit record is contemporaneous; editing an observation changes what the file
 * says the worker found, which is the single most useful property it has in a dispute.
 *
 * @property ObservationKind $kind
 */
final class VisitObservation extends Model
{
    protected $table = 'visit_observations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => ObservationKind::class,
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->recorded_at ??= now();
            $model->created_at ??= now();
        });
    }
}
