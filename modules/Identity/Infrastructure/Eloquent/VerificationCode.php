<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\VerificationPurpose;

/**
 * @property string $uuid
 */
final class VerificationCode extends Model
{
    protected $table = 'verification_codes';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'purpose' => VerificationPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }

    /** Guessing burns the code rather than being unlimited (OWASP ASVS V2.2). */
    public const MAX_ATTEMPTS = 5;

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
