<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

/**
 * Writes newsfeed events to the append-only audit trail.
 *
 * Publishing is the act worth recording. "Who put this on the municipal feed, and when" is the
 * first question after an announcement turns out to be wrong — and unlike most of this system,
 * the answer is one the public may reasonably ask for.
 */
final class ContentAudit
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
            'entity_type' => 'Content.NewsfeedPost',
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
