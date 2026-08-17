<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes catalogue events to the append-only audit trail.
 *
 * Article 5.4 requires every privileged administrative action to be auditable, and the service
 * provider directory is one: an entry moved from `retired` to `active` starts routing families
 * somewhere, and an entry whose contact details are quietly edited redirects every referral that
 * follows. Neither leaves a trace anywhere else.
 *
 * `ProgramCatalog` predates this and does not yet audit its own writes — publishing a programme
 * is at least as consequential. Recorded as gap G-28 rather than fixed here, because retrofitting
 * it belongs with a review of what a programme change means, not with a referral TAB.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class ServiceCatalogAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?string $actorSubjectId, string $action, string $summary, string $entityId): void
    {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'ServiceCatalog.Provider',
            $entityId,
        );
    }
}
