<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAB 18 — a removed route is a reviewed line in a diff, never a discovery.
 *
 * *"Record the deployment order. The API deploys before the console when the console needs a new
 * endpoint; the console deploys before the API when the API removes one. Write down which, per
 * release."*
 *
 * ## Why a snapshot rather than a note
 *
 * The order per release is not a judgement somebody makes; it is a fact about the diff. What makes
 * it hard is that **one of the two directions is invisible**. Adding an endpoint is deliberate —
 * whoever wrote the console route knows the API needs to ship first. Removing one is not: a
 * controller method deleted during a tidy-up, a route file reorganised, a resource collapsed into
 * another — none of those feel like a breaking change to the person making them, and the console
 * that still calls the path finds out in production.
 *
 * So the published surface is committed as `docs/api/routes.published.json`. Removing a route now
 * requires editing that file in the same change, which turns an invisible removal into a line a
 * reviewer sees and a release note can be written from.
 *
 * ## What this does not claim
 *
 * That the route still *behaves* the same. `ConsumerContractTest` replays the console's recorded
 * expectations against the real router and covers the response shape. This covers existence, which
 * is the failure that produces a 404 on every screen rather than an empty list on one.
 */
final class PublishedRoutesAreStableTest extends TestCase
{
    private const SNAPSHOT = 'docs/api/routes.published.json';

    #[Test]
    public function no_published_route_disappears_without_the_snapshot_saying_so(): void
    {
        $published = $this->snapshot();
        $live = $this->liveRoutes();

        $removed = array_values(array_diff($published, $live));
        $added = array_values(array_diff($live, $published));

        $this->assertSame([], $removed, implode("\n", [
            count($removed).' published route(s) no longer exist:',
            '',
            ...array_map(static fn (string $r): string => "  {$r}", $removed),
            '',
            'A console still calling one of these gets a 404 on every screen that uses it.',
            'If the removal is intended, delete the line from '.self::SNAPSHOT.' in this same change —',
            'that is what puts CONSOLE-BEFORE-API into the release note instead of into an incident.',
            '',
            'Regenerate with: php artisan lguids:routes --write',
        ]));

        $this->assertSame([], $added, implode("\n", [
            count($added).' route(s) are served but not published:',
            '',
            ...array_map(static fn (string $r): string => "  {$r}", $added),
            '',
            'Add them with `php artisan lguids:routes --write`. A new endpoint means API-BEFORE-CONSOLE',
            'for this release, and the snapshot is where that is recorded.',
        ]));
    }

    /**
     * The snapshot has to be big, or an empty file would make the rule above vacuous.
     *
     * Every detector in this repository asserts its own reach: one that compares two empty lists
     * passes forever and reports a guarantee nobody has.
     */
    #[Test]
    public function the_snapshot_covers_the_whole_api(): void
    {
        $this->assertGreaterThan(
            250,
            count($this->snapshot()),
            'The published-routes snapshot is too small to be the whole API. A near-empty snapshot makes the removal check vacuous.'
        );
    }

    /** @return list<string> */
    private function snapshot(): array
    {
        $path = base_path(self::SNAPSHOT);

        $this->assertFileExists($path, self::SNAPSHOT.' is missing. Generate it with `php artisan lguids:routes --write`.');

        /** @var array{routes: list<string>} $data */
        $data = json_decode((string) file_get_contents($path), true);

        return $data['routes'] ?? [];
    }

    /** @return list<string> */
    private function liveRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $out[] = "{$method} /{$uri}";
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}
