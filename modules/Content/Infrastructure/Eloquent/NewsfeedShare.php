<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A counter. That is the whole model.
 *
 * NO DESTINATION, NO RECIPIENT, NO PLATFORM. The row records that an advisory travelled, never who
 * carried it or to whom — the master command forbids tracking external destinations or personal
 * contacts, and `NoShareRecipientDataTest` fails the build if a column appears that would.
 */
final class NewsfeedShare extends Model
{
    protected $table = 'newsfeed_shares';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
