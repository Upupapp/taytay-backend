<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Modules\AccessControl\Contracts\Permission;
use Modules\AccessControl\Domain\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One vocabulary, in one shape.
 *
 * Before TAB 03 this enum carried three conventions at once — kebab
 * (`request.view-sensitive`), `snake_case` (`resident.link_review`), and three
 * dotted segments (`document.view.sensitive`). Nothing was wrong with any of
 * them individually; the problem was having all three, because a developer
 * reaching for a key then has to remember which spelling this particular grant
 * happened to get, and a client author has to look every one up.
 *
 * The canonical form is a single kebab-case `resource.action`, decided in the
 * console/backend reconciliation and recorded in ADR 0044.
 */
final class PermissionVocabularyTest extends TestCase
{
    #[Test]
    public function every_permission_is_a_kebab_case_resource_and_action(): void
    {
        $wrong = [];

        foreach (Permission::cases() as $permission) {
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*\.[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $permission->value) !== 1) {
                $wrong[] = $permission->value;
            }
        }

        sort($wrong);

        $this->assertSame([], $wrong, implode("\n", [
            'These permissions are not `resource.action` in kebab-case:',
            '',
            ...$wrong,
            '',
            'Three conventions in one enum is a model nobody can predict, and every client that',
            'consumes this API has to learn all three. Underscores and third segments both became',
            'kebab in TAB 03 (ADR 0044); a new key must not reintroduce either.',
        ]));
    }

    #[Test]
    public function no_two_permissions_differ_only_by_convention(): void
    {
        /*
         * Catches the specific way this vocabulary would fracture again: adding
         * `document.view_sensitive` beside `document.view-sensitive`, which reads
         * as a second grant and enforces a different one.
         */
        $normalised = [];

        foreach (Permission::cases() as $permission) {
            $key = str_replace(['_', '-', '.'], '', $permission->value);
            $normalised[$key][] = $permission->value;
        }

        $collisions = array_filter($normalised, static fn (array $group): bool => count($group) > 1);

        $this->assertSame([], $collisions, 'Two permissions differ only by punctuation: '.json_encode($collisions));
    }

    #[Test]
    public function every_role_grants_only_permissions_that_exist(): void
    {
        /*
         * A rename that missed a role definition would leave that role silently
         * short of a grant — the failure mode is a screen refusing somebody who
         * should be allowed, which reads as a bug in the screen.
         */
        $known = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        $unknown = [];

        foreach (Role::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $value = $permission instanceof Permission ? $permission->value : (string) $permission;

                if (! in_array($value, $known, true)) {
                    $unknown[] = $role->value.' → '.$value;
                }
            }
        }

        $this->assertSame([], $unknown, "A role grants a permission that is not in the catalog:\n".implode("\n", $unknown));
    }
}
