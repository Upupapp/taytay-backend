<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One channel's attempt at one notification.
 *
 * There is deliberately no payload column. A stored push body would be a second copy of exactly
 * the content this design keeps out of the push channel in the first place.
 */
final class NotificationDispatch extends Model
{
    protected $table = 'notification_dispatches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
