<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every module that stores a `resident_id` must be repointed by a merge (ADR 0019 §4).
 *
 * WHY THIS TEST EXISTS. ADR 0013 §6 established that a merge repoints every consumer of
 * `resident_id`. When it was written the consumers were Credential, which listens for
 * `ResidentMerged`, and Identity, which the merge calls directly. Welfare arrived three TABs
 * later storing `resident_id` on four tables, and **nothing connected it** — so a merge left
 * welfare cases pointing at a soft-deleted resident. The applicant's own `me/cases` went empty
 * while staff carried on working the file, and each side was internally consistent and certain
 * it was right.
 *
 * Nothing failed. No exception, no constraint, no red test — because every existing test asserted
 * its own module, and a domain event with one listener is indistinguishable from a domain event
 * with a missing listener.
 *
 * A checklist entry would not have caught it either; the module was added correctly by every rule
 * that existed. So the rule is stated here, where forgetting it is loud.
 *
 * TWO MECHANISMS ARE VALID, and which one a module uses is decided by the dependency direction,
 * not by preference (boundary map §2):
 *
 *  * ResidentProfile **calls the module directly** where it already depends on it — Identity,
 *    through `AccountLinkService::reassign()`;
 *  * the module **listens for `ResidentMerged`** where the call would have to run back up the
 *    graph — Credential and Welfare, which both depend on ResidentProfile.
 *
 * This asserts a mechanism exists, not that it is correct. What it moves is proven by the feature
 * tests; this only ensures that a module cannot be added with no answer at all.
 *
 * It reads the **live schema** rather than the source, because the columns that matter are the
 * ones the database actually has — a model with `$guarded = ['id']` names none of its columns,
 * which is exactly how this went unseen the first time.
 */
final class ResidentMergeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ResidentProfile publishes the event and repoints its own tables inside the merge
     * transaction. It is not a subscriber to itself.
     */
    private const PUBLISHER = 'ResidentProfile';

    /**
     * Tables that record a merge rather than being subject to one.
     *
     * `resident_merges` IS the merge; repointing its `resident_id` would rewrite the history of
     * which two records were joined. `resident_duplicate_pairs` and `resident_match_candidates`
     * are matching working notes about the records as they were.
     */
    private const HISTORICAL = [
        'resident_merges',
        'resident_duplicate_pairs',
        'resident_match_candidates',
    ];

    #[Test]
    public function every_module_holding_a_resident_id_is_repointed_by_a_merge(): void
    {
        $covered = array_merge($this->modulesListeningForMerge(), $this->modulesTheMergeCallsDirectly());
        $owners = $this->modulesOwningAResidentIdColumn();

        $this->assertNotEmpty($owners, 'No table carries resident_id — the scan is broken, not the code.');

        foreach ($owners as $module => $tables) {
            $this->assertContains(
                $module,
                $covered,
                sprintf(
                    "Module [%s] stores resident_id on [%s] and no merge path touches it.\n".
                    'After a merge those rows point at a soft-deleted resident: staff keep seeing the '.
                    "record and the person it belongs to stops seeing it, with nothing failing.\n".
                    'Either register a listener in %sServiceProvider::boot(), or — only if '.
                    'ResidentProfile already depends on [%s] — call it from the merge (ADR 0019 §4).',
                    $module,
                    implode(', ', $tables),
                    $module,
                    $module,
                ),
            );
        }
    }

    /**
     * The negative fixture. A scan that answered "covered" for everything would pass the test
     * above for every module at once, including the one that was actually broken.
     */
    #[Test]
    public function the_direct_call_scan_does_not_answer_for_modules_it_cannot_reach(): void
    {
        $direct = $this->modulesTheMergeCallsDirectly();

        $this->assertContains('Identity', $direct, 'The scan no longer finds AccountLinkService::reassign().');

        // Welfare and Credential both depend on ResidentProfile, so the merge cannot call them
        // and must reach them through the event. If they appear here, the scan is over-matching.
        $this->assertNotContains('Welfare', $direct);
        $this->assertNotContains('Credential', $direct);
    }

    #[Test]
    public function the_merge_event_has_at_least_one_listener(): void
    {
        // Guards the guard. If the event were renamed and the listeners left behind, the test
        // above would pass vacuously for every module at once.
        $this->assertNotEmpty(
            Event::getListeners(ResidentMerged::class),
            'ResidentMerged has no listeners at all — every consumer would be stranded by a merge.',
        );
    }

    /**
     * @return array<string, list<string>> module => tables
     */
    private function modulesOwningAResidentIdColumn(): array
    {
        $owners = [];

        foreach ($this->tableToModuleMap() as $table => $module) {
            if ($module === self::PUBLISHER || in_array($table, self::HISTORICAL, true)) {
                continue;
            }

            if (Schema::hasColumn($table, 'resident_id')) {
                $owners[$module][] = $table;
            }
        }

        return $owners;
    }

    /**
     * Maps each Eloquent table name to the module that declares it.
     *
     * @return array<string, string>
     */
    private function tableToModuleMap(): array
    {
        $map = [];

        foreach (glob(base_path('modules/*/Infrastructure/Eloquent/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/protected \\\$table = '([a-z0-9_]+)'/", $source, $matches) !== 1) {
                continue;
            }

            $module = basename(dirname($file, 3));
            $map[$matches[1]] = $module;
        }

        return $map;
    }

    /**
     * Modules the merge reaches by a direct downward call.
     *
     * Follows `ResidentMergeService` and the ResidentProfile application services it collaborates
     * with — one hop, which is how `AccountLinkService::reassign()` is found. Deeper than that
     * and the answer stops being evidence of anything.
     *
     * @return list<string>
     */
    private function modulesTheMergeCallsDirectly(): array
    {
        $entry = base_path('modules/ResidentProfile/Application/ResidentMergeService.php');
        $merge = (string) file_get_contents($entry);
        $sources = [$merge];

        /*
         * Collaborators are found by name, not by `use` statement: they share the merge service's
         * namespace, so there is no import to read — which is how `AccountLinkService::reassign()`
         * hid from the first version of this scan and produced a false accusation against Identity.
         */
        foreach (glob(base_path('modules/ResidentProfile/Application/*.php')) ?: [] as $file) {
            $class = basename($file, '.php');

            if ($file !== $entry && str_contains($merge, $class)) {
                $sources[] = (string) file_get_contents($file);
            }
        }

        $modules = [];

        foreach ($sources as $source) {
            preg_match_all('/use Modules\\\\([A-Za-z]+)\\\\/', $source, $matches);
            $modules = array_merge($modules, $matches[1]);
        }

        return array_values(array_unique($modules));
    }

    /**
     * @return list<string>
     */
    private function modulesListeningForMerge(): array
    {
        $modules = [];

        foreach (Event::getRawListeners()[ResidentMerged::class] ?? [] as $listener) {
            if (is_string($listener) && preg_match('/^Modules\\\\([A-Za-z]+)\\\\/', $listener, $matches) === 1) {
                $modules[] = $matches[1];
            }
        }

        return array_values(array_unique($modules));
    }
}
