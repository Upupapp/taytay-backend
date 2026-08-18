<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use SensitiveParameter;

/**
 * A message that must reach somebody now and be remembered by nobody.
 *
 * DELIBERATELY NOT AN OutboundNotification, AND THE DIFFERENCE IS THE WHOLE POINT.
 *
 * That type is inbox-shaped: it carries a recipient *subject id*, a rendered title and body, a
 * category and a deep-link subject, and `Notifier::notify()` persists it so the recipient can
 * read it back at `GET me/notifications`. Every one of those is wrong here:
 *
 * 1. **The text is a credential.** A one-time code written to a notifications table is a secret
 *    sitting in an inbox, readable over an authenticated API for as long as the row lives.
 * 2. **The recipient is not authenticated yet.** A notification addressed to a subject who
 *    cannot open their inbox until they have used the code is circular.
 * 3. **There is no subject to link to.** Nothing happened in the system that the recipient might
 *    want to look at afterwards. Something is being asked of them, right now.
 *
 * So this addresses a **number**, not an account, and its text exists only in memory between here
 * and the provider. Nothing in this module writes it anywhere.
 */
final class TransactionalMessage
{
    public function __construct(
        /**
         * The destination, as the account holds it. E.164 for Philippine mobiles.
         */
        public readonly string $recipient,
        /**
         * What this is for — `sign-in-code`, and one day others.
         *
         * Routing and audit only. It is safe to log; it says that a sign-in code was sent to
         * somebody, which is already in the audit trail, and never what the code was.
         */
        public readonly string $purpose,
        /**
         * The rendered text. **Never persisted, never logged, never returned.**
         *
         * `SensitiveParameter` so a stack trace from anywhere below this constructor shows
         * `Object(...)` instead of the code itself. Laravel writes stack traces to the log on
         * any unhandled exception, which is exactly the path by which a secret escapes without
         * anybody having written a line that logs it.
         */
        #[SensitiveParameter]
        public readonly string $text,
    ) {}

    /**
     * Redacted, on purpose and permanently.
     *
     * The number is masked too. It is not a secret, but it is personal data under RA 10173 and a
     * log line that pairs a mobile number with "we texted this person a sign-in code" is a record
     * of who holds an account here.
     */
    public function __toString(): string
    {
        return sprintf('TransactionalMessage(%s → %s, text redacted)', $this->purpose, $this->maskedRecipient());
    }

    /**
     * The last four digits only, which is what a support desk can confirm with somebody who is
     * holding the phone without disclosing the number to whoever reads the log.
     */
    public function maskedRecipient(): string
    {
        $length = strlen($this->recipient);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 4).substr($this->recipient, -4);
    }
}
