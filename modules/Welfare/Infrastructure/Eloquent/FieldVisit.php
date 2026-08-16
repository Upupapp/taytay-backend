<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\VisitStatus;

/**
 * A home or field visit.
 *
 * NOT A TRACKING RECORD, and shaped so it cannot quietly become one. No coordinate, no check-in,
 * no route, no device-taken arrival time — nothing that records where a worker was rather than
 * what they found (ADR 0022 §1, enforced by `NoLocationTrackingTest`).
 *
 * @property VisitStatus $status
 */
final class FieldVisit extends Model
{
    protected $table = 'field_visits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'scheduled_for' => 'date',
            'follow_up_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->reference_number ??= 'FV-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        });
    }

    /**
     * @return HasMany<VisitObservation, self>
     */
    public function observations(): HasMany
    {
        /*
         * Tie-broken by insertion order. A worker writing up a visit records several observations
         * in the same second, and on an equal timestamp the database is free to return them in
         * any order — so the sequence a reader sees would vary between requests. That matters
         * here more than it usually would: these entries are read as a narrative, and "she says
         * he stopped sending money" landing above "the roof is missing sheets" changes how the
         * account reads.
         */
        return $this->hasMany(VisitObservation::class)->orderBy('recorded_at')->orderBy('id');
    }

    /**
     * @return HasMany<VisitChecklistItem, self>
     */
    public function checklist(): HasMany
    {
        return $this->hasMany(VisitChecklistItem::class)->orderBy('code');
    }

    /**
     * Still scheduled after its date.
     *
     * **The worker owes it, not the family.** Named and surfaced that way throughout, because a
     * queue that mixes "we have not been yet" with "the applicant has not brought their papers"
     * tells a supervisor nothing about who needs help.
     */
    public function isOverdue(?Carbon $on = null): bool
    {
        return $this->status->isOpen() && $this->scheduled_for->lt(($on ?? Carbon::now())->startOfDay());
    }
}
