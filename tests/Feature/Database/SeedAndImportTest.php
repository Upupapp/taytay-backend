<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Import\ImportPipeline;
use Modules\Shared\Import\RowMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The acceptance criteria of TAB 35, as tests.
 *
 *  1. **Demo relationships are internally consistent.**
 *  2. **No real PII is seeded.**
 *  3. **The import framework can dry-run and report bad rows before commit.**
 */
final class SeedAndImportTest extends TestCase
{
    use RefreshDatabase;

    // ── criterion 2: nothing seeded could belong to a real person ────────────────────

    #[Test]
    public function the_demo_dataset_contains_no_plausibly_real_contact_details(): void
    {
        $this->seed(DemoDataSeeder::class);

        $residents = DB::table('residents')->get();

        $this->assertGreaterThan(5, $residents->count(), 'The seeder produced almost nothing to check.');

        foreach ($residents as $resident) {
            /*
             * `.test` IS RESERVED (RFC 6761) AND CAN NEVER RESOLVE. A plausible-looking
             * `@gmail.com` address in a demo dataset absolutely can, the first time somebody
             * points a staging environment at a real mail provider — and the person who receives
             * the notification is a stranger being told about somebody else's welfare case.
             */
            $this->assertStringEndsWith('@example.test', (string) $resident->email);

            // A fixed reserved block, not a random number. Unlikely is not the same as impossible.
            $this->assertStringStartsWith('+63917000', (string) $resident->mobile_number);
        }
    }

    #[Test]
    public function no_government_identifier_is_seeded_at_all(): void
    {
        $this->seed(DemoDataSeeder::class);

        /*
         * NOT EVEN AN INVENTED ONE. A plausible-looking government identifier in a database is a
         * plausible-looking government identifier, and somebody will eventually paste one into a
         * form that checks it — or into a support ticket, or a screenshot.
         */
        foreach (DB::table('residents')->get() as $resident) {
            $this->assertNull($resident->philsys_last_four);
        }

        // And nothing anywhere in the seeded rows looks like one.
        $serialised = (string) json_encode(DB::table('residents')->get());
        $this->assertSame(0, preg_match('/\b\d{4}-\d{4}-\d{4}\b/', $serialised));
    }

    // ── criterion 1: the demo data is coherent ───────────────────────────────────────

    #[Test]
    public function every_seeded_resident_lives_in_the_barangay_of_their_household(): void
    {
        $this->seed(DemoDataSeeder::class);

        $mismatches = [];

        foreach (DB::table('household_memberships')->whereNull('effective_to')->get() as $membership) {
            $household = DB::table('households')->find($membership->household_id);
            $resident = DB::table('residents')->find($membership->resident_id);

            if ($household === null || $resident === null) {
                // A membership pointing at a record that does not exist is the incoherence this
                // test exists to catch, and it is worth reporting distinctly from a mismatch.
                $mismatches[] = 'membership '.$membership->id.' points at a missing record';

                continue;
            }

            if ((int) $household->barangay_id !== (int) $resident->barangay_id) {
                $mismatches[] = sprintf(
                    'resident %d is in barangay %d, household %d is in %d',
                    $resident->id,
                    $resident->barangay_id,
                    $household->id,
                    $household->barangay_id,
                );
            }
        }

        $this->assertSame([], $mismatches, implode("\n", $mismatches));
    }

    #[Test]
    public function the_demo_dataset_covers_all_five_barangays(): void
    {
        $this->seed(DemoDataSeeder::class);

        // The master command names five barangays. A demo that only populated one would look fine
        // until somebody tested barangay scoping against it.
        $this->assertSame(5, DB::table('barangays')->count());
        /*
         * Counted in PHP rather than with `distinct()->count($column)`, which does not survive
         * SQLite — it returned 0 against the test database while returning 5 against the
         * development one. A portable expression is worth more than a clever one in an assertion
         * whose failure would otherwise read as a broken seeder (Article 1).
         */
        $spanned = DB::table('households')->pluck('barangay_id')->unique()->count();

        $this->assertSame(5, $spanned, 'The demo households do not span every barangay.');
    }

    #[Test]
    public function every_seeded_record_has_the_public_identifier_the_api_exposes(): void
    {
        $this->seed(DemoDataSeeder::class);

        /*
         * THE BUG THIS TEST WAS WRITTEN FOR. The seeder originally used `WithoutModelEvents` —
         * standard seeder hygiene — which suppressed the `creating` hook that mints every model's
         * public UUID. The first run failed on a NOT NULL constraint; had the column been
         * nullable it would have produced a registry of records the API could not address.
         */
        foreach (['residents', 'households'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->whereNull('uuid')->count(),
                "[{$table}] has rows with no public identifier.",
            );
        }
    }

    // ── criterion 3: dry run before commit ───────────────────────────────────────────

    #[Test]
    public function a_dry_run_reports_bad_rows_and_writes_nothing(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
            2 => ['first_name' => '', 'last_name' => 'Bautista', 'source_id' => 'L-2'],
            3 => ['first_name' => 'Teodoro', 'last_name' => '', 'source_id' => 'L-3'],
        ]);

        $outcome = $pipeline->validate($batch);

        $this->assertSame(3, $outcome->total);
        $this->assertSame(1, $outcome->valid);
        $this->assertSame(2, $outcome->rejected);
        $this->assertTrue($outcome->wasDryRun);

        /*
         * THE REPORT NAMES THE LINE, so an operator can find the row in the spreadsheet in front
         * of them — and lists EVERY reason, because fixing one problem per pass through a
         * four-thousand-row file is three round trips.
         */
        $this->assertSame(2, (int) $outcome->rejections[0]['line']);
        $this->assertNotEmpty($outcome->rejections[0]['reasons']);

        // Nothing reached the registry.
        $this->assertSame(0, DB::table('residents')->count());
    }

    #[Test]
    public function a_batch_with_any_rejection_cannot_be_committed(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
            2 => ['first_name' => '', 'last_name' => 'Bautista', 'source_id' => 'L-2'],
        ]);

        $pipeline->validate($batch);

        /*
         * STRICTER THAN IT NEEDS TO BE, DELIBERATELY. Importing the good rows and leaving the bad
         * ones produces a partial registry that looks complete — and the missing people are the
         * ones whose data was messiest, which correlates with the households that need the office
         * most.
         */
        $this->expectExceptionMessage('rejected rows');
        $pipeline->commit($batch, static fn (array $row): string => 'never-called');
    }

    #[Test]
    public function a_batch_cannot_be_committed_before_it_is_validated(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
        ]);

        // The dry run is not a mode with a flag somebody can forget — it is a separate call that
        // must have happened. There is no argument to get wrong.
        $this->expectExceptionMessage('has not been validated');
        $pipeline->commit($batch, static fn (array $row): string => 'never-called');
    }

    #[Test]
    public function a_row_the_system_already_holds_is_a_duplicate_rather_than_an_error(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $first = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
        ]);
        $pipeline->validate($first);

        // The same source row in a later overlapping export — the common case when an office
        // sends a fresh extract.
        $second = $pipeline->stage('resident', 'Legacy export, 2021', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
        ]);
        $outcome = $pipeline->validate($second);

        /*
         * DUPLICATE, NOT REJECTED. They need different answers: a rejection is bad data to fix, a
         * duplicate is a record the system already has and the right response is usually to skip
         * it. Conflating them makes a re-run report thousands of "errors" that are nothing of the
         * kind, and the operator stops reading the report.
         */
        $this->assertSame(1, $outcome->duplicates);
        $this->assertSame(0, $outcome->rejected);
        $this->assertFalse($outcome->isCommittable(), 'A batch of only duplicates has nothing to import.');
    }

    #[Test]
    public function the_same_row_twice_within_one_file_is_caught(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        // An export joined across two tables is the usual cause, and a duplicate check that only
        // looked at what was already imported would let the second copy through on the first run.
        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
            2 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
        ]);

        $outcome = $pipeline->validate($batch);

        $this->assertSame(1, $outcome->valid);
        $this->assertSame(1, $outcome->duplicates);
    }

    #[Test]
    public function a_clean_batch_commits_and_records_what_each_row_became(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1'],
            2 => ['first_name' => 'Teodoro', 'last_name' => 'Bautista', 'source_id' => 'L-2'],
        ]);

        $this->assertTrue($pipeline->validate($batch)->isCommittable());

        $created = [];

        $outcome = $pipeline->commit($batch, static function (array $row) use (&$created): string {
            $id = 'entity-'.$row['source_id'];
            $created[] = $id;

            return $id;
        });

        $this->assertSame(2, $outcome->imported);
        $this->assertFalse($outcome->wasDryRun);

        /*
         * WHAT EACH ROW BECAME is recorded, which is what makes the rollback plan possible at all:
         * an import is undone by walking the batch rather than by guessing which records arrived
         * when.
         */
        $plan = $pipeline->rollbackPlan($batch);

        $this->assertSame(2, $plan['count']);
        $this->assertSame($created, $plan['entities']);
        // It reports rather than acts — by the time somebody wants this, a caseworker may have
        // edited one of the records.
        $this->assertStringContainsString('Review each', $plan['note']);
    }

    #[Test]
    public function an_unknown_target_is_refused_rather_than_guessed(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('spreadsheet-of-something', 'Unknown export', [
            1 => ['anything' => 'at all'],
        ]);

        // There is deliberately no fallback that "does its best". A best-effort import into a
        // resident registry is what this whole pipeline exists to prevent.
        $this->expectExceptionMessage('No import mapper is registered');
        $pipeline->validate($batch);
    }

    #[Test]
    public function the_source_row_is_kept_verbatim_as_evidence(): void
    {
        $pipeline = new ImportPipeline([new FakeResidentMapper]);

        $batch = $pipeline->stage('resident', 'Legacy export, 2019', [
            1 => ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'source_id' => 'L-1', 'odd_legacy_column' => 'kept'],
        ]);

        $row = DB::table('import_rows')->first();

        /*
         * INCLUDING THE COLUMNS NOBODY MAPPED. "How did this record get here" is asked when the
         * record turns out to be wrong, and the answer has to include what the file actually said
         * — not the subset somebody thought was interesting at the time.
         */
        $this->assertStringContainsString('odd_legacy_column', (string) $row->source_payload);
        $this->assertSame('Legacy export, 2019', (string) DB::table('import_batches')->value('source_label'));
        $this->assertSame($batch, (string) DB::table('import_batches')->value('uuid'));
    }
}

/**
 * A mapper for the tests, and the only implementation of `RowMapper` anywhere.
 *
 * `ImportPipeline` ships with none, because the master command says to build the framework and not
 * to write mappings against imaginary legacy columns (ADR 0040 §3). This one exists to exercise the
 * pipeline and describes a file that does not exist.
 */
final class FakeResidentMapper implements RowMapper
{
    public function target(): string
    {
        return 'resident';
    }

    public function validate(array $row): array
    {
        $reasons = [];

        // EVERY reason, not the first — see the interface.
        if (($row['first_name'] ?? '') === '') {
            $reasons[] = 'First name is missing.';
        }

        if (($row['last_name'] ?? '') === '') {
            $reasons[] = 'Last name is missing.';
        }

        return $reasons;
    }

    public function importKey(array $row): ?string
    {
        $source = (string) ($row['source_id'] ?? '');

        return $source === '' ? null : hash('sha256', 'resident:'.$source);
    }
}
