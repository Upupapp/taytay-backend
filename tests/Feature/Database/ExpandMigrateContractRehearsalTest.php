<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 18 — the first schema change after go-live, rehearsed rather than described.
 *
 * *"Rehearse the expand-migrate-contract pattern for the first schema change that will follow a
 * live system: add, backfill, switch, remove — never rename in place while the old code is
 * running."*
 *
 * ## Why this is a test and not a page in the runbook
 *
 * The runbook already describes the pattern, and a described pattern is one everybody agrees with
 * and nobody has performed. What goes wrong is never the concept; it is a specific, boring detail —
 * the backfill missing rows written *during* the backfill, the old code's query failing at step
 * three, a row lost between two of the four steps.
 *
 * So this performs all four steps against a populated table and asserts, at **every** step, the two
 * properties that matter on a live system:
 *
 *  1. **The previously deployed code still works.** Between deploys the old release is serving
 *     traffic. A step that breaks its query is an outage beginning the moment the migration commits.
 *  2. **No value is lost.** Not "the count matches" — a count matches trivially if a backfill wrote
 *     nulls.
 *
 * The worked example is a column rename, because that is the change whose one-line version is most
 * tempting and most damaging: it passes every test in this repository and takes the running
 * application down for the length of the deploy.
 */
final class ExpandMigrateContractRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'rehearsal_records';

    #[Test]
    public function a_column_is_renamed_without_the_running_application_noticing(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('contact_no');
        });

        $original = [];

        foreach (range(1, 50) as $n) {
            $original[$n] = '09'.str_pad((string) $n, 9, '0', STR_PAD_LEFT);
            DB::table(self::TABLE)->insert(['id' => $n, 'contact_no' => $original[$n]]);
        }

        $oldCodeReads = fn (): array => DB::table(self::TABLE)->pluck('contact_no', 'id')->all();
        $newCodeReads = fn (): array => DB::table(self::TABLE)->pluck('contact_number', 'id')->all();

        $this->assertSame($original, $oldCodeReads(), 'The fixture is wrong before anything happened.');

        // ── 1. EXPAND — add the new shape, nullable, touching nothing ────────────────
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('contact_number')->nullable();
        });

        $this->assertSame($original, $oldCodeReads(), 'Expand broke the running application. Nullable and unwritten should be invisible to it.');

        // ── 2. BACKFILL — in batches, with the old code serving throughout ───────────
        foreach (array_chunk(range(1, 50), 10) as $batch) {
            DB::table(self::TABLE)->whereIn('id', $batch)->update([
                'contact_number' => DB::raw('contact_no'),
            ]);

            $this->assertSame($original, $oldCodeReads(), 'The old query stopped working mid-backfill.');
        }

        /*
         * THE STEP EVERY DESCRIPTION LEAVES OUT.
         *
         * A row written *during* the backfill, by the still-running old code, has the old column set
         * and the new one null. Counting rows would not notice: the count is right and one value is
         * missing. So the check is for nulls, and the consequence is that the release which
         * backfills must also dual-write.
         */
        DB::table(self::TABLE)->insert(['id' => 51, 'contact_no' => '099999999']);
        $original[51] = '099999999';

        $this->assertSame(
            1,
            DB::table(self::TABLE)->whereNull('contact_number')->count(),
            'The rehearsal failed to produce the straggler it exists to demonstrate.'
        );

        DB::table(self::TABLE)->whereNull('contact_number')->update(['contact_number' => DB::raw('contact_no')]);

        $this->assertSame(
            0,
            DB::table(self::TABLE)->whereNull('contact_number')->count(),
            'Rows written during the backfill were never picked up. The count would have matched and one column would be empty.'
        );

        // ── 3. CUT OVER — new code reads the new column; both still present ──────────
        $this->assertSame($original, $newCodeReads(), 'The new column does not hold what the old one held.');
        $this->assertSame($original, $oldCodeReads(), 'The old code must keep working until it is no longer deployed.');

        // ── 4. CONTRACT — only now, and only in a later release ──────────────────────
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('contact_no');
        });

        $this->assertSame($original, $newCodeReads(), 'The rename lost data at the final step.');
        $this->assertSame(51, DB::table(self::TABLE)->count(), 'A row was lost across the four steps.');
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'contact_no'), 'Contract left the old column behind.');
    }

    /**
     * The one-line version, shown failing, so the reason for the four steps is not folklore.
     *
     * A rename in place is invisible to every test here — the schema is consistent, the data intact,
     * nothing errors. What it breaks is the **previously deployed code**, which no test in this
     * repository runs. So this reconstructs that: the old query, against the renamed table.
     */
    #[Test]
    public function renaming_in_place_breaks_the_release_that_is_still_serving_traffic(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('contact_no');
        });

        DB::table(self::TABLE)->insert(['id' => 1, 'contact_no' => '09171234567']);

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->renameColumn('contact_no', 'contact_number');
        });

        // The data is fine. That is exactly why this is tempting.
        $this->assertSame('09171234567', DB::table(self::TABLE)->value('contact_number'));

        try {
            DB::table(self::TABLE)->pluck('contact_no')->all();
            $this->fail('The old column still resolves, so this is not reproducing the failure it exists to show.');
        } catch (\Throwable $e) {
            /*
             * This is the outage: it starts when the migration commits and ends when the last old
             * process is replaced — every request in between, from the release still serving
             * traffic, against a column that no longer exists.
             */
            $this->assertStringContainsString('contact_no', $e->getMessage());
        }
    }
}
