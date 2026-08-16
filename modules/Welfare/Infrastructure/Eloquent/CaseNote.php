<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\NoteSensitivity;

/**
 * One entry in a case's running record.
 *
 * Append-only, and withdrawn rather than deleted: the fact that something was written and
 * retracted is itself part of the record, and a note that vanishes leaves a file that reads as
 * though the office never had the thought.
 *
 * @property NoteSensitivity $sensitivity
 */
final class CaseNote extends Model
{
    protected $table = 'case_notes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sensitivity' => NoteSensitivity::class,
            'withdrawn_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->created_at ??= now();
        });
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
}
