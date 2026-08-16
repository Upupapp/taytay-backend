<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * This system does not track where anybody is (ADR 0022 §1).
 *
 * The master command forbids continuous location tracking, geofencing of clients and background
 * surveillance. Those are easy to refuse as *features* and easy to acquire as *columns* — a
 * `visit_location` added in good faith to help a supervisor plan routes is the first half of a
 * system that records where poor families live and who visited them when. Nobody sets out to
 * build that; it arrives one helpful field at a time.
 *
 * So the absence is enforced rather than intended, exactly as the admin console enforces it with
 * `tools/check-visits.mjs`. This is the server half, and it is the half that matters, because a
 * column that exists will eventually be filled by something.
 *
 * WHAT IS NOT FORBIDDEN: the address a visit was made to. The household registry already holds
 * it, the worker is going there anyway, and a visit record that cannot say where it happened is
 * useless. The line is between *an address the office already has* and *a position captured from
 * a device* — the first is a fact about a household, the second is a fact about a person's
 * movements.
 */
final class NoLocationTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Column-name fragments that would record a position or a movement.
     *
     * `address` is deliberately absent — see the class docblock for why it is a different thing.
     */
    private const FORBIDDEN_COLUMNS = [
        'latitude', 'longitude', 'lat_', '_lat', 'lng', 'geo', 'coordinate', 'coords',
        'gps', 'geofence', 'geopoint', 'location_point', 'checked_in', 'check_in',
        'arrived_at', 'departed_at', 'tracked', 'last_seen', 'position',
    ];

    /**
     * Tables that describe field work and are therefore where such a column would land.
     */
    private const FIELD_WORK_TABLES = [
        'field_visits',
        'field_visit_checklist_items',
        'visit_observations',
        'safeguarding_concerns',
        'case_notes',
    ];

    #[Test]
    public function no_field_work_table_records_a_position(): void
    {
        $offenders = [];

        foreach (self::FIELD_WORK_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] is missing — the scan is stale.");

            foreach (Schema::getColumnListing($table) as $column) {
                foreach (self::FORBIDDEN_COLUMNS as $fragment) {
                    if (str_contains(strtolower($column), $fragment)) {
                        $offenders[] = "{$table}.{$column}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These columns record where somebody was rather than what was found:',
            ...$offenders,
            '',
            'A visit records the address it was made to — which the household registry already',
            'holds — and nothing about a worker\'s movements. A location column added to help a',
            'supervisor plan routes is the first half of a system that records where poor',
            'families live and who visited them when (ADR 0022 §1).',
        ]));
    }

    #[Test]
    public function the_visit_contract_accepts_no_position(): void
    {
        $source = (string) file_get_contents(
            base_path('modules/Welfare/Http/Controllers/V1/FieldVisitController.php'),
        );

        $offenders = [];

        foreach (self::FORBIDDEN_COLUMNS as $fragment) {
            // Matches a validation key or an array key, which is how a field would enter the
            // contract. Prose in a docblock is excluded by requiring the quote.
            if (preg_match("/['\"][a-z_]*".preg_quote($fragment, '/')."[a-z_]*['\"]\s*=>/i", $source) === 1) {
                $offenders[] = $fragment;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'The field visit contract accepts a position-shaped field: '.implode(', ', $offenders),
            'There must be no field to send a coordinate to.',
        ]));
    }

    /**
     * The scan's negative fixture.
     *
     * A detector matching nothing would pass both assertions above while proving nothing — and
     * this one has an unusually quiet failure mode, because the tables it guards are supposed to
     * be clean.
     */
    #[Test]
    public function the_scan_would_actually_notice_a_position_column(): void
    {
        $matched = [];

        foreach (['visit_latitude', 'gps_fix', 'worker_checked_in_at', 'geofence_id'] as $hypothetical) {
            foreach (self::FORBIDDEN_COLUMNS as $fragment) {
                if (str_contains($hypothetical, $fragment)) {
                    $matched[] = $hypothetical;

                    break;
                }
            }
        }

        $this->assertSame(
            ['visit_latitude', 'gps_fix', 'worker_checked_in_at', 'geofence_id'],
            $matched,
            'The forbidden-fragment list no longer catches an obvious tracking column.',
        );

        // And that it does NOT catch the one thing that is legitimately allowed.
        foreach (self::FORBIDDEN_COLUMNS as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                'address_visited',
                'The scan rejects the address a visit was made to, which is a fact the household registry already holds.',
            );
        }
    }
}
