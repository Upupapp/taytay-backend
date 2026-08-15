<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Application\AccountDirectory;
use Modules\ResidentProfile\Infrastructure\Eloquent\AccountResidentLink;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Authorising an account to act for a canonical resident, reviewably (ADR 0013 §5).
 *
 * WHY THIS IS NOT JUST A COLUMN. `accounts.resident_id` answers "who does this account act
 * for" in one indexed read, and Identity keeps it for that. But a column is mutable and
 * remembers nothing: repoint it and every trace that the account was ever attached to
 * somebody else is gone. Linking an account to the wrong resident hands one person another
 * person's welfare file — so the *history* is the control, and the column is a cache of its
 * current state (ADR 0008 §10).
 *
 * The two are written together, in one transaction, by this class and nothing else.
 *
 * A LINK IS NOT A PERMISSION. Being attached to a resident lets an account act as that
 * resident in the citizen API; it grants no staff capability and no access to anybody
 * else's record. Authorization stays a separate decision (ADR 0002).
 */
final class AccountLinkService
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly ResidentProfileAudit $audit,
    ) {}

    /**
     * Attaches an account to a resident.
     *
     * Idempotent: re-linking an account that is already actively linked to this resident
     * returns the existing row rather than stacking a second one, so a retried request
     * cannot manufacture a second history.
     *
     * @param  string  $origin  'kyc-approval' | 'staff-link' | 'merge'
     */
    public function link(
        Resident $resident,
        string $accountUuid,
        string $origin,
        ActorContext $actor,
    ): AccountResidentLink {
        return DB::transaction(function () use ($resident, $accountUuid, $origin, $actor): AccountResidentLink {
            if (! $this->accounts->isLinkableCitizenAccount($accountUuid)) {
                /*
                 * NOT FOUND rather than a validation error, and deliberately the same
                 * response whether the account does not exist or is a staff account. A
                 * distinguishable message here is an oracle for probing which account ids
                 * are real (OWASP API1).
                 */
                throw new ApiException(ErrorCode::NotFound, 'That account was not found.');
            }

            /** @var AccountResidentLink|null $existing */
            $existing = AccountResidentLink::query()
                ->where('account_id', $accountUuid)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((int) $existing->resident_id === (int) $resident->id) {
                    return $existing;
                }

                /*
                 * An account may act for exactly one resident (ADR 0010 §5). Moving it
                 * requires revoking the old link explicitly, so that reassignment is a
                 * decision somebody made and can be asked about — never a side effect of
                 * creating a new link.
                 */
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That account is already linked to another resident. Revoke the existing link first.',
                );
            }

            $link = AccountResidentLink::query()->create([
                'resident_id' => $resident->id,
                'account_id' => $accountUuid,
                'origin' => $origin,
                'status' => 'active',
                'linked_by' => $actor->subjectId,
                'linked_at' => now(),
            ]);

            // The cache follows the record, inside the same transaction. If this throws,
            // the history row rolls back with it and the two cannot disagree.
            $this->accounts->linkResident($accountUuid, (string) $resident->uuid);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.account-linked',
                "Account linked to resident record ({$origin})",
                (string) $resident->uuid,
            );

            return $link;
        });
    }

    /**
     * Withdraws a link.
     *
     * The row is kept and marked revoked rather than deleted: "this account used to be able
     * to act for that resident" is exactly the fact a privacy complaint asks about, and a
     * deleted row cannot answer it.
     */
    public function revoke(AccountResidentLink $link, ActorContext $actor, string $reason): AccountResidentLink
    {
        return DB::transaction(function () use ($link, $actor, $reason): AccountResidentLink {
            /** @var AccountResidentLink $link */
            $link = AccountResidentLink::query()->lockForUpdate()->findOrFail($link->id);

            if (! $link->isActive()) {
                throw new ApiException(ErrorCode::Conflict, 'That link has already been revoked.');
            }

            $link->forceFill([
                'status' => 'revoked',
                'revoked_by' => $actor->subjectId,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $this->accounts->unlinkResident((string) $link->account_id);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.account-unlinked',
                'Account link to resident record revoked',
                null,
            );

            return $link->refresh();
        });
    }

    /**
     * Every link a resident has ever had, active first then most recent.
     *
     * @return Collection<int, AccountResidentLink>
     */
    public function forResident(Resident $resident): Collection
    {
        return AccountResidentLink::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('status')
            ->orderByDesc('linked_at')
            ->get();
    }

    /**
     * Moves active links from the absorbed resident to the survivor during a merge.
     *
     * Returns the number of accounts moved.
     */
    public function reassign(Resident $absorbed, Resident $survivor): int
    {
        AccountResidentLink::query()
            ->where('resident_id', $absorbed->id)
            ->where('status', 'active')
            ->update(['resident_id' => $survivor->id]);

        return $this->accounts->reassignResident((string) $absorbed->uuid, (string) $survivor->uuid);
    }
}
