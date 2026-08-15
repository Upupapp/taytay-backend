<?php

declare(strict_types=1);

namespace Modules\AccessControl\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\RequestContext;

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
 */
final class AccessControlAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(ActorContext $actor, string $action, string $summary, string $subjectId): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actor->subjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => 'AccessControl.Subject',
            // The subject whose authority changed, not the actor who changed it.
            'entity_id' => $subjectId,
            'summary' => Str::limit($summary.' [by scope: '.$actor->scope->type.']', 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
