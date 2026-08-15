<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class KycDocument extends Model
{
    protected $table = 'kyc_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deleted_from_storage_at' => 'datetime'];
    }

    public function isPurged(): bool
    {
        return $this->deleted_from_storage_at !== null;
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
