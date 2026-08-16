<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A material thing that happened to a case. Append-only.
 *
 * `is_citizen_visible` is decided at WRITE time by the code that knows what the event
 * contains, not at render time by whichever endpoint happens to be listing it. A rule applied
 * at render is a rule the next endpoint forgets; a column travels with the row.
 *
 * `citizen_message` being null makes an event staff-only even if the flag were flipped by
 * mistake — belt and braces on the one boundary that leaks worst.
 */
final class CaseEvent extends Model
{
    public $timestamps = false;

    protected $table = 'welfare_case_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_citizen_visible' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->occurred_at ??= now();
            $model->created_at ??= now();
        });
    }

    /**
     * Whether this row may be shown to the applicant.
     *
     * Both conditions, always: the flag AND a message written for them. An event flagged
     * visible with no citizen message would otherwise fall back to the operator summary,
     * which is exactly the staff-deliberation leak this design exists to prevent.
     */
    public function isVisibleToCitizen(): bool
    {
        return (bool) $this->is_citizen_visible && $this->citizen_message !== null;
    }
}
