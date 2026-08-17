<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * That a person was SHOWN a version of the notice.
 *
 * NOT CONSENT, and the distinction is the whole reason this is a separate table from
 * `consent_records`. Being told how your data will be used is not agreeing to it, and for almost
 * everything this office does no agreement is sought because none is the legal basis
 * (ADR 0034 §4).
 */
final class PrivacyAcknowledgement extends Model
{
    protected $table = 'privacy_acknowledgements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
