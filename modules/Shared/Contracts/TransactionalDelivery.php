<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/**
 * Whether a transactional message left the building.
 *
 * A value object rather than a thrown exception, for the same reason `Notification`'s
 * `DeliveryResult` is one: a provider failure is an ordinary outcome, not a system error.
 *
 * **A separate type from that one, deliberately.** `DeliveryResult` lives in
 * `Notification/Contracts`, and a caller in `Identity` importing it would rebuild the very cycle
 * this contract exists to avoid — `ModuleBoundaryTest` caught exactly that on the first attempt.
 * It also carries `tokenIsDead`, which is a push concept with no meaning for a text message.
 *
 * **Carries no message text, and must not be made to.** `failureReason` is logged.
 */
final class TransactionalDelivery
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self('sent', $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', null, $reason);
    }

    /**
     * No sender is configured, or there was nobody to send to.
     *
     * A deployment fact, not an incident — and the two cases are deliberately indistinguishable
     * to a caller, because the difference between "we have no SMS provider" and "that number
     * holds no account" must never reach a client.
     */
    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    public function wasSent(): bool
    {
        return $this->status === 'sent';
    }
}
