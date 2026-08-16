<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A restricted record that a family is being watched, and why.
 *
 * A TABLE RATHER THAN A FLAG ON THE CASE, because a boolean on `welfare_cases` is selected by
 * every list query in the system and would therefore reach every queue, export and count — the
 * "minimal list-view exposure" the master command asks for is impossible once the column travels
 * with the row (ADR 0022 §4).
 */
final class SafeguardingConcern extends Model
{
    protected $table = 'safeguarding_concerns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raised_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->raised_at ??= now();
        });
    }

    public function isActive(): bool
    {
        return $this->status !== 'closed';
    }
}
