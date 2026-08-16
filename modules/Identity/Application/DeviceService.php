<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Illuminate\Database\Eloquent\Collection;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Identity\Infrastructure\Eloquent\Device;

/**
 * The devices an account signs in from, and where their push token lives.
 *
 * A device row is how a person recognises and revokes a session they do not recognise —
 * "sign out my old phone" is only meaningful if the list is readable and the entries are
 * named.
 *
 * The installation identifier is stored hashed. In the clear it is a stable per-install
 * key that would follow a person across accounts, which is a tracking identifier rather
 * than a security control (CLAUDE.md Article 5, data minimisation). The push token is
 * encrypted rather than hashed because it must be usable to send a notification.
 */
final class DeviceService
{
    public function __construct(private readonly IdentityAudit $audit) {}

    /**
     * Registers or refreshes a device. Idempotent by (account, installation) so a client
     * that re-registers on every launch updates one row instead of accumulating hundreds.
     */
    public function register(
        Account $account,
        string $fingerprint,
        string $displayName,
        string $platform,
        ?string $pushToken = null,
    ): Device {
        /** @var Device $device */
        $device = Device::query()->updateOrCreate(
            ['account_id' => $account->id, 'fingerprint_hash' => OneTimeCodes::hash($fingerprint)],
            [
                'display_name' => $displayName,
                'platform' => $platform,
                'push_token' => $pushToken,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        $this->audit->record($account, 'identity.device-registered', "Device registered: {$displayName}");

        return $device;
    }

    /** @return Collection<int, Device> */
    public function listActive(Account $account): Collection
    {
        return $account->devices()->whereNull('revoked_at')->orderByDesc('last_seen_at')->get();
    }

    /**
     * Revokes a device and clears its push token.
     *
     * Scoped to the account's own devices, so a caller cannot revoke somebody else's
     * device by guessing an identifier (OWASP API1). Clearing the push token matters as
     * much as the flag: a revoked device that still holds a token still receives
     * notifications about a person's case.
     */
    public function revoke(Account $account, string $deviceUuid): bool
    {
        /** @var Device|null $device */
        $device = $account->devices()->where('uuid', $deviceUuid)->first();

        if ($device === null) {
            return false;
        }

        $device->forceFill(['revoked_at' => now(), 'push_token' => null])->save();

        $this->audit->record($account, 'identity.device-revoked', "Device revoked: {$device->display_name}");

        return true;
    }

    /**
     * The push tokens an account can currently be reached on.
     *
     * Published for Notification, which owns *how* somebody is told and deliberately keeps no
     * device registry of its own — a second one would drift the moment a device was revoked here
     * and kept receiving push from there (Article 6, ADR 0025 §5).
     *
     * Revoked devices are excluded at the query. A revoked device that still received
     * notifications about a person's case would make revocation a gesture.
     *
     * @return list<string>
     */
    public function activePushTokensFor(string $accountUuid): array
    {
        return Device::query()
            ->whereIn('account_id', Account::query()->select('id')->where('uuid', $accountUuid))
            ->whereNull('revoked_at')
            ->whereNotNull('push_token')
            ->pluck('push_token')
            ->map(static fn (mixed $token): string => (string) $token)
            ->values()
            ->all();
    }

    /**
     * Clears a push token the provider has told us is dead.
     *
     * NOT a revocation. The phone is still a device the person logs in from and still carries its
     * trust stamp; it simply cannot receive push any more — usually because the app was
     * uninstalled or the token rotated. Revoking the device instead would sign somebody out of a
     * handset they are holding.
     */
    public function clearDeadPushToken(string $accountUuid, string $reason): int
    {
        return Device::query()
            ->whereIn('account_id', Account::query()->select('id')->where('uuid', $accountUuid))
            ->whereNull('revoked_at')
            ->whereNotNull('push_token')
            ->update(['push_token' => null]);
    }
}
