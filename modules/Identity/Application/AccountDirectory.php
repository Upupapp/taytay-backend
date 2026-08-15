<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Modules\Identity\Infrastructure\Eloquent\Account;

/**
 * The published way for other modules to ask about an account.
 *
 * Exposes the one fact other modules legitimately need — which resident, if any, this
 * account acts for — and nothing about credentials, sessions or contact details. Reaching
 * for the Account model from another module would hand over the whole authentication
 * record (CLAUDE.md Article 2.1).
 */
final class AccountDirectory
{
    /**
     * The resident this account is linked to, or null.
     *
     * Note what this does NOT mean: holding a resident id grants no access to that
     * resident's records. Authorization is a separate decision made per object (ADR 0002).
     */
    public function residentIdFor(string $accountUuid): ?string
    {
        $residentId = Account::query()->where('uuid', $accountUuid)->value('resident_id');

        return $residentId === null ? null : (string) $residentId;
    }

    public function linkResident(string $accountUuid, string $residentUuid): void
    {
        Account::query()->where('uuid', $accountUuid)->update(['resident_id' => $residentUuid]);
    }
}
