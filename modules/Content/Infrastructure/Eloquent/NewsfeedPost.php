<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Content\Domain\PostStatus;

/**
 * An announcement.
 *
 * @property PostStatus $status
 */
final class NewsfeedPost extends Model
{
    protected $table = 'newsfeed_posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'comments_enabled' => 'boolean',
            'is_pinned' => 'boolean',
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return HasMany<NewsfeedMedia, self>
     */
    public function media(): HasMany
    {
        return $this->hasMany(NewsfeedMedia::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Visible to the public right now.
     *
     * BOTH conditions, always together. A published post whose `publish_at` has not arrived is
     * still scheduled in every way that matters to a reader, and treating `status` alone as the
     * gate is how an embargoed announcement goes out early.
     */
    public function isLive(?Carbon $on = null): bool
    {
        return $this->status->isPublic()
            && $this->publish_at !== null
            && $this->publish_at->lte($on ?? Carbon::now());
    }
}
