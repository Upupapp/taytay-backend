<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Who held the file, and when.
 *
 * Effective-dated: an open row (`unassigned_at IS NULL`) is the current holder. Reassignment
 * closes one and opens another, so "who was responsible on the 12th" stays answerable — which
 * is a different question from "what state was it in on the 12th", and the one asked first
 * when something went wrong.
 */
final class CaseAssignment extends Model
{
    protected $table = 'welfare_case_assignments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->assigned_at ??= now();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at');
    }

    public function isOpen(): bool
    {
        return $this->unassigned_at === null;
    }
}
