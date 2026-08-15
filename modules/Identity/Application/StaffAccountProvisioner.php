<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\AccountStatus;
use Modules\Identity\Contracts\AccountType;
use Modules\Identity\Contracts\StaffAccountSummary;
use Modules\Identity\Infrastructure\Eloquent\Account;

/**
 * Creating and deactivating staff accounts, published for AccessControl to call.
 *
 * Identity owns accounts; AccessControl owns authority. Neither reaches into the other's
 * tables (CLAUDE.md Article 2.1), so staff provisioning is two collaborating application
 * services rather than one service writing both stores.
 *
 * Note what is *not* here: this creates a way to sign in and nothing else. A brand-new
 * staff account holds no role, no scope and therefore no access to any resident record
 * until somebody with `staff.manage` grants it (ADR 0009, ADR 0012).
 */
final class StaffAccountProvisioner
{
    public function __construct(private readonly IdentityAudit $audit) {}

    /**
     * Creates a staff account, or returns the existing one for that email.
     *
     * Idempotent by email so a retried provisioning request — the network dropped, the
     * console retried — does not fail with a unique-constraint error or, worse, leave a
     * half-provisioned account behind.
     *
     * No password is set. Staff activate through the normal password-reset flow, which
     * means this endpoint never handles, returns or logs a credential.
     */
    public function create(string $email, string $displayName): StaffAccountSummary
    {
        $account = DB::transaction(function () use ($email, $displayName): Account {
            /** @var Account|null $existing */
            $existing = Account::withTrashed()->where('email', $email)->lockForUpdate()->first();

            if ($existing !== null) {
                // Re-provisioning someone who was deactivated restores the same account
                // rather than creating a second one, so their audit history stays attached
                // to one subject id.
                if ($existing->trashed() || $existing->status === AccountStatus::Deactivated) {
                    $existing->restore();
                    $existing->forceFill(['status' => AccountStatus::Pending])->save();
                }

                return $existing;
            }

            $account = new Account;
            $account->forceFill([
                'account_type' => AccountType::Staff,
                'email' => $email,
                'display_name' => $displayName,
                // Pending until they set a password and, for staff, enrol a second factor.
                'status' => AccountStatus::Pending,
            ])->save();

            return $account->refresh();
        });

        $this->audit->record($account, 'identity.staff-provisioned', 'Staff account provisioned');

        return $this->summarise($account);
    }

    /**
     * Deactivates a staff account and kills every live session.
     *
     * Deactivation alone is not enough: a bearer token issued yesterday keeps working
     * until it expires unless it is deleted. ActorContextFactory also refuses to build
     * authority for an inactive account, so the two together close the window from both
     * ends (ADR 0012).
     */
    public function deactivate(string $accountUuid): ?StaffAccountSummary
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('uuid', $accountUuid)
            ->where('account_type', AccountType::Staff)
            ->first();

        if ($account === null) {
            return null;
        }

        DB::transaction(function () use ($account): void {
            $account->forceFill(['status' => AccountStatus::Deactivated])->save();
            $account->tokens()->delete();
        });

        $this->audit->record($account, 'identity.staff-deactivated', 'Staff account deactivated');

        return $this->summarise($account->refresh());
    }

    public function summaryFor(string $accountUuid): ?StaffAccountSummary
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('uuid', $accountUuid)
            ->where('account_type', AccountType::Staff)
            ->first();

        return $account === null ? null : $this->summarise($account);
    }

    /**
     * The staff directory, newest first.
     *
     * @return array{items: list<StaffAccountSummary>, total: int}
     */
    public function paginate(int $page, int $perPage): array
    {
        $query = Account::query()->where('account_type', AccountType::Staff)->orderByDesc('id');

        return [
            'items' => (clone $query)->forPage($page, $perPage)->get()
                ->map(fn (Account $account): StaffAccountSummary => $this->summarise($account))
                ->all(),
            'total' => $query->count(),
        ];
    }

    private function summarise(Account $account): StaffAccountSummary
    {
        return new StaffAccountSummary(
            id: (string) $account->uuid,
            displayName: (string) $account->display_name,
            email: (string) $account->email,
            status: $account->status->value,
            lastSignedInAt: $account->last_signed_in_at?->toIso8601ZuluString(),
        );
    }
}
