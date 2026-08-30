<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Replay protection for retryable writes (ADR 0008 §7, conventions §7).
 *
 * `idempotency_keys` has existed since TAB 04 with **no caller**. This is it. The promise the
 * conventions document already makes to the Flutter client — that a state-changing request
 * carrying an `Idempotency-Key` can be retried safely — was until now a promise nothing kept.
 *
 * WHAT PROBLEM THIS ACTUALLY SOLVES. A citizen on a weak connection taps "submit", the request
 * reaches the server, the response never arrives, and the app retries. Without this, the
 * second attempt opens a second case: two files for one person, worked independently by two
 * reviewers, and the office discovers it at payout. Client-side de-duplication cannot fix it,
 * because the client is exactly the thing that lost the answer.
 *
 * THREE OUTCOMES, ALL DELIBERATE:
 *
 *  * **Same key, same body, completed** → the stored response is replayed verbatim. The caller
 *    cannot tell it from the original, which is the point.
 *  * **Same key, different body** → `409`. A key reused for a different payload is a client
 *    bug, and answering it with the old result would silently discard the new request.
 *  * **Same key, still in flight** → `409`. Two concurrent attempts are not a replay; letting
 *    both execute is the duplicate this class exists to prevent.
 *
 * Scoped by `subject_id` and `endpoint`, so one caller can never replay another's response and
 * a key cannot be reused across operations.
 */
final class IdempotencyService
{
    /** How long a stored response stays replayable. */
    private const RETENTION_HOURS = 24;

    /**
     * Runs an operation at most once per key.
     *
     * @template T
     *
     * @param  Closure(): array{0: int, 1: array<string, mixed>}  $operation  status + body
     * @return array{0: int, 1: array<string, mixed>, 2: bool} status, body, whether replayed
     */
    public function execute(
        ?string $key,
        ?string $subjectId,
        string $endpoint,
        array $payload,
        Closure $operation,
    ): array {
        // No key, no protection. The endpoint still works — an idempotency key is a promise a
        // client opts into, not a requirement we impose on every caller.
        if ($key === null || trim($key) === '') {
            [$status, $body] = $operation();

            return [$status, $body, false];
        }

        $fingerprint = $this->fingerprint($payload);

        $existing = DB::table('idempotency_keys')
            ->where('idempotency_key', $key)
            ->where('endpoint', $endpoint)
            ->when($subjectId === null, fn ($q) => $q->whereNull('subject_id'))
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            if ((string) $existing->request_fingerprint !== $fingerprint) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That idempotency key was already used with a different request body.',
                );
            }

            if ($existing->completed_at !== null && $existing->response_status !== null) {
                /** @var array<string, mixed> $body */
                $body = json_decode((string) $existing->response_body, true) ?: [];

                return [(int) $existing->response_status, $body, true];
            }

            // Claimed but not finished. Two concurrent attempts are not a replay.
            throw new ApiException(
                ErrorCode::Conflict,
                'An identical request is already being processed. Retry shortly.',
            );
        }

        /*
         * Claim the key BEFORE doing the work, in its own committed write.
         *
         * If the claim were inside the operation's transaction, a rollback would erase it and
         * a concurrent retry would find nothing — which is the duplicate this whole class
         * exists to prevent. The unique index is the real arbiter: two racing claims mean one
         * insert fails, and that caller is told to retry rather than allowed to proceed.
         */
        /*
         * WRAPPED SO THE FAILURE IS SURVIVABLE, which is a PostgreSQL requirement and not a
         * style choice.
         *
         * A losing insert violates the unique index, and on PostgreSQL that ABORTS the enclosing
         * transaction: every later statement answers `25P02 current transaction is aborted`, so
         * the 409 below cannot be rendered and the caller gets a 500 instead. SQLite has no such
         * rule, and the suite runs on SQLite, so two tests asserted a clean 409 for as long as
         * this code has existed while production would have answered 500 to the same request.
         *
         * A nested `DB::transaction()` is a SAVEPOINT when one is already open, so the rollback
         * undoes only the failed insert. Outside a transaction it is an ordinary committed write,
         * which is what the comment above requires.
         */
        try {
            DB::transaction(fn () => DB::table('idempotency_keys')->insert([
                'uuid' => (string) Str::uuid7(),
                'idempotency_key' => $key,
                'subject_id' => $subjectId,
                'endpoint' => $endpoint,
                'request_fingerprint' => $fingerprint,
                'locked_at' => now(),
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (\Throwable) {
            // Lost the race to insert. The winner is executing; this caller must not.
            throw new ApiException(
                ErrorCode::Conflict,
                'An identical request is already being processed. Retry shortly.',
            );
        }

        [$status, $body] = $operation();

        DB::table('idempotency_keys')
            ->where('idempotency_key', $key)
            ->where('endpoint', $endpoint)
            ->when($subjectId === null, fn ($q) => $q->whereNull('subject_id'))
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->update([
                'response_status' => $status,
                'response_body' => json_encode($body),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return [$status, $body, false];
    }

    /**
     * A stable hash of the request payload.
     *
     * Keys are sorted recursively so that two semantically identical bodies with different
     * field order do not read as a client bug — JSON object order is not significant, and a
     * client that serialises a map is under no obligation to preserve it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        $normalised = $this->sortRecursive($payload);

        return hash('sha256', (string) json_encode($normalised));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }
}
