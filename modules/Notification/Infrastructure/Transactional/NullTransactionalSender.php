<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Transactional;

use Modules\Shared\Contracts\TransactionalDelivery;
use Modules\Shared\Contracts\TransactionalMessage;
use Modules\Shared\Contracts\TransactionalSender;

/**
 * The default, and the honest one: no provider, so nothing was sent.
 *
 * **`skipped`, never `sent`.** The distinction is the same one the notification channels draw and
 * it matters more here: a deployment reporting "delivered" for a sign-in code that never left the
 * building tells an operator that residents can sign in, while every one of them is standing at a
 * counter saying no text arrived.
 *
 * This platform has **no SMS provider at all** — not unconfigured, not chosen. That is a
 * procurement decision for the LGU, and it is on the master manual-task list. Until it is made,
 * this is what is bound, and `POST auth/otp` still answers 202 because telling a client whether
 * delivery succeeded would turn sign-in into an account-existence oracle. The truth is in the
 * audit trail and in the operational log instead.
 */
final class NullTransactionalSender implements TransactionalSender
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(TransactionalMessage $message): TransactionalDelivery
    {
        return TransactionalDelivery::skipped('No transactional sender is configured in this environment.');
    }
}
