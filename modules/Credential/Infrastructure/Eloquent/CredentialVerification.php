<?php

declare(strict_types=1);

namespace Modules\Credential\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One scan. Append-only — `created_at` with no `updated_at`, and no delete path.
 *
 * The nonce is stored hashed and uniquely, which is what makes a photographed QR code
 * useless the second time it is presented.
 */
final class CredentialVerification extends Model
{
    protected $table = 'credential_verifications';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
