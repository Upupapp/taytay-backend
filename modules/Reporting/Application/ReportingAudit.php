<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes report and export events to the append-only audit trail.
 *
 * EXPORTS ARE AUDITED TWICE: when requested and when downloaded. They are different acts — an
 * export somebody queued and never fetched is a different fact from one that left the building,
 * and after an incident the second is the one that matters.
 *
 * Summaries name the report, never its contents or its filters. The filters live on the export
 * row, where they are evidence; repeating them here would put a barangay and a date range into a
 * log operators read for unrelated reasons.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class ReportingAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    /**
     * @param  string|null  $entityId  the export's UUID, or null where the act concerns no stored
     *                                 record — running a report produces no row to point at.
     */
    public function record(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        ?string $entityId,
        string $entityType = 'Reporting.Export',
    ): void {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            $entityType,
            $entityId,
        );
    }
}
