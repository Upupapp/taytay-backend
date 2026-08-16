<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A choice somebody made about being contacted.
 *
 * Opt-OUT: a row exists only when something has been switched off, so an absent row means "on".
 * That is the right default for a service that has to be able to tell people things, and it means
 * a notification type added later reaches people rather than silently reaching nobody.
 *
 * Mandatory notices ignore this table entirely (ADR 0025 §4).
 */
final class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
