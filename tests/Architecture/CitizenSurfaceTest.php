<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use Modules\Shared\Support\CitizenSurface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every route in this API is classified, and a citizen route is scanned (ADR 0032 §1).
 *
 * THE ACCEPTANCE CRITERION OF TAB 27 IS "internal admin-only fields are absent BY CONSTRUCTION",
 * and the words that matter are the last two. A test that scans the endpoints it happens to know
 * about proves nothing about the endpoint somebody adds next week — this project has been bitten
 * by exactly that twice (`ResidentMergeCoverageTest`, `feedback: a test can only find what it
 * renders`).
 *
 * So the mechanism is completeness, not coverage:
 *
 *  1. every registered route must appear in one of two declared lists, or the build fails;
 *  2. a route declared **citizen** is subject to the leak scan in `CitizenLeakScanTest`;
 *  3. a route declared **staff** is asserted to actually refuse a resident.
 *
 * Adding an endpoint without classifying it is not possible. Classifying it as citizen enrols it
 * in the scan. Classifying it as staff asserts a refusal. There is no third option and no way to
 * abstain.
 */
final class CitizenSurfaceTest extends TestCase
{
    #[Test]
    public function every_registered_route_is_classified_as_citizen_or_staff(): void
    {
        $declared = array_merge(
            CitizenSurface::citizenRouteNames(),
            CitizenSurface::staffRouteNamesOutsideAdminPrefix(),
        );

        $unclassified = [];

        foreach ($this->apiRoutes() as $name => $uri) {
            // The `admin/` prefix is a routing convention, not authorization (Article 3), but it
            // is an unambiguous *declaration of audience* — so it classifies without a list entry.
            if (str_contains($name, '.admin.')) {
                continue;
            }

            if (! in_array($name, $declared, true)) {
                $unclassified[] = $name.'  ('.$uri.')';
            }
        }

        sort($unclassified);

        $this->assertSame([], $unclassified, implode("\n", [
            'These routes belong to no declared audience:',
            '',
            ...$unclassified,
            '',
            'Add each to Modules\Shared\Support\CitizenSurface — `citizenRouteNames()` if a resident',
            'may reach it, `staffRouteNamesOutsideAdminPrefix()` if not.',
            '',
            'This is not bookkeeping. A citizen route is enrolled in the leak scan by being declared',
            'one; an unclassified route is scanned by nothing, and the first field somebody adds to',
            'its projection reaches whoever can call it.',
        ]));
    }

    #[Test]
    public function every_declared_route_still_exists(): void
    {
        $registered = array_keys($this->apiRoutes());

        $stale = array_values(array_diff(
            array_merge(
                CitizenSurface::citizenRouteNames(),
                CitizenSurface::staffRouteNamesOutsideAdminPrefix(),
            ),
            $registered,
        ));

        /*
         * A stale entry is worse than a missing one. It makes the list look complete while the
         * route it names is gone — and if a route is later added back under that name, it is
         * silently pre-classified by a decision nobody is making now.
         */
        $this->assertSame([], $stale, 'These are declared but no longer registered: '.implode(', ', $stale));
    }

    #[Test]
    public function the_forbidden_field_list_is_not_empty_and_is_unique(): void
    {
        $fields = CitizenSurface::fieldsForbiddenToCitizens();

        // A scan against an empty list passes everything. If somebody empties this, the build
        // says so rather than going quietly green.
        $this->assertNotEmpty($fields);
        $this->assertSame($fields, array_values(array_unique($fields)));
    }

    /**
     * @return array<string, string>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            $name = $route->getName();

            /*
             * An unnamed route cannot be classified, so it fails here rather than slipping past
             * the classification test by having nothing to compare.
             */
            $this->assertNotNull(
                $name,
                sprintf('Route [%s %s] has no name and cannot be classified.', $route->methods()[0], $uri),
            );

            $routes[$name] = $uri;
        }

        return $routes;
    }
}
