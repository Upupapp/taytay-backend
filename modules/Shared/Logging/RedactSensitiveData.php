<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Monolog\LogRecord;

/**
 * Removes what must never reach a log file (ADR 0037 §2, Article 5.5).
 *
 * **A LOG IS THE LEAST-GUARDED COPY OF ANYTHING IT CONTAINS.** It is read by whoever is debugging,
 * shipped to whatever aggregator the LGU eventually buys, kept longer than the record it describes,
 * and pasted into a support ticket by somebody trying to be helpful. Article 5.5 names what must
 * never be in one: government identifiers, credential secrets, QR signing material, tokens,
 * passwords and full addresses.
 *
 * **REDACTION HAPPENS AT THE PROCESSOR, NOT AT THE CALL SITE**, and that is the whole design. A
 * rule applied where somebody remembers it is a rule that holds until the day somebody writes
 * `Log::error('Upload failed', ['request' => $request->all()])` while chasing a bug at four in the
 * afternoon. That line looks entirely reasonable and puts a resident's PhilSys number in a file.
 *
 * TWO PASSES, BECAUSE EITHER ALONE MISSES HALF:
 *
 *  * **by key name** — `password`, `token`, `philsys_number`. Catches a field whose value happens
 *    to look innocuous, and catches it even when the value is null.
 *  * **by value shape** — a PhilSys number, a bearer token, a long hex string. Catches the same
 *    data arriving under a name nobody predicted: `['payload' => '1234-5678-9012']`, or an
 *    exception message that quotes the SQL it failed on.
 *
 * The second is the one that matters in practice, because the dangerous log line is never the one
 * somebody designed.
 */
final class RedactSensitiveData
{
    public const REDACTED = '[redacted]';

    /**
     * How deep to walk. A context array nested deeper than this is almost certainly a serialised
     * object graph, which should not be in a log line at all.
     */
    private const MAX_DEPTH = 8;

    /**
     * Key names whose VALUE is removed whatever it looks like.
     *
     * Matched as a substring of the lower-cased key, so `philsys_number`, `philsysNumber` and
     * `resident_philsys` all match one entry. Substring matching over-redacts occasionally, and
     * that is the right direction: an over-redacted log costs somebody a second look at the
     * database, an under-redacted one cannot be un-written.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        // Credentials and secrets (Article 5.5, 5.6).
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'auth',
        'private_key', 'signing_key', 'credential', 'mfa', 'totp', 'recovery_code',
        'otp', 'code_hash', 'session',

        // Government and document identifiers.
        'philsys', 'psn', 'sss', 'tin', 'philhealth', 'pagibig', 'passport',
        'document_number', 'reference_number', 'card_number',

        // The narrative fields. A case note is a caseworker's assessment of a family, written for
        // a colleague; a log is not the colleague.
        'narrative', 'case_note', 'note_body', 'assessment', 'safeguarding',
        'moderation_reason', 'staff_notes',

        // Location precise enough to find somebody's home.
        'street_address', 'address_line', 'full_address', 'purok',
        /*
         * A PERSON'S NAME (TAB 15 step 10).
         *
         * The command's verification is *"search the logs for a seeded resident's name and find
         * nothing"*, and before this it would have been found. A name on its own is mild; a name
         * in a line reading `resident.viewed` is a statement that this person is in the welfare
         * registry, which is exactly the fact the whole system is careful with.
         *
         * The operator loses nothing: a uuid and the correlation id identify the record precisely,
         * and that is already how push payloads work — an identifier and a type, never the detail.
         *
         * SPECIFIC KEYS, not a bare `name`. Redacting `name` would blank programme names, event
         * titles, barangay names and saved-view names, leaving logs that no longer say what
         * happened — and a log nobody can read is a log nobody keeps.
         */
        'first_name', 'middle_name', 'last_name', 'maiden_name', 'suffix',
        'full_name', 'display_name', 'resident_name', 'head_name',
        'acknowledged_by_name', 'contact_person',
    ];

    /**
     * Value shapes that are redacted wherever they appear, under any key.
     *
     * @var array<string, string>
     */
    private const SENSITIVE_PATTERNS = [
        // PhilSys, in its printed form.
        'philsys' => '/\b\d{4}-\d{4}-\d{4}\b/',
        // A bearer token or a Sanctum token, which is `id|hash`.
        'bearer' => '/\bBearer\s+[A-Za-z0-9._\-|]{16,}/i',
        'sanctum' => '/\b\d+\|[A-Za-z0-9]{32,}\b/',
        // A long hex run: a hash, a signing key, a session id.
        'hex' => '/\b[a-f0-9]{40,}\b/i',
        // A PEM block. Never in a log, under any circumstances.
        'pem' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function scrub(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_' => '[truncated: too deeply nested for a log line]'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                // The KEY decides, so a null or an empty string is still redacted — the presence
                // of the field is itself the finding, and a caller cannot smuggle a value past by
                // making it look boring.
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->scrub($value, $depth + 1),
                is_string($value) => $this->scrubString($value),
                // Objects are not walked. A serialised model in a log line is the payload dump
                // this class exists to prevent, so it is replaced rather than inspected.
                is_object($value) => '['.$value::class.']',
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * Redacts anything in a string that matches a sensitive shape.
     *
     * Applied to the MESSAGE as well as the context, because an exception message is the most
     * common way a secret reaches a log: a database driver quoting the SQL it failed on, an HTTP
     * client quoting the URL it called, a validator quoting the value it refused.
     */
    public function scrubString(string $value): string
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $value = (string) preg_replace($pattern, self::REDACTED, $value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The vocabulary, for the test that proves this processor still reaches everything.
     *
     * @return array{keys: list<string>, patterns: array<string, string>}
     */
    public static function vocabulary(): array
    {
        return ['keys' => self::SENSITIVE_KEYS, 'patterns' => self::SENSITIVE_PATTERNS];
    }
}
