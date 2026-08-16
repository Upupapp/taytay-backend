<?php

declare(strict_types=1);

namespace Modules\Content\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * Where a newsfeed post stands.
 *
 * PUBLISHING IS THE IRREVERSIBLE STEP, in the way that matters for content: an announcement that
 * has been seen cannot be unseen, and an archived post is one people already read. So `archived`
 * is not "deleted" and not "draft again" — it is "no longer current", and the difference is what
 * lets somebody ask what the office was saying last August.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Published, self::Archived],
            // A scheduled post can be pulled back to draft: somebody spotted a mistake before it
            // went out, which is the whole reason scheduling exists.
            self::Scheduled => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Archived],
            // An archived post is republished as a NEW post, never resurrected. Resurrecting one
            // would put a post back at the top of the feed with its original date, which reads as
            // the office announcing something old as if it were new.
            self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Whether the public may see it, given its schedule has also arrived. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    public function requiredPermission(): Permission
    {
        return match ($this) {
            self::Published, self::Scheduled => Permission::NewsfeedPublish,
            default => Permission::NewsfeedManage,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
