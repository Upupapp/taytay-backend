<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Events\Domain\EventStatus;

/**
 * An official LGU event.
 *
 * @property EventStatus $status
 */
final class Event extends Model
{
    protected $table = 'events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_required' => 'boolean',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'waitlist_enabled' => 'boolean',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
