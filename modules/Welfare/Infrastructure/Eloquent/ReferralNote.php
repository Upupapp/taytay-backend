<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A note on a referral, append-only, addressed to one audience or the other.
 *
 * `internal` is this office talking to itself — a worker's doubt, a safeguarding concern, a
 * judgement about a family. `receiving-office` is written to be read elsewhere. The audience is a
 * column rather than a flag added later, because a flag is what gets forgotten on the day
 * somebody exports the lot.
 */
final class ReferralNote extends Model
{
    protected $table = 'referral_notes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
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

    public function isInternal(): bool
    {
        return $this->audience === 'internal';
    }
}
