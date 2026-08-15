<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The reviewable record of an account being authorised to act for a resident.
 *
 * `accounts.resident_id` is Identity's fast current answer. This is the history behind it:
 * who linked them, on what authority, and — after a revocation — that the link ever
 * existed. A mutable column alone cannot answer "who gave this account access to that
 * person's file", which is the first question asked after a privacy complaint.
 *
 * A link is authorisation to *act for*, never authorisation to *see*: every read is still
 * a separate decision (ADR 0002).
 */
final class AccountResidentLink extends Model
{
    protected $table = 'account_resident_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->linked_at ??= now();
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
