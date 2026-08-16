<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One thing somebody was told.
 *
 * Holds rendered text because it is read back over an authenticated API. That text is NOT what a
 * push provider receives — see `OutboundNotification::routingPayload()` for what is.
 */
final class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return HasMany<NotificationDispatch, self>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(NotificationDispatch::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
