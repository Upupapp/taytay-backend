<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\ReleaseStatus;

/**
 * One instalment of approved assistance, and whether it reached the family.
 *
 * OPERATIONAL TRACKING, NOT A LEDGER. No journal entry, no account code, no posting state.
 * `funding_source` is a label for grouping a report, never a chart-of-accounts reference.
 *
 * @property ReleaseStatus $status
 */
final class Release extends Model
{
    protected $table = 'releases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'scheduled_for' => 'date',
            'released_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->reference_number ??= 'REL-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        });
    }

    /**
     * @return HasMany<ReleaseTransition, self>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(ReleaseTransition::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * The amount, in centavos. Null for in-kind, where nothing was handed over as money.
     *
     * Integer throughout — never cast to float for arithmetic, never formatted for storage. A
     * peso figure that has been through a float is a peso figure nobody can reconcile.
     */
    public function amountCentavos(): ?int
    {
        return $this->amount_centavos === null ? null : (int) $this->amount_centavos;
    }
}
