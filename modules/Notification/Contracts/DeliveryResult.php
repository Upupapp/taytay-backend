<?php

declare(strict_types=1);

namespace Modules\Notification\Contracts;

/**
 * What a channel reports back.
 *
 * A value object rather than a thrown exception, because a provider failure is an ordinary
 * outcome here and not an error in this system: the assistance was still approved.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        /**
         * Set when the provider says this token is dead — unregistered, uninstalled, replaced.
         *
         * The caller deactivates it. Without this the same dead token is retried every time
         * forever, and a person who changed phones generates a permanent stream of failures
         * nobody can distinguish from a real outage.
         */
        public readonly bool $tokenIsDead = false,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self('sent', $providerMessageId);
    }

    public static function failed(string $reason, bool $tokenIsDead = false): self
    {
        return new self('failed', null, $reason, $tokenIsDead);
    }

    /** No provider is configured in this environment. A deployment fact, not an incident. */
    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    public function wasSent(): bool
    {
        return $this->status === 'sent';
    }
}
