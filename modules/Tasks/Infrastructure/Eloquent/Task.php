<?php

declare(strict_types=1);

namespace Modules\Tasks\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Tasks\Domain\TaskPriority;
use Modules\Tasks\Domain\TaskStatus;
use Modules\Tasks\Domain\TaskType;

/**
 * One piece of work somebody owes.
 *
 * Carries a subject TYPE and IDENTIFIER and nothing else about it. A queue row is read by
 * everyone with access to the queue; anything denormalised here is disclosed to all of them
 * regardless of whether they may open the thing it points at (ADR 0024 §2).
 *
 * @property TaskType $type
 * @property TaskStatus $status
 * @property TaskPriority $priority
 */
final class Task extends Model
{
    protected $table = 'tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * Still owed and past its date.
     *
     * A task with no due date is never overdue — it was never a promise about a date.
     */
    public function isOverdue(?Carbon $on = null): bool
    {
        return $this->status->isOpen()
            && $this->due_on !== null
            && $this->due_on->lt(($on ?? Carbon::now())->startOfDay());
    }
}
