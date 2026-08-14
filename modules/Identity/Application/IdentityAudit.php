<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Application\RequestContext;

/**
 * Writes Identity's events to the append-only audit trail.
 *
 * Every authentication event is recorded: RA 10173 accountability, and because "when did
 * this account last sign in, from which channel, and who reset its password" is the first
 * question asked after any incident.
 *
 * WHAT IS NEVER RECORDED HERE: passwords, tokens, one-time codes, reset tokens, TOTP
 * secrets, recovery codes, or any value a reader could replay. The summary says what
 * happened, never with what (CLAUDE.md Article 5.5). The audit table exists to prove
 * access occurred, not to become a second copy of the secrets it protects.
 */
final class IdentityAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(?Account $account, string $action, string $summary): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $account?->uuid,
            'actor_label' => $account?->display_name,
            'action' => $action,
            'entity_type' => 'Identity.Account',
            'entity_id' => $account?->uuid,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }

    /**
     * A failure for an identifier that matched no account.
     *
     * Recorded without the identifier itself: logging the address someone tried to sign in
     * as turns the audit trail into a list of probed accounts, and for a VAWC survivor
     * that list is itself dangerous. The request id ties it to the API log if an
     * investigation genuinely needs more.
     */
    public function recordUnknownSubjectFailure(string $action, string $summary): void
    {
        $this->record(null, $action, $summary);
    }
}
