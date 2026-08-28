<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes newsfeed events to the append-only audit trail.
 *
 * Publishing is the act worth recording. "Who put this on the municipal feed, and when" is the
 * first question after an announcement turns out to be wrong — and unlike most of this system,
 * the answer is one the public may reasonably ask for.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class ContentAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        string $entityId,
        ?string $reason = null,
    ): void {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'Content.NewsfeedPost',
            $entityId,
            reason: $reason,
        );
    }
}
