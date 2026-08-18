<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Modules\Content\Domain\ReportReason;

/**
 * One resident's report of one comment.
 *
 * Append-only by shape: `created_at` and nothing else. A report is a statement somebody made at a
 * moment, and the outcome lives on the comment where the moderator's decision already is.
 */
final class NewsfeedCommentReport extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'newsfeed_comment_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason' => ReportReason::class];
    }
}
