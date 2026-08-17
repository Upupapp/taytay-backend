<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes event events to the append-only audit trail.
 *
 * The master command asks specifically for public-state changes to be audited, and that is the
 * useful subset: publishing and cancelling are the two acts residents plan their week around, and
 * "who called this off, and when" is the question at the covered court on the day.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class EventsAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?string $actorSubjectId, string $action, string $summary, string $entityId): void
    {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'Events.Event',
            $entityId,
        );
    }
}
