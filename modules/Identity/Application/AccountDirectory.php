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

    /**
     * Detaches an account from the resident it was acting for.
     *
     * The account survives — it is a way to authenticate, not a person (see the accounts
     * migration). Deleting it because a link was wrong would destroy the sign-in history
     * and sessions of a real human being over a clerical error.
     */
    public function unlinkResident(string $accountUuid): void
    {
        Account::query()->where('uuid', $accountUuid)->update(['resident_id' => null]);
    }

    /**
     * Repoints every account from one resident to another, for a merge.
     *
     * Returns how many moved so the caller can record it as evidence. Without this seam a
     * merge would leave accounts pointing at a soft-deleted resident, and the person would
     * sign in to find their own record missing (CLAUDE.md Article 2.1).
     */
    public function reassignResident(string $fromResidentUuid, string $toResidentUuid): int
    {
        if ($fromResidentUuid === $toResidentUuid) {
            return 0;
        }

        return Account::query()
            ->where('resident_id', $fromResidentUuid)
            ->update(['resident_id' => $toResidentUuid]);
    }

    /**
     * The accounts currently acting for a resident.
     *
     * Account uuids only. A caller assembling a staff-facing link review needs to know
     * *which* accounts are attached; it does not need their credentials or contact details,
     * and this module will not hand those over.
     *
     * @return list<string>
     */
    public function accountIdsForResident(string $residentUuid): array
    {
        return Account::query()
            ->where('resident_id', $residentUuid)
            ->pluck('uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->values()
            ->all();
    }

    /**
     * Whether an account exists and may be linked to a resident at all.
     *
     * Staff accounts are refused: an LGU employee's sign-in must never double as a
     * resident's self-service identity, or an audit trail can no longer distinguish "the
     * resident updated their address" from "an employee updated it for them" (ADR 0009).
     */
    public function isLinkableCitizenAccount(string $accountUuid): bool
    {
        return Account::query()
            ->where('uuid', $accountUuid)
            ->where('account_type', 'citizen')
            ->exists();
    }
}
