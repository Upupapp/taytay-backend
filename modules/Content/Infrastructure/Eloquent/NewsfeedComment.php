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
