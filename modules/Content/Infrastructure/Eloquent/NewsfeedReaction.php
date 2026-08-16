<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's current reaction to one post.
 *
 * Current, not historical. Changing a reaction updates this row and removing it deletes it,
 * because a history of somebody's changing feelings about a municipal announcement is not a record
 * this office needs to be able to produce.
 */
final class NewsfeedReaction extends Model
{
    protected $table = 'newsfeed_reactions';

    protected $guarded = ['id'];
}
