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
}
