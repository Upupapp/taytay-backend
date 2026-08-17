<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A hold that prevents retention deletion.
 *
 * ONE DIRECTION ONLY: a hold can prevent a deletion and can never cause one. Retention deletion is
 * irreversible — a record destroyed during an ongoing investigation cannot be un-destroyed,
 * whereas a record kept too long can still be deleted tomorrow.
 */
final class LegalHold extends Model
{
    protected $table = 'legal_holds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
