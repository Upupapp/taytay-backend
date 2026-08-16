<?php

declare(strict_types=1);

namespace Modules\Files\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\ScanStatus;

/**
 * A pointer to bytes on the private disk. Never the bytes themselves.
 *
 * @property FileClassification $classification
 * @property ScanStatus $scan_status
 */
final class StoredFile extends Model
{
    protected $table = 'stored_files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'classification' => FileClassification::class,
            'scan_status' => ScanStatus::class,
            'scanned_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * Whether the bytes are still there to serve.
     *
     * A purged file keeps its row — the record that something was provided, by whom and when is
     * itself evidence, and deleting it would erase the fact that the applicant complied.
     */
    public function isAvailable(): bool
    {
        return $this->purged_at === null && $this->scan_status->mayBeServed();
    }
}
