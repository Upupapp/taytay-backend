<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Transactional;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Shared\Contracts\TransactionalDelivery;
use Modules\Shared\Contracts\TransactionalMessage;
use Modules\Shared\Contracts\TransactionalSender;

/**
 * Writes the message to the log so a developer can read the code. **Local only.**
 *
 * ---
 *
 * **This deliberately does the thing the whole design forbids**, and is therefore the most
 * dangerous file in the module. Every other rule here exists to keep a one-time code out of
 * persistent storage; this writes it to a file.
 *
 * That is the same trade Laravel's `log` mail driver makes, and it is worth making, because the
 * alternative is that nobody can exercise sign-in end to end until a vendor contract exists —
 * which is exactly the state that let F16 survive an entire integration sequence unnoticed.
 *
 * **So it fails closed, at construction.** A deployment that somehow binds it gets a
 * `LogicException` at boot rather than a log file full of credentials — a container that will not
 * start is recoverable, and a leaked sign-in code for every resident is not.
 */
final class LogTransactionalSender implements TransactionalSender
{
    /**
     * @throws LogicException when bound anywhere a real resident could be affected.
     */
    public function __construct(private readonly Application $app)
    {
        /*
         * TWO CONDITIONS, BOTH REQUIRED, AND THE SECOND IS THE ONE THAT MATTERS.
         *
         * An environment allow-list alone is not enough: the names are chosen by whoever writes
         * the `.env`, and a deployment that calls itself `integration` while serving real
         * residents would slip straight through one. So this also requires `APP_DEBUG` — which
         * `.env.integration` in this repository already documents as *"false everywhere except a
         * developer machine"*, because the API error renderer changes behaviour on it.
         *
         * Anything serving a real resident has debug off, and therefore cannot bind this.
         */
        if (! $this->app->environment(['local', 'testing', 'integration']) || ! config('app.debug')) {
            throw new LogicException(
                'LogTransactionalSender writes one-time codes to the log and may only run on a '
                .'developer machine (a local environment with APP_DEBUG on). Configure a real '
                .'transactional sender for '.$this->app->environment().'.',
            );
        }
    }

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(TransactionalMessage $message): TransactionalDelivery
    {
        Log::warning('Transactional message written to the log instead of being sent.', [
            'purpose' => $message->purpose,
            'recipient' => $message->maskedRecipient(),
            // The reason this class exists, and the reason it may not leave a developer machine.
            'text' => $message->text,
        ]);

        return TransactionalDelivery::sent();
    }
}
