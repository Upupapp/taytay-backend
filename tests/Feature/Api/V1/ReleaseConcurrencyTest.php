<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 08 step 8 — two officers releasing the same payout at the same instant.
 *
 * *"Two officers releasing the same payout simultaneously must produce one success and one refusal.
 * Prove it against real PostgreSQL — not the test suite's SQLite, where row locking compiles away."*
 *
 * ── WHY THIS FILE IS A SKIP AND NOT A TEST ───────────────────────────────────────────
 *
 * **It cannot be written honestly yet, and a placeholder would be worse than nothing.**
 *
 * Two things stand in the way, and only one of them is the environment:
 *
 *  1. **SQLite would pass it for the wrong reason.** SQLite serialises writes at the file level and
 *     has no row lock at all, so `lockForUpdate` compiles away. The assertion would hold, the suite
 *     would go green, and the report would claim a guarantee nobody had checked.
 *  2. **A body written against a database nobody can run is a body nobody has run.** Writing the
 *     two-connection race blind and marking it skipped produces code that looks verified, sits in
 *     the suite for months, and fails on the day somebody finally provisions PostgreSQL — at which
 *     point the failure reads as a regression rather than as a test that was never right.
 *
 * So this states the obligation and refuses to pretend. What must be proven, when the environment
 * exists:
 *
 *  * two connections, each opening a transaction against the same `releases` row;
 *  * the first takes `lockForUpdate`, transitions `ready → released`, commits;
 *  * the second blocks on the lock, re-reads **inside its own transaction**, finds `released`, and
 *    is refused by the state machine;
 *  * exactly one row in `release_transitions` with `to_status = released`.
 *
 * ── WHAT IS PROVEN TODAY ─────────────────────────────────────────────────────────────
 *
 *  * The **sequential** double-release is refused —
 *    `ReleaseTest::a_second_confirmation_without_a_key_is_refused_by_the_state_machine`.
 *  * The **replayed** double-release is refused —
 *    `ReleaseTest::the_same_key_and_the_same_body_replays_rather_than_scheduling_twice`.
 *  * `lockForUpdate` and the in-transaction re-read are written in `ReleaseService::confirmRelease`.
 *
 * None of that is the concurrent case. Provisioning PostgreSQL is on the master TODO, and until it
 * happens **TAB 08's concurrency criterion is unmet**, which is recorded rather than glossed.
 */
final class ReleaseConcurrencyTest extends TestCase
{
    #[Test]
    public function concurrent_release_is_unproven_until_postgresql_exists(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->markTestSkipped(
            "TAB 08 step 8 is not proven. The suite runs on {$driver}; the criterion requires real "
            .'PostgreSQL, because on SQLite row locking compiles away and the test would pass for a '
            .'reason unrelated to the code. See this class docblock for what must be asserted.',
        );
    }
}
