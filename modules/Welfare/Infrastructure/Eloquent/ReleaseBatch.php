<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A distribution run: a hall, a table, a queue of families and one manifest.
 *
 * Exists because releases genuinely happen this way, and because "who was on the list that day"
 * is the question asked when somebody says they were missed.
 */
final class ReleaseBatch extends Model
{
    protected $table = 'release_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->reference_number ??= 'BAT-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));
        });
    }

    /**
     * @return HasMany<Release, self>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
