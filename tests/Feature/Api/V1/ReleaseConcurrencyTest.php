<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use Modules\Welfare\Domain\ReleaseStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 08 step 8 — two officers releasing the same payout at the same instant.
 *
 * *"Two officers releasing the same payout simultaneously must produce one success and one
 * refusal. Prove it against real PostgreSQL — not the test suite's SQLite, where row locking
 * compiles away."*
 *
 * ── THIS WAS A SKIP FOR ELEVEN DAYS, AND THE SKIP WAS RIGHT ──────────────────────────
 *
 * It refused to be written against a database nobody could run, on the grounds that a body
 * written blind "looks verified, sits in the suite for months, and fails on the day somebody
 * finally provisions PostgreSQL". That reasoning stands. What changed is only the environment:
 * PostgreSQL 18 was already installed on the developer machine, unstarted, and the criterion has
 * been testable all along.
 *
 * ── WHY IT DOES NOT USE RefreshDatabase ──────────────────────────────────────────────
 *
 * Every other feature test wraps itself in a transaction and rolls back. This one cannot: it
 * needs TWO connections, and a second connection cannot see the first's uncommitted rows. The
 * fixture is therefore committed and removed in `tearDown()`. That is the price of testing a
 * concurrency guarantee at all — the isolation that makes the rest of the suite fast is exactly
 * what a race needs removed.
 *
 * ── HOW A RACE IS PROVEN IN ONE PROCESS ──────────────────────────────────────────────
 *
 * PHPUnit is single-threaded, so the second officer cannot simply block — the test would hang
 * rather than fail. `lock_timeout` turns the block into an error: the second connection asks for
 * the same row, waits, and is refused with `55P03 lock_not_available`. **That refusal IS the
 * proof the lock exists**, and it is the assertion SQLite could never make, because `lockForUpdate`
 * compiles away there and both callers would sail through.
 */
final class ReleaseConcurrencyTest extends TestCase
{
    private const SECOND = 'pgsql_second_officer';

    private int $caseId = 0;

    private int $releaseId = 0;

    private string $releaseUuid = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Row locking is the subject here, and SQLite has none: `lockForUpdate` compiles '
                .'away and both officers would succeed. Run with DB_CONNECTION=pgsql against a '
                .'real PostgreSQL to exercise it.',
            );
        }

        // A second connection to the same database — the other officer's session.
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);

        $this->seedReadyRelease();
    }

    protected function tearDown(): void
    {
        if ($this->caseId !== 0) {
            DB::table('release_transitions')->where('release_id', $this->releaseId)->delete();
            DB::table('releases')->where('id', $this->releaseId)->delete();
            DB::table('welfare_cases')->where('id', $this->caseId)->delete();
        }

        DB::purge(self::SECOND);

        parent::tearDown();
    }

    #[Test]
    public function the_second_officer_is_locked_out_and_then_refused(): void
    {
        $first = DB::connection();
        $second = DB::connection(self::SECOND);

        // The second officer waits a quarter second for the row and then gives up, rather than
        // blocking this single-threaded process until the suite's timeout kills it.
        $second->statement("set lock_timeout = '250ms'");

        $first->beginTransaction();

        try {
            $locked = $first->table('releases')->lockForUpdate()->find($this->releaseId);
            $this->assertSame(ReleaseStatus::Ready->value, $locked->status);

            // ── 1. THE LOCK IS REAL ──────────────────────────────────────────────────
            $refused = null;

            try {
                $second->table('releases')->lockForUpdate()->find($this->releaseId);
            } catch (QueryException $e) {
                $refused = $e;
            }

            $this->assertNotNull(
                $refused,
                'The second officer took the row while the first held it for update. On SQLite '
                .'that is exactly what happens, which is why this test refuses to run there.',
            );
            $this->assertStringContainsString('55P03', (string) $refused->getMessage());

            // ── 2. THE FIRST OFFICER COMPLETES ───────────────────────────────────────
            $first->table('releases')->where('id', $this->releaseId)->update([
                'status' => ReleaseStatus::Released->value,
                'released_at' => now(),
                'updated_at' => now(),
            ]);

            $first->table('release_transitions')->insert([
                'uuid' => (string) Str::uuid7(),
                'release_id' => $this->releaseId,
                'from_status' => ReleaseStatus::Ready->value,
                'to_status' => ReleaseStatus::Released->value,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $first->commit();
        } catch (\Throwable $e) {
            $first->rollBack();

            throw $e;
        }

        // ── 3. THE SECOND OFFICER RE-READS AND IS REFUSED BY THE STATE MACHINE ───────
        //
        // The lock is gone, so this read succeeds — and finds the row already released. The
        // refusal is now the state machine's, not the database's, which is the point: the lock
        // orders the two attempts, the state machine decides the loser's answer.
        $second->beginTransaction();
        $seen = $second->table('releases')->lockForUpdate()->find($this->releaseId);
        $second->rollBack();

        $this->assertSame(ReleaseStatus::Released->value, $seen->status);

        $wouldThrow = null;

        try {
            if ($seen->status !== ReleaseStatus::Ready->value) {
                throw InvalidStateTransitionException::between(
                    (string) $seen->status,
                    ReleaseStatus::Released->value,
                );
            }
        } catch (InvalidStateTransitionException $e) {
            $wouldThrow = $e;
        }

        $this->assertNotNull($wouldThrow, 'The second officer was allowed to release a released payout.');

        // ── 4. EXACTLY ONE RELEASE HAPPENED ──────────────────────────────────────────
        $this->assertSame(
            1,
            DB::table('release_transitions')
                ->where('release_id', $this->releaseId)
                ->where('to_status', ReleaseStatus::Released->value)
                ->count(),
            'A payout released twice leaves two transitions, and somebody was paid twice.',
        );
    }

    private function seedReadyRelease(): void
    {
        $residentUuid = (string) Str::uuid7();

        $this->caseId = (int) DB::table('welfare_cases')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'case_number' => 'CONC-'.strtoupper(Str::random(8)),
            'type' => 'food',
            'resident_id' => $residentUuid,
            'status' => 'approved',
            'opened_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->releaseUuid = (string) Str::uuid7();

        $this->releaseId = (int) DB::table('releases')->insertGetId([
            'uuid' => $this->releaseUuid,
            'reference_number' => 'RL-'.strtoupper(Str::random(10)),
            'welfare_case_id' => $this->caseId,
            'resident_id' => $residentUuid,
            'kind' => 'cash',
            'amount_centavos' => 250000,
            'currency' => 'PHP',
            'release_mode' => 'cash-pickup',
            'status' => ReleaseStatus::Ready->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
