<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A consent given, and possibly withdrawn.
 *
 * Withdrawal is a TIMESTAMP, never a deleted row: "did she ever agree, and when did she change her
 * mind" is the question a complaint asks, and a deleted row answers neither.
 */
final class ConsentRecord extends Model
{
    protected $table = 'consent_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function isLive(): bool
    {
        return $this->withdrawn_at === null;
    }
}
