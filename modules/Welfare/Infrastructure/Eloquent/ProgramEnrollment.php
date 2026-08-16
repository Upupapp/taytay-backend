<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\EnrollmentStatus;

/**
 * A beneficiary on a programme's roll, over a period.
 *
 * References the canonical resident by identifier. There is no beneficiary person row here and
 * there must never be one: a second place a person exists is a second population for duplicate
 * detection to reconcile, and it drifts from the canonical record after the first name
 * correction (ADR 0019 §1).
 *
 * @property EnrollmentStatus $status
 */
final class ProgramEnrollment extends Model
{
    protected $table = 'program_enrollments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->effective_from ??= now()->toDateString();
        });

        /*
         * Keeps the derived `open_key` in step with `effective_to` on every write, including the
         * ones that go through `forceFill()`.
         *
         * It lives here rather than at the call sites because a column that carries an invariant
         * is only worth having if it cannot be forgotten — and the write that would forget it is
         * the merge collapse, the one path that does not go through `enroll()` (ADR 0019 §5).
         *
         * Mass `update()` calls bypass model events entirely, so they must set it themselves;
         * EnrollmentService::reassignOnMerge() is the only such caller and does.
         */
        self::saving(function (self $model): void {
            $model->open_key = $model->effective_to === null ? $model->program_id : null;
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    public function isOpen(): bool
    {
        return $this->effective_to === null;
    }

    /**
     * Whether this enrolment was in force on a given date.
     *
     * The question a release audit asks: "was this household on the roll when the October
     * tranche went out". Answered from the row rather than from today's status, which is the
     * whole reason enrolment is effective-dated.
     */
    public function wasInForceOn(\DateTimeInterface $date): bool
    {
        if ($this->effective_from !== null && $this->effective_from->gt($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->gte($date);
    }
}
