<?php

declare(strict_types=1);

namespace Modules\Content\Domain;

/**
 * What has happened to a comment.
 *
 * `Deleted` IS A STATE, NOT A MISSING ROW. A comment removed for abuse must stay readable by a
 * moderator: "what did it say, who wrote it, who removed it and why" is the question asked when
 * the author complains, and a hard delete makes every one of those answers "we do not know".
 */
enum ModerationState: string
{
    /** Readable by everybody. */
    case Visible = 'visible';

    /** Removed from public view by a moderator. The row and its reason survive. */
    case Hidden = 'hidden';

    /** Withdrawn, by its author or a moderator. Also a state, also survives. */
    case Deleted = 'deleted';

    /**
     * Flagged for a human to look at.
     *
     * Nothing sets this today. It exists so a future moderation provider has a state to write
     * into without inventing one — the hook the master command asks for, without building AI
     * moderation now (ADR 0029 §5).
     */
    case ReviewNeeded = 'review-needed';

    /** Whether a reader may see it. */
    public function isPublic(): bool
    {
        return $this === self::Visible;
    }

    /**
     * Whether the author may still edit it.
     *
     * A hidden comment is not editable back into visibility: correcting the wording of something
     * a moderator removed would let an author launder a decision they disagree with.
     */
    public function isAuthorEditable(): bool
    {
        return $this === self::Visible;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
