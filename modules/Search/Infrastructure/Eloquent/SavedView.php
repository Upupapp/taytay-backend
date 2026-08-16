<?php

declare(strict_types=1);

namespace Modules\Search\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A named filter preset.
 *
 * IT SAVES A QUESTION, NOT AN ANSWER. Two people opening the same shared view see different rows,
 * because each query is scoped to whoever runs it. A view that carried its author's scope would
 * be a way to hand somebody a caseload they cannot otherwise reach (ADR 0027 §4).
 */
final class SavedView extends Model
{
    protected $table = 'saved_views';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
