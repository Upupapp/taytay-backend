<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 18 — migrations are reversible and safe against a populated table.
 *
 * *"Verify every migration is reversible against real PostgreSQL, as CI already does, and that no
 * migration is destructive without an explicit, reviewed decision."*
 *
 * ## What runs here, and what cannot
 *
 * There is **no PostgreSQL on this machine and no container runtime**, so the executed half of this
 * runs on SQLite. That is a materially weaker proof and the weakness is specific rather than
 * general: Laravel's SQLite grammar **rebuilds a table** to drop a column, and SQLite does not
 * enforce a foreign key against a table being dropped. So the two rollback failures that actually
 * happen in production — dropping a table something still references, and dropping a column an
 * index or constraint depends on — are exactly the two SQLite cannot report.
 *
 * The static rules below therefore carry the weight the database cannot. They read every migration
 * and check the orderings PostgreSQL enforces and SQLite ignores. Running the real thing against a
 * real PostgreSQL stays on the master TODO as a TAB 18 item, because a check that reasons about SQL
 * is not the same as SQL that ran.
 */
final class MigrationSafetyTest extends TestCase
{
    // ── the executed half ────────────────────────────────────────────────────────────

    /**
     * Every migration rolls back, and the database comes back up afterwards.
     *
     * The second half matters more than the first. A `down()` that drops a table but leaves an
     * index, a sequence or a type behind rolls back "successfully" and then fails on the way up,
     * which is the worst possible moment: mid-incident, with the rollback already committed.
     */
    #[Test]
    public function the_whole_migration_set_rolls_back_and_migrates_up_again(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $before = $this->tableNames();
        $this->assertGreaterThan(50, count($before), 'The schema did not build; this test would then prove nothing.');

        $this->artisan('migrate:rollback', ['--step' => 100, '--force' => true])->assertSuccessful();

        $left = array_values(array_diff($this->tableNames(), ['migrations']));

        $this->assertSame(
            [],
            $left,
            'Rolling back left tables behind: '.implode(', ', $left)."\n"
            .'A rollback that half-succeeds is worse than one that refuses: the next migrate up fails '
            .'mid-incident, with the rollback already committed.'
        );

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame(
            $before,
            $this->tableNames(),
            'The schema after down-then-up differs from the schema before. A rollback is only rehearsed if the way back is rehearsed too.'
        );
    }

    // ── the static half: what SQLite structurally cannot report ──────────────────────

    /**
     * A foreign key must point at a table created in an **earlier or the same** migration.
     *
     * Rollback runs in reverse. A key pointing forward means the target is dropped first, while the
     * table holding the key still exists — PostgreSQL refuses the `DROP TABLE`, and SQLite does not
     * notice. This is the single most common way a migration set that "rolls back fine locally"
     * fails on the server.
     */
    #[Test]
    public function no_foreign_key_points_at_a_table_created_later(): void
    {
        $migrations = $this->migrations();
        $order = array_flip(array_keys($migrations));
        $createdIn = [];

        foreach ($migrations as $name => $source) {
            foreach ($this->matchAll('/Schema::create\(\'([a-z_]+)\'/', $this->body($source, 'up')) as $table) {
                $createdIn[$table] = $name;
            }
        }

        $problems = [];

        foreach ($this->foreignKeys($migrations) as [$migration, $table, $target]) {
            $targetMigration = $createdIn[$target] ?? null;

            if ($targetMigration !== null && $order[$targetMigration] > $order[$migration]) {
                $problems[] = "{$table} (in {$migration}) references {$target}, created later in {$targetMigration}";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems)
            ."\nRollback runs in reverse, so the target is dropped while the dependant still holds the key.");
    }

    /**
     * Inside one `down()`, a table must be dropped **before** the table it points at.
     *
     * Same failure, one migration smaller, and just as invisible on SQLite.
     */
    #[Test]
    public function each_rollback_drops_dependants_before_their_targets(): void
    {
        $migrations = $this->migrations();
        $problems = [];

        foreach ($migrations as $name => $source) {
            $drops = $this->matchAll('/dropIfExists\(\'([a-z_]+)\'\)/', $this->body($source, 'down'));
            $position = array_flip($drops);

            foreach ($this->foreignKeys([$name => $source]) as [, $table, $target]) {
                if (isset($position[$table], $position[$target]) && $position[$target] < $position[$table]) {
                    $problems[] = "{$name}: drops {$target} before {$table}, which references it";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($problems)), implode("\n", array_unique($problems)));
    }

    /**
     * Nothing destructive in an `up()` without saying so.
     *
     * Article 6: *"Never rename/drop a column in one deploy: add the new shape, backfill,
     * dual-write, cut over, then remove in a later change."* A `renameColumn` is the sharpest of
     * these — the old code is still running while the column it selects no longer exists, so the
     * outage begins the moment the migration commits and lasts until the deploy finishes.
     *
     * The escape hatch is a comment containing `EXPAND-MIGRATE-CONTRACT`, naming the earlier
     * migration that added the replacement. That makes the reviewed decision the command asks for
     * a thing a reviewer can see, rather than a thing they must reconstruct.
     */
    #[Test]
    public function no_migration_destroys_data_without_an_explicit_note(): void
    {
        $problems = [];

        foreach ($this->migrations() as $name => $source) {
            $up = $this->body($source, 'up');

            foreach (['dropColumn', 'dropIfExists', 'renameColumn', 'truncate', '->drop('] as $verb) {
                if (str_contains($up, $verb) && ! str_contains($source, 'EXPAND-MIGRATE-CONTRACT')) {
                    $problems[] = "{$name} calls {$verb} in up() with no EXPAND-MIGRATE-CONTRACT note.";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems)
            ."\nThe old code is still running when this commits. Add the new shape, backfill, cut over, remove later.");
    }

    /**
     * A column added to an existing table is nullable or has a default.
     *
     * *"Migrations are forward-only and must be safe to run against a populated table."* A
     * `NOT NULL` with no default is accepted by an empty test database and rejected by a populated
     * one — so this is a defect that only ever appears in production, on the deploy that matters.
     */
    #[Test]
    public function a_column_added_to_an_existing_table_is_nullable_or_defaulted(): void
    {
        $problems = [];
        $types = 'string|integer|bigInteger|unsignedBigInteger|uuid|boolean|text|timestamp|decimal|date|json|foreignId';

        foreach ($this->migrations() as $name => $source) {
            /*
             * Walked line by line rather than matched as a block.
             *
             * The first version used `/Schema::table\(.*?\n    \}\);/s`, which requires the closing
             * brace at four spaces. Every real block here closes at eight, inside `up()`, so the
             * pattern matched **nothing** and the rule reported a guarantee nobody had. A check that
             * cannot fail is worse than no check, and this is the third time that shape has appeared
             * in this repository.
             */
            $inside = false;
            $closing = '';

            foreach (explode("\n", $this->body($source, 'up')) as $line) {
                if (preg_match('/^(\s*)Schema::table\(/', $line, $m)) {
                    $inside = true;
                    $closing = $m[1].'});';

                    continue;
                }

                if ($inside && rtrim($line) === $closing) {
                    $inside = false;

                    continue;
                }

                if ($inside
                    && preg_match("/\\\$table->({$types})\(/", $line)
                    && ! str_contains($line, '->nullable()')
                    && ! str_contains($line, '->default(')) {
                    $problems[] = "{$name}: ".trim($line);
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems)
            ."\nAn empty test database accepts this and a populated one refuses it.");
    }

    /** Every migration can actually be reversed, rather than merely declaring a method. */
    #[Test]
    public function every_migration_has_a_down_that_does_something(): void
    {
        $problems = [];

        foreach ($this->migrations() as $name => $source) {
            $down = trim(preg_replace('!//[^\n]*!', '', $this->body($source, 'down')) ?? '');

            if ($down === '') {
                $problems[] = "{$name} has an empty down().";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems)
            ."\nAn irreversible migration makes rollback a code change, decided during an incident.");
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────

    /** @return array<string, string> filename => source, in migration order */
    private function migrations(): array
    {
        $out = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $out[basename($path)] = (string) file_get_contents($path);
        }

        ksort($out);

        return $out;
    }

    private function body(string $source, string $method): string
    {
        preg_match('/public function '.$method.'\(\): void\s*\{(.*?)\n    \}/s', $source, $m);

        return $m[1] ?? '';
    }

    /** @return list<string> */
    private function matchAll(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $m);

        return $m[1];
    }

    /**
     * @param  array<string, string>  $migrations
     * @return list<array{string, string, string}> migration, table holding the key, target table
     */
    private function foreignKeys(array $migrations): array
    {
        $out = [];

        foreach ($migrations as $name => $source) {
            $table = null;

            foreach (explode("\n", $this->body($source, 'up')) as $line) {
                if (preg_match('/Schema::(?:create|table)\(\'([a-z_]+)\'/', $line, $m)) {
                    $table = $m[1];
                }

                if ($table !== null && preg_match('/->constrained\(\'([a-z_]+)\'\)|->on\(\'([a-z_]+)\'\)/', $line, $m)) {
                    $out[] = [$name, $table, $m[1] !== '' ? $m[1] : $m[2]];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = array_map(
            static fn (array $t): string => $t['name'],
            Schema::getTables()
        );

        sort($names);

        return $names;
    }
}
