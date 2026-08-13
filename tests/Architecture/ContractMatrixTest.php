<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keeps the TAB 02 contract documents honest.
 *
 * A contract matrix is worth exactly as much as its agreement with the code. Left
 * unchecked it becomes archaeology: endpoints appear that nobody documented, documented
 * endpoints quietly never get built, and a screen loses its contract row without anyone
 * noticing. These assertions make each of those a build failure.
 *
 * They check *correspondence*, not prose — the documents stay editable.
 */
final class ContractMatrixTest extends TestCase
{
    /**
     * Every routed admin screen in the Angular console, from its app.routes.ts at audit
     * time. Each must appear in the endpoint matrix, or be listed as having no backend
     * contract with a reason.
     */
    private const ADMIN_SCREENS = [
        '/sign-in',
        '/dashboard',
        '/residents',
        '/assistance-requests',
        '/programs',
        '/disbursements',
        '/referrals',
        '/reports',
        '/administration/staff',
        '/administration/audit',
        '/administration/settings',
        '/forbidden',
    ];

    /**
     * Fields the visibility matrix must name as internal. Losing one of these from the
     * document is how an internal field ends up in a citizen payload.
     */
    private const INTERNAL_FIELDS = [
        'decision_remarks',
        'assigned_to',
        'instrument_reference',
        'released_by',
        'funding_source',
        'reviewed_by',
        'actor_name',
        'created_by',
    ];

    #[Test]
    public function every_implemented_row_resolves_to_a_registered_route(): void
    {
        $registered = self::registeredApiRoutes();
        $unresolved = [];

        foreach (self::matrixRows() as $row) {
            if (! str_contains($row, '`implemented`')) {
                continue;
            }

            foreach (self::endpointsIn($row) as $endpoint) {
                if (! in_array($endpoint, $registered, true)) {
                    $unresolved[] = $endpoint;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unresolved)),
            "The matrix claims these are implemented, but no route is registered:\n"
                .implode("\n", array_unique($unresolved))
        );
    }

    #[Test]
    public function every_registered_route_appears_in_the_matrix(): void
    {
        $matrix = self::matrixContents();
        $undocumented = [];

        foreach (self::registeredApiRoutes() as $endpoint) {
            if (! str_contains($matrix, $endpoint)) {
                $undocumented[] = $endpoint;
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            "These routes exist but no contract row documents them:\n".implode("\n", $undocumented)
        );
    }

    #[Test]
    public function every_admin_screen_has_a_contract_or_a_stated_exception(): void
    {
        $matrix = self::matrixContents();
        $missing = [];

        foreach (self::ADMIN_SCREENS as $screen) {
            if (! str_contains($matrix, $screen)) {
                $missing[] = $screen;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Admin screens with neither a backend contract nor a documented exception:\n"
                .implode("\n", $missing)
        );
    }

    #[Test]
    public function mock_only_exceptions_are_justified_in_place(): void
    {
        $matrix = self::matrixContents();

        $this->assertStringContainsString('`mock-only`', $matrix);

        // Each exception is a decision not to build something a client asked for. The
        // reason has to travel with it, or the next reader reinstates it.
        foreach (['signInAs', 'NotificationRepository.create'] as $exception) {
            $this->assertStringContainsString(
                $exception,
                $matrix,
                "`{$exception}` is a deliberate non-endpoint and must stay documented as one."
            );
        }
    }

    #[Test]
    public function the_visibility_matrix_names_every_internal_field(): void
    {
        $visibility = self::read('docs/contracts/client-visibility-matrix.md');
        $missing = [];

        foreach (self::INTERNAL_FIELDS as $field) {
            if (! str_contains($visibility, $field)) {
                $missing[] = $field;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Internal fields missing from the exclusion list:\n".implode("\n", $missing)
        );
    }

    #[Test]
    public function no_endpoint_is_specific_to_one_client(): void
    {
        // The duplicate-logic failure this TAB exists to prevent would show up first as a
        // path segment: /mobile/..., /web/..., /android/... . One service, many
        // deliveries (CLAUDE.md Article 3.1) means the path cannot name its client.
        $offenders = [];

        foreach (self::matrixRows() as $row) {
            foreach (self::endpointsIn($row) as $endpoint) {
                foreach (['/mobile', '/web', '/android', '/ios', '/desktop'] as $segment) {
                    if (str_contains($endpoint, $segment)) {
                        $offenders[] = $endpoint;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Client-specific endpoints defeat the shared-service rule:\n".implode("\n", $offenders)
        );
    }

    #[Test]
    public function the_two_citizen_channels_share_one_contract_explicitly(): void
    {
        $this->assertStringContainsString(
            'no row in this matrix that exists for one and not the other',
            self::matrixContents(),
            'The shared-citizen-contract rule must be stated, not merely implied.'
        );

        // The visibility matrix must resolve both citizen channels to a single column;
        // a per-channel column is where a "mobile-only field" would eventually appear.
        $this->assertStringContainsString(
            '`citizen-web`, `citizen-mobile`',
            self::read('docs/contracts/client-visibility-matrix.md'),
            'Citizen web and mobile must share one visibility column.'
        );
    }

    #[Test]
    public function the_gap_list_records_every_blocking_gap_with_an_owner(): void
    {
        $gaps = self::read('docs/contracts/frontend-backend-gap-list.md');

        $this->assertStringContainsString('blocking', $gaps);

        // The blocking gaps found in the TAB 02 audit. Removing one from the document
        // should be a deliberate act with a resolution, not an edit.
        foreach (['G-01', 'G-02', 'G-03', 'G-05'] as $gap) {
            $this->assertStringContainsString($gap, $gaps, "Gap {$gap} must remain recorded.");
        }
    }

    /**
     * @return list<string> e.g. "GET /api/v1/health"
     */
    private static function registeredApiRoutes(): array
    {
        $endpoints = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $endpoints[] = $method.' /'.$uri;
            }
        }

        return array_values(array_unique($endpoints));
    }

    /**
     * @return list<string>
     */
    private static function endpointsIn(string $row): array
    {
        preg_match_all('#\b(GET|POST|PATCH|PUT|DELETE) (/api/v1[a-z0-9/_{}-]*)#i', $row, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): string => strtoupper($match[1]).' '.rtrim($match[2], '/'),
            $matches,
        );
    }

    /**
     * @return list<string>
     */
    private static function matrixRows(): array
    {
        return array_values(array_filter(
            explode("\n", self::matrixContents()),
            static fn (string $line): bool => str_starts_with(trim($line), '|'),
        ));
    }

    private static function matrixContents(): string
    {
        return self::read('docs/contracts/frontend-endpoint-matrix.md');
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        self::assertFileExists($path, "Required contract document is missing: {$relative}");

        return (string) file_get_contents($path);
    }
}
