<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A TIMESTAMP THE DATABASE GENERATES MUST BE STORED AT THE PRECISION IT WAS GENERATED.
 *
 * PostgreSQL ROUNDS a timestamp to fit a column's precision — it does not truncate. So a
 * `CURRENT_TIMESTAMP` default written into a precision-0 column stores a value that never
 * happened, up to half a second in the FUTURE.
 *
 * That is wrong on its own terms for an append-only record under Article 5.4, and it is the
 * foundation of ADR 0049: `role_assignments.valid_from` was stamped ahead of the clock, the
 * authorization query filtered on `valid_from <= now()`, and a staff member held no permissions
 * at all until the wall clock caught up.
 *
 * The invariant is asserted on the SCHEMA rather than on the call sites that read these columns.
 * An audit of every `now()` comparison in the codebase found none over a database-defaulted column
 * today — but "no call site does the dangerous thing right now" describes the current code, and
 * the next one somebody writes would reintroduce ADR 0049 exactly. A guard on the column cannot be
 * walked past.
 *
 * MEANINGFUL ON POSTGRESQL ONLY. SQLite stores a timestamp as text exactly as given and has no
 * column precision to inspect, which is precisely why it never showed the defect.
 */
final class SchemaPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel's own failed-jobs table, which a framework upgrade may recreate at whatever
     * precision it likes. Excluded deliberately and by name: `failed_at` is operational, is never
     * compared against `now()`, and records nothing a citizen relies on.
     *
     * @var list<string>
     */
    private const NOT_OURS = ['failed_jobs'];

    #[Test]
    public function every_database_stamped_timestamp_keeps_sub_second_precision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'SQLite stores a timestamp as text and has no column precision to inspect — which '
                .'is exactly why it never reproduced the defect this guards. PostgreSQL only.'
            );
        }

        $stamped = DB::select(
            "select table_name, column_name, datetime_precision as precision
               from information_schema.columns
              where table_schema = current_schema()
                and column_default like '%CURRENT_TIMESTAMP%'
              order by table_name, column_name"
        );

        $this->assertNotEmpty($stamped, implode("\n", [
            'No database-stamped timestamp columns were found at all.',
            '',
            'This test then proves nothing, which is worse than failing. Either the default is no',
            'longer spelled the way the query above matches, or the migrations did not run.',
        ]));

        $rounded = [];

        foreach ($stamped as $column) {
            if (in_array($column->table_name, self::NOT_OURS, true)) {
                continue;
            }

            if ((int) $column->precision === 0) {
                $rounded[] = "{$column->table_name}.{$column->column_name}";
            }
        }

        $this->assertSame([], $rounded, implode("\n", [
            'These columns are written by the database and stored as whole seconds: '
                .implode(', ', $rounded),
            '',
            'PostgreSQL ROUNDS to fit a precision rather than truncating, so each can hold an',
            'instant up to half a second in the future — a moment that had not happened when the',
            'row was written. Give the column sub-second precision in a migration.',
            '',
            'This is the shape of ADR 0049: a value stamped ahead of the clock, read back by a',
            'query filtering on `<= now()`, and a staff member refused a permission they held.',
        ]));
    }
}
