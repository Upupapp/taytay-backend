<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An image attached to a post, with the alt text that makes it readable.
 */
final class NewsfeedMedia extends Model
{
    protected $table = 'newsfeed_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_decorative' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
