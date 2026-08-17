<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes task events to the append-only audit trail.
 *
 * Summaries name the act and the task, never the subject. An entry reading "closed the VAWC
 * referral follow-up for case X" would put in the audit log exactly what the task itself is
 * careful not to carry.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class TasksAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?string $actorSubjectId, string $action, string $summary, string $taskUuid): void
    {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'Tasks.Task',
            $taskUuid,
        );
    }
}
