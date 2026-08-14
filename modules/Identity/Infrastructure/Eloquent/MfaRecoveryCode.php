<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 */
final class MfaRecoveryCode extends Model
{
    protected $table = 'mfa_recovery_codes';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
