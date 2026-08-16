<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The office asking an applicant for a document, recorded.
 */
final class DocumentRequest extends Model
{
    protected $table = 'document_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->requested_at ??= now();
        });
    }

    /**
     * Whether the applicant is late.
     *
     * Named for its subject rather than as a bare `isOverdue`, because a case task is overdue
     * when *staff* are late and a document request when the *applicant* is. Two different
     * obligations, and a queue that mixes them tells a supervisor nothing useful.
     */
    public function isApplicantOverdue(?Carbon $on = null): bool
    {
        return $this->state === 'open'
            && $this->needed_by !== null
            && $this->needed_by->lt($on ?? Carbon::now());
    }
}
