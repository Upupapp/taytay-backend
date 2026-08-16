<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

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
 */
final class ServiceCatalogAudit
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
            'entity_type' => 'ServiceCatalog.Provider',
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
