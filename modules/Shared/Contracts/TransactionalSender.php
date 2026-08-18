<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/**
 * Delivery that persists nothing.
 *
 * THE SEAM L-18 SAID WAS MISSING. A sign-in code was issued, hashed, recorded and then discarded
 * by a deliberate `unset()` — every part of that correct — and nothing carried it to a person.
 * The gap was not an unwritten adapter; it was that the only published way to reach somebody from
 * another module was `Notifier::notify()`, which is the wrong shape for a credential (see
 * [TransactionalMessage]).
 *
 * Narrow on purpose. One method, one message, one answer: did it leave. No preferences, no
 * categories, no retry policy, no queue. A sign-in code that arrives four minutes late has
 * already expired, so queueing it would turn a failure into a worse failure that looks like a
 * success — which is why this is synchronous where `Notifier` is not.
 *
 * **IT LIVES IN `Shared`, AND THAT IS NOT WHERE IT WAS FIRST WRITTEN.** The obvious home is
 * `Notification/Contracts`, next to `NotificationChannel`. `ModuleBoundaryTest` refused it:
 * `Notification` already depends on `Identity`, so `Identity -> Notification` closes a cycle, and
 * the test named thirty-nine of them in one run.
 *
 * The resolution is the one `Modules\Shared\Contracts\AuditWriter` already uses for the same
 * shape of problem -- everybody needs it, its implementer has a surface of its own to protect --
 * and the one the boundary map prescribes for any downward dependency: invert it. The interface
 * goes in the module everyone may depend on and which depends on nothing; `Notification` provides
 * the adapter and stays free to depend on `Identity` like any other module.
 *
 * Shared holds the **interface only** (Article 2.3). The adapters are in
 * `Notification/Infrastructure/Transactional`, bound in `NotificationServiceProvider`.
 */
interface TransactionalSender
{
    /**
     * The adapter's stable name, for audit and operational logging.
     */
    public function name(): string;

    /**
     * Whether this environment has what this sender needs.
     *
     * `false` is a deployment fact, not an incident — the same distinction the notification
     * channels draw. A deployment with no SMS provider is a deployment where nobody can sign in,
     * and it should be visible as a configuration state rather than as a stream of failures.
     */
    public function isConfigured(): bool;

    /**
     * Attempts delivery, now.
     *
     * @return TransactionalDelivery Never throws for a provider-side failure, and **never puts the
     *                        message text in `failureReason`** — that string is logged.
     */
    public function send(TransactionalMessage $message): TransactionalDelivery;
}
