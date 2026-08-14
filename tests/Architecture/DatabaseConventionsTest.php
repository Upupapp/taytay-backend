<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enforces ADR 0008 on every migration.
 *
 * Schema conventions decay quietly: one migration uses a naive timestamp, the next copies
 * it, and two TABs later the rule exists only in a document nobody opens. These assertions
 * make each deviation a build failure at the moment it is written.
 *
 * They read migration source rather than a live database so they run on any connection and
 * cost nothing.
 */
final class DatabaseConventionsTest extends TestCase
{
    /**
     * Migrations that predate ADR 0008 — Laravel's own scaffolding and Sanctum's published
     * table. Grandfathered rather than rewritten: reformatting a framework migration to
     * satisfy our conventions would break the framework's expectations about those tables
     * and buy nothing.
     */
    private const PRE_CONVENTION = [
        '0001_01_01_000000_create_users_table.php',
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
        '2026_08_13_165512_create_personal_access_tokens_table.php',
    ];

    /**
     * The JSON allow-list required by ADR 0008 §13.
     *
     * Every entry is one of the three permitted uses — an external payload kept verbatim,
     * a cached HTTP response for idempotent replay, or schemaless annotation that is never
     * queried. Adding a row here is a decision; the point of the list is that it cannot be
     * done absent-mindedly.
     *
     * @var array<string, string> column => justification
     */
    private const JSON_ALLOW_LIST = [
        'response_body' => 'idempotency_keys: a cached HTTP response replayed verbatim; opaque, never filtered, joined or authorized on',
    ];

    #[Test]
    public function application_state_never_lives_in_a_json_column(): void
    {
        $offenders = [];

        foreach (self::conventionMigrations() as $file => $source) {
            preg_match_all('/->(?:json|jsonb)\(\s*[\'"](\w+)[\'"]/', $source, $matches);

            foreach ($matches[1] as $column) {
                if (! array_key_exists($column, self::JSON_ALLOW_LIST)) {
                    $offenders[] = "{$file}: `{$column}`";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "JSON columns must be on the ADR 0008 §13 allow-list with a justification.\n"
                .'A status, a relationship or an amount inside JSON cannot be constrained, '
                ."indexed or migrated:\n".implode("\n", $offenders)
        );
    }

    #[Test]
    public function every_migration_can_be_rolled_back(): void
    {
        $missing = [];

        foreach (self::allMigrations() as $file => $source) {
            if (! str_contains($source, 'public function down(')) {
                $missing[] = $file;
            }
        }

        // A migration whose rollback was never written is a rollback that does not exist,
        // and local/staging re-runs from empty are routine (ADR 0008 §14).
        $this->assertSame([], $missing, "Migrations without down():\n".implode("\n", $missing));
    }

    #[Test]
    public function datetime_columns_carry_a_time_zone(): void
    {
        $offenders = [];

        foreach (self::conventionMigrations() as $file => $source) {
            // `timestamp(` / `timestamps(` / `softDeletes(` without the Tz suffix store a
            // naive local time — ambiguous the first time a server moves region.
            if (preg_match('/->(timestamp|timestamps|softDeletes|dateTime)\(/', $source, $m) === 1) {
                $offenders[] = "{$file}: ->{$m[1]}() — use the Tz variant";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    #[Test]
    public function every_table_exposes_a_uuid_rather_than_its_primary_key(): void
    {
        $missing = [];

        foreach (self::conventionMigrations() as $file => $source) {
            if (str_contains($source, 'Schema::create') && ! str_contains($source, '$table->uuid()')) {
                $missing[] = $file;
            }
        }

        // Sequential integers leak volume and let a caller walk the table by incrementing
        // (ADR 0008 §1, conventions §6).
        $this->assertSame([], $missing, "Tables without a public uuid:\n".implode("\n", $missing));
    }

    #[Test]
    public function no_migration_declares_a_cross_module_foreign_key(): void
    {
        $offenders = [];

        // Columns that reference another module's table by identifier. A `constrained()`
        // on any of them would weld two modules together at the schema level and make the
        // boundary in ADR 0001 unenforceable (CLAUDE.md Article 2.2).
        $crossModule = ['subject_id', 'actor_subject_id', 'granted_by', 'resident_id', 'account_id', 'recipient_subject_id'];

        foreach (self::conventionMigrations() as $file => $source) {
            foreach ($crossModule as $column) {
                if (preg_match('/[\'"]'.$column.'[\'"]\s*\)?\s*->[^;]*constrained\(/', $source) === 1) {
                    $offenders[] = "{$file}: `{$column}` is constrained";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    #[Test]
    public function append_only_tables_have_no_mutation_columns(): void
    {
        $source = self::allMigrations()['2026_08_14_000300_create_audit_entries_table.php'] ?? '';

        $this->assertNotSame('', $source, 'The audit entries migration is missing.');

        // The absence of a mutation column is the append-only guarantee: there is nowhere
        // to record a change because a change must not happen (ADR 0008 §8).
        //
        // Matched against schema *calls* rather than the file text, so the migration can
        // still explain itself in prose without tripping its own rule.
        $this->assertDoesNotMatchRegularExpression('/\$table->timestampsTz\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$table->\w+\(\s*[\'"]updated_at[\'"]/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$table->\w*[sS]oftDeletes\w*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$table->\w+\(\s*[\'"]deleted_at[\'"]/', $source);
        $this->assertStringContainsString("timestampTz('created_at')", $source);
    }

    #[Test]
    public function relationship_tables_declare_a_unique_key(): void
    {
        // The duplicate-relationship guard is the single most common integrity defect in
        // systems like this one: "assign role" run twice must not produce two rows.
        $source = self::allMigrations()['2026_08_14_000200_create_role_assignments_table.php'] ?? '';

        $this->assertStringContainsString(
            "unique(['subject_id', 'role']",
            $source,
            'role_assignments must carry a unique key over the relationship pair.'
        );

        // …and the nullable scope column must stay OUT of that key, because PostgreSQL
        // treats NULLs as distinct and would happily allow unlimited duplicates.
        $this->assertStringNotContainsString(
            "unique(['subject_id', 'role', 'barangay_id']",
            $source,
            'A nullable column inside a unique key defeats the constraint on PostgreSQL.'
        );
    }

    /**
     * Migrations subject to ADR 0008 — everything except the grandfathered scaffolding.
     *
     * @return array<string, string>
     */
    private static function conventionMigrations(): array
    {
        return array_diff_key(self::allMigrations(), array_flip(self::PRE_CONVENTION));
    }

    /**
     * @return array<string, string> filename => source
     */
    private static function allMigrations(): array
    {
        $directory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $migrations = [];

        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*.php') as $path) {
            if (is_string($path)) {
                $migrations[basename($path)] = (string) file_get_contents($path);
            }
        }

        self::assertNotEmpty($migrations, 'No migrations found.');

        return $migrations;
    }
}
