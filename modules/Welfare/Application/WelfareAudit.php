<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes Welfare events to the append-only audit trail.
 *
 * Every material case transition, assignment change and staff read of somebody's case is
 * recorded: RA 10173 accountability, and because "who approved this, and who looked at it" is
 * the first question after any dispute over a benefit.
 *
 * Summaries name the event, never the reason. A trail that repeats a rejection's
 * justification becomes a second, less-guarded copy of the deliberation it exists to protect
 * (CLAUDE.md Article 5.5) — and unlike the transition log, the audit table is read by
 * operators investigating something else entirely.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class WelfareAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?string $actorSubjectId, string $action, string $summary, ?string $caseUuid): void
    {
        $this->write($actorSubjectId, $action, $summary, $caseUuid);
    }

    /**
     * A staff member opened somebody's case file.
     *
     * Recorded separately because reads are the events people forget to audit, and the ones a
     * data-privacy complaint asks about.
     */
    public function recordRead(?string $actorSubjectId, string $caseUuid): void
    {
        $this->write($actorSubjectId, 'case.viewed', 'Welfare case viewed', $caseUuid);
    }

    private function write(?string $actorSubjectId, string $action, string $summary, ?string $caseUuid): void
    {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'Welfare.Case',
            $caseUuid,
        );
    }
}
