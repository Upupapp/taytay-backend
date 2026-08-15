<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

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
 */
final class ResidentProfileAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(?string $actorSubjectId, string $action, string $summary, ?string $entityId = null): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'ResidentProfile.KycCase',
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
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
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'ResidentProfile.Resident',
            'entity_id' => $residentUuid,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }

    /**
     * A staff member opened somebody else's record. Recorded separately because reads are
     * the events people forget to audit, and the ones a data-privacy complaint asks about.
     */
    public function recordResidentRead(?string $actorSubjectId, string $residentUuid): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => 'resident.viewed',
            'entity_type' => 'ResidentProfile.Resident',
            'entity_id' => $residentUuid,
            'summary' => 'Resident record viewed',
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
