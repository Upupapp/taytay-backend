<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A request for a copy of some of this database.
 *
 * The row outlives the file: purging the export at expiry removes the copy, and the record that
 * somebody asked for it stays, because that is the part an audit needs.
 */
final class ReportExport extends Model
{
    protected $table = 'report_exports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'permission_context' => 'array',
            'is_person_level' => 'boolean',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function isExpired(?Carbon $on = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt($on ?? Carbon::now());
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->purged_at === null && ! $this->isExpired();
    }
}
