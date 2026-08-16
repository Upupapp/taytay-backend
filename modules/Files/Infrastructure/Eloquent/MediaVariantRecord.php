<?php

declare(strict_types=1);

namespace Modules\Files\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Files\Domain\MediaVariant;
use Modules\Files\Domain\MediaVisibility;

/**
 * A derived rendition of a stored file.
 *
 * Named `MediaVariantRecord` rather than `MediaVariant` because the enum already owns that name,
 * and the enum is the concept — this is the row.
 *
 * @property MediaVariant $variant
 * @property MediaVisibility $visibility
 */
final class MediaVariantRecord extends Model
{
    protected $table = 'media_variants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'variant' => MediaVariant::class,
            'visibility' => MediaVisibility::class,
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
