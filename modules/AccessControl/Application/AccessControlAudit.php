<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Modules\Shared\Application\ActorContext;
use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes authority changes to the append-only audit trail.
 *
 * Provisioning events are the ones an investigation starts from: "who could approve this
 * in March, and who gave them that?" A grant that is not recorded is indistinguishable
 * afterwards from a grant that was never made (CLAUDE.md Article 5.4).
 *
 * The actor's own scope is recorded alongside the change, because "an admin granted this"
 * is a much weaker fact than "an admin who could themselves reach that barangay granted
 * this" — the second is what shows the escalation guards held.
 *
 * Summaries name the authority, never the person. No names, no email addresses, no
 * contact details in the trail (Article 5.5).

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class AccessControlAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(ActorContext $actor, string $action, string $summary, string $subjectId): void
    {
        $this->trail->record(
            $actor->subjectId,
            $action,
            $summary.' [by scope: '.$actor->scope->type.']',
            'AccessControl.Subject',
            $subjectId,
        );
    }
}
