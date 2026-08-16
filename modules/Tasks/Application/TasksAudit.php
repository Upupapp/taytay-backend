<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

/**
 * Writes task events to the append-only audit trail.
 *
 * Summaries name the act and the task, never the subject. An entry reading "closed the VAWC
 * referral follow-up for case X" would put in the audit log exactly what the task itself is
 * careful not to carry.
 */
final class TasksAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(?string $actorSubjectId, string $action, string $summary, string $taskUuid): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'Tasks.Task',
            'entity_id' => $taskUuid,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
