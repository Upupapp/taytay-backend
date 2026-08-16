<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

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
 */
final class ReportingAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(?string $actorSubjectId, string $action, string $summary, string $entityId): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'Reporting.Export',
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
