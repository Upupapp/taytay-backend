<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Welfare\Domain\CasePriority;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\CaseType;

/**
 * One person's request for help, from the counter to the payout.
 *
 * @property CaseStatus $status
 * @property CaseType $type
 * @property CasePriority $priority
 */
final class WelfareCase extends Model
{
    use SoftDeletes;

    protected $table = 'welfare_cases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'type' => CaseType::class,
            'priority' => CasePriority::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
            'assigned_at' => 'datetime',
            'next_follow_up_on' => 'date',
            'needs_home_visit' => 'boolean',
            'is_escalated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->case_number ??= self::generateCaseNumber();
            $model->opened_at ??= now();
            $model->last_activity_at ??= now();
        });
    }

    /** @return HasMany<CaseTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(CaseTransition::class, 'welfare_case_id');
    }

    /** @return HasMany<CaseAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class, 'welfare_case_id');
    }

    /** @return HasMany<CaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'welfare_case_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isRestricted(): bool
    {
        return $this->type->isRestricted();
    }

    /**
     * Random, not sequential. A sequential number tells any holder how many cases the LGU has
     * opened and lets them guess their neighbour's.
     */
    public static function generateCaseNumber(): string
    {
        return 'SWC-'.now()->format('Y').'-'.strtoupper(Str::random(8));
    }
}
