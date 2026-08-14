<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 */
final class Device extends Model
{
    protected $table = 'devices';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * `push_token` is encrypted rather than hashed: it must be recoverable to send a
     * notification. It authorises delivery to a device, so a database dump must not
     * become a push-spoofing capability (ADR 0004).
     */
    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'datetime',
            'trusted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
