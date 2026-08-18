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
         * An environment allow-list, and only that.
         *
         * `testing` is deliberately NOT on it. The suite holds an invariant that a one-time code
         * never reaches the log (`CredentialLeakageTest`), and this class breaks that by design —
         * so the provider hands the testing environment a null sender no matter what the `.env`
         * says, and a test wanting to see a message binds its own capture sender instead.
         *
         * A second condition on `APP_DEBUG` was also tried and removed. The reasoning — *anything
         * serving a real resident has debug off* — is true and useless here: `phpunit.xml` forces
         * `APP_DEBUG=false`, so it excluded `testing` and took out thirty-one unrelated
         * authentication tests with a 500 before anything asserted on delivery.
         *
         * It bought nothing either. The threat is somebody copying a developer `.env` to a
         * server, and that `.env` carries `APP_DEBUG=true` along with everything else. The name
         * and the sender are chosen by the same person in the same file; a second field they also
         * control is not a second opinion.
         *
         * So this stops an accident — a production `.env` that names a sender it should not — and
         * nothing more. That is what an allow-list is for, and claiming more of it would be worse
         * than claiming less.
         */
        if (! $this->app->environment(['local', 'integration'])) {
            throw new LogicException(
                'LogTransactionalSender writes one-time codes to the log and may only run in a '
                .'local or integration environment. Configure a real transactional sender for '
                .$this->app->environment().'.',
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
