<?php

declare(strict_types=1);

namespace Modules\Files\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Permission to read one file, once, before it expires.
 */
final class FileAccessGrant extends Model
{
    protected $table = 'file_access_grants';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'redacted_for_sharing' => 'boolean',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->created_at ??= now();
        });
    }

    /**
     * @return BelongsTo<StoredFile, self>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    /**
     * Whether this grant may still be exchanged for bytes, by this holder.
     *
     * The holder is checked as well as the clock. A handle that leaks — pasted into a chat, left
     * in a browser history, forwarded — is useless to anybody it was not issued to, which is the
     * difference between a grant and a signed URL.
     */
    public function isRedeemableBy(?string $accountUuid): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $accountUuid !== null
            && (string) $this->issued_to === $accountUuid;
    }
}
