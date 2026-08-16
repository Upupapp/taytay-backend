<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

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
 */
final class WelfareAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

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
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'Welfare.Case',
            'entity_id' => $caseUuid,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
