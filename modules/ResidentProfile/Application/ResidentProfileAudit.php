<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes ResidentProfile events to the append-only audit trail.
 *
 * Every read of another person's record and every KYC decision is recorded: RA 10173
 * accountability, and because "who approved this identity, and who looked at this
 * record" is the first question after any dispute over a benefit.
 *
 * Summaries name the event, never the claim. A trail that repeats a resident's name,
 * address or document number becomes a second, less-guarded copy of the record it exists
 * to protect (CLAUDE.md Article 5.5).

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class ResidentProfileAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?string $actorSubjectId, string $action, string $summary, ?string $entityId = null): void
    {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'ResidentProfile.KycCase',
            $entityId,
        );
    }

    /**
     * A change was made to the canonical registry.
     *
     * Separate from `record()` because the entity type differs: a trail that files every
     * resident edit under `KycCase` cannot answer "what happened to this resident record",
     * which is the only question anyone actually asks of it.
     *
     * The summary names the action and never the value. "Address corrected" belongs here;
     * the address itself belongs in `resident_status_events`, which is scoped to the module
     * and read under an authorization decision — not in the audit log, which is read by
     * operators investigating something else entirely (Article 5.5).
     */
    public function recordResidentWrite(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        ?string $residentUuid,
    ): void {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            'ResidentProfile.Resident',
            $residentUuid,
        );
    }

    /**
     * A staff member opened somebody else's record. Recorded separately because reads are
     * the events people forget to audit, and the ones a data-privacy complaint asks about.
     */
    public function recordResidentRead(?string $actorSubjectId, string $residentUuid): void
    {
        $this->trail->record(
            $actorSubjectId,
            'resident.viewed',
            'Resident record viewed',
            'ResidentProfile.Resident',
            $residentUuid,
        );
    }
}
