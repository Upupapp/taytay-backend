<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\DisclosureBasis;
use Modules\Welfare\Domain\ReferralStatus;
use Modules\Welfare\Domain\ReferralUrgency;

/**
 * A person routed to another organisation, and the record of what went with them.
 *
 * @property ReferralStatus $status
 * @property ReferralUrgency $urgency
 * @property DisclosureBasis|null $disclosure_basis
 */
final class Referral extends Model
{
    protected $table = 'referrals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'urgency' => ReferralUrgency::class,
            'disclosure_basis' => DisclosureBasis::class,
            'referred_at' => 'datetime',
            'sent_at' => 'datetime',
            'follow_up_on' => 'date',
            'responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'disclosure_recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->referred_at ??= now();
            $model->reference_number ??= self::mintReference();
        });
    }

    /**
     * @return HasMany<ReferralSharedField, self>
     */
    public function sharedFields(): HasMany
    {
        return $this->hasMany(ReferralSharedField::class);
    }

    /**
     * @return HasMany<ReferralAttachment, self>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReferralAttachment::class);
    }

    /**
     * @return HasMany<ReferralNote, self>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ReferralNote::class)->orderBy('recorded_at');
    }

    /**
     * A referral this office said it would chase by now, and has not heard about.
     *
     * All three conditions matter. Still open, because a declined referral is answered.
     * No response recorded, because hearing back is what discharges the commitment. And a
     * follow-up date in the past, because a referral with no date was never a promise.
     */
    public function isOverdue(?Carbon $on = null): bool
    {
        return $this->status->isOpen()
            && $this->responded_at === null
            && $this->follow_up_on !== null
            && $this->follow_up_on->lt($on ?? Carbon::now());
    }

    /**
     * Short, printable and quoted back by the receiving office when they telephone.
     *
     * Deliberately not derived from the resident or the case: a reference number read aloud over
     * a phone to a third party must carry nothing about the person it belongs to.
     */
    private static function mintReference(): string
    {
        return 'REF-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
