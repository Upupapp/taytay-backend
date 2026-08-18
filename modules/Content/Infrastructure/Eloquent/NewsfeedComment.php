<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Content\Domain\ModerationState;

/**
 * One comment on an announcement.
 *
 * @property ModerationState $moderation_state
 */
final class NewsfeedComment extends Model
{
    /**
     * Residents who said this should not be here (F26).
     *
     * Read only by the moderation queue. `readerProjection` must never surface this or its count:
     * a reader learning that three people objected to a neighbour's comment is the feed reporting
     * on its own residents.
     */
    public function reports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NewsfeedCommentReport::class, 'newsfeed_comment_id');
    }

    protected $table = 'newsfeed_comments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'moderation_state' => ModerationState::class,
            'is_official' => 'boolean',
            'moderated_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
