<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Contracts\AuditWriter;

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

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class IdentityAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function record(?Account $account, string $action, string $summary): void
    {
        $this->trail->record(
            $account?->uuid,
            $action,
            $summary,
            'Identity.Account',
            $account?->uuid,
        );
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
