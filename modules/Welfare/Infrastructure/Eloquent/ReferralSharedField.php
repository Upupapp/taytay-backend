<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Modules\Welfare\Domain\SharedField;

/**
 * One field released to a receiving office, and why.
 *
 * A row rather than an entry in a JSON blob, because this is the audit trail of a disclosure:
 * "which referrals released a home address" is the first question asked after a protection
 * incident, and a blob cannot answer it.
 *
 * @property SharedField $field
 */
final class ReferralSharedField extends Model
{
    protected $table = 'referral_shared_fields';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'field' => SharedField::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            $model->created_at ??= now();
        });
    }
}
