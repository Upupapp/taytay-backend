<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One document chosen to travel with a referral, and why.
 *
 * Opt-in, one at a time. Nothing is ever attached automatically — the acceptance criterion, and
 * the reason a family's whole document set does not follow them to an office that asked about one
 * thing.
 */
final class ReferralAttachment extends Model
{
    protected $table = 'referral_attachments';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->created_at ??= now();
        });
    }
}
