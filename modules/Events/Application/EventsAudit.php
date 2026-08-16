<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

/**
 * Writes event events to the append-only audit trail.
 *
 * The master command asks specifically for public-state changes to be audited, and that is the
 * useful subset: publishing and cancelling are the two acts residents plan their week around, and
 * "who called this off, and when" is the question at the covered court on the day.
 */
final class EventsAudit
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
            'entity_type' => 'Events.Event',
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
