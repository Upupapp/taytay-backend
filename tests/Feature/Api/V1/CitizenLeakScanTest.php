<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Support\CitizenSurface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nothing internal reaches a resident (ADR 0032 §1, TAB 27 acceptance criterion 2).
 *
 * `CitizenSurfaceTest` guarantees that every route is classified. This is the other half: it
 * **calls** the readable citizen endpoints with a real resident behind a real token, against a
 * database deliberately seeded with the internal values that must not come back, and scans the
 * whole response tree for forbidden field names.
 *
 * THE FIXTURE IS THE TEST. A scan against clean data proves nothing — every projection looks safe
 * when there is nothing to leak. So every record this citizen owns is written with a staff note, a
 * moderation reason, an assigning officer and a caseworker attached, and *then* read back. If a
 * projection widens, the value is already sitting there waiting to be returned.
 *
 * AND THE SCANNER IS ITSELF TESTED, positively and negatively. A leak detector that cannot detect
 * a leak is worse than none, because it is believed — `feedback: detectors need positive AND
 * negative fixtures`.
 */
final class CitizenLeakScanTest extends KycTestCase
{
    use RefreshDatabase;

    // ── the scanner is tested before it is trusted ───────────────────────────────────

    #[Test]
    public function the_scanner_finds_a_planted_leak(): void
    {
        // THE NEGATIVE FIXTURE. If this ever passes cleanly, every other assertion in this file
        // is decoration.
        $leaks = $this->forbiddenKeysIn([
            'id' => 'abc',
            'case' => ['status' => 'approved', 'assigned_to' => 'officer-7'],
        ]);

        $this->assertSame(['case.assigned_to'], $leaks);
    }

    #[Test]
    public function the_scanner_finds_a_leak_nested_inside_a_list(): void
    {
        /*
         * The shape that matters most: a paginated `data` array. A scanner that only looked at
         * top-level keys would pass every list endpoint in this API.
         */
        $leaks = $this->forbiddenKeysIn([
            'data' => [
                ['id' => 'one'],
                ['id' => 'two', 'notes' => ['staff_notes' => 'internal remark']],
            ],
        ]);

        $this->assertSame(['data.1.notes.staff_notes'], $leaks);
    }

    #[Test]
    public function the_scanner_passes_a_clean_payload(): void
    {
        // The positive fixture. A scanner that flagged everything would also "never miss a leak".
        $this->assertSame([], $this->forbiddenKeysIn([
            'data' => [['id' => 'one', 'status' => 'approved', 'reference' => 'EVT-1']],
        ]));
    }

    // ── the real surface ─────────────────────────────────────────────────────────────

    #[Test]
    public function no_readable_citizen_endpoint_returns_an_internal_field(): void
    {
        [$citizen, $case, $event] = $this->citizenWithEverything();

        Sanctum::actingAs($citizen);

        $findings = [];

        foreach ($this->readableCitizenUrls($case, $event) as $url) {
            $response = $this->getJson($url);

            // A `404` or `409` is a legitimate answer for a fixture that does not reach every
            // state; a `500` is not, and would silently hide the endpoint from this scan.
            $this->assertLessThan(500, $response->status(), "[{$url}] failed with {$response->status()}");

            $exempt = CitizenSurface::fieldExemptions()[ltrim($url, '/')] ?? [];

            foreach ($this->forbiddenKeysIn((array) $response->json()) as $path) {
                // Exemptions are per URL and stated with a reason in `CitizenSurface`. There is no
                // global off-switch, because one would defeat the list.
                if (in_array((string) preg_replace('#^.*\.#', '', $path), $exempt, true)) {
                    continue;
                }

                $findings[] = $url.' → '.$path;
            }
        }

        $this->assertSame([], $findings, implode("\n", [
            'These citizen endpoints returned a field a resident must never see:',
            '',
            ...$findings,
            '',
            'The fix is a narrower projection, not a filter over a wide one: a citizen projection',
            'must be its own method, so the next field somebody adds to the staff view does not',
            'arrive here as well (ADR 0028 §1).',
        ]));
    }

    #[Test]
    public function the_scan_actually_covers_the_declared_citizen_reads(): void
    {
        /*
         * THE TEST ABOVE CAN ONLY FIND WHAT IT CALLS, so this one checks what it calls.
         *
         * Every declared citizen route that is a `GET` must appear in the scanned set — otherwise
         * a readable endpoint could be declared, never exercised, and the scan would be green
         * about it forever.
         */
        [, $case, $event] = $this->citizenWithEverything();

        $scanned = array_map(
            static fn (string $url): string => (string) preg_replace('#\?.*$#', '', $url),
            $this->readableCitizenUrls($case, $event),
        );

        $missing = [];

        foreach (CitizenSurface::citizenRouteNames() as $name) {
            $route = collect(app('router')->getRoutes())->first(
                static fn ($r): bool => $r->getName() === $name && in_array('GET', $r->methods(), true),
            );

            if ($route === null) {
                // A write. Covered by its own module's feature tests, not by a read scan.
                continue;
            }

            /*
             * Built segment by segment. Quoting the whole URI first and swapping placeholders
             * afterwards does not work — `preg_quote` escapes the braces, so the swap leaves a
             * stray backslash and the pattern silently matches nothing. That is a detector that
             * reports full coverage while calling nothing, which is the exact failure this test
             * exists to prevent.
             */
            $pattern = '#^/'.implode('/', array_map(
                static fn (string $segment): string => str_starts_with($segment, '{')
                    ? '[^/]+'
                    : preg_quote($segment, '#'),
                explode('/', $route->uri()),
            )).'$#';

            $matched = false;

            foreach ($scanned as $url) {
                if (preg_match($pattern, $url) === 1) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $missing[] = $name.'  ('.$route->uri().')';
            }
        }

        sort($missing);

        $this->assertSame([], $missing, implode("\n", [
            'These citizen GET routes are declared but never called by the leak scan:',
            '',
            ...$missing,
            '',
            'Add a URL for each to readableCitizenUrls(). An endpoint the scan does not call is an',
            'endpoint the scan is green about for no reason.',
        ]));
    }

    #[Test]
    public function web_and_mobile_receive_the_same_business_payload(): void
    {
        [$citizen, $case, $event] = $this->citizenWithEverything();

        Sanctum::actingAs($citizen);

        foreach ($this->readableCitizenUrls($case, $event) as $url) {
            $web = $this->getJson($url, ['X-Client-Channel' => 'citizen-web']);
            $mobile = $this->getJson($url, ['X-Client-Channel' => 'citizen-mobile']);

            /*
             * The one endpoint whose JOB is to describe the client, so of course it differs: it
             * echoes the channel back and states that channel's default page size. Exempted here
             * rather than made channel-blind, because a client needs to see how its header was
             * parsed (ADR 0032 §2).
             */
            if ($url === '/api/v1/app/bootstrap') {
                continue;
            }

            $this->assertSame($web->status(), $mobile->status(), "[{$url}] answered two channels differently");

            /*
             * `data` compared, `meta` not. The channel legitimately picks a default page size
             * (ADR 0002), so pagination metadata may differ — the *business outcome* may not.
             *
             * This is the acceptance criterion "citizen web and mobile see consistent business
             * outcomes", and it is asserted across the whole readable surface rather than on one
             * chosen endpoint, because the one that drifts will be the one nobody chose.
             */
            $this->assertEquals(
                $web->json('data'),
                $mobile->json('data'),
                "[{$url}] returned different data to web and mobile",
            );
        }
    }

    // ── the scanner ──────────────────────────────────────────────────────────────────

    /**
     * Every forbidden key in the tree, as dotted paths.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function forbiddenKeysIn(array $payload, string $prefix = ''): array
    {
        $forbidden = CitizenSurface::fieldsForbiddenToCitizens();
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($key) && in_array($key, $forbidden, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                $found = array_merge($found, $this->forbiddenKeysIn($value, $path));
            }
        }

        return $found;
    }

    // ── the fixture ──────────────────────────────────────────────────────────────────

    /**
     * A resident who owns one of everything, with internal values attached to all of it.
     *
     * @return array{0: Account, 1: string, 2: string}
     */
    private function citizenWithEverything(): array
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
            'capacity' => 20,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        [$citizen, $resident] = $this->activeCitizenWithResident();

        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help with hospital bills.',
            'consent_reference' => 'ack-leak-scan',
        ])->assertCreated()->json('data.id');

        /*
         * A REAL SUBMITTED CASE, not a draft. The whole point of the poisoning below is that this
         * resident owns a welfare case with a caseworker attached — a scan run against a resident
         * who never filed anything would find nothing because there is nothing to find.
         */
        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        $case = (string) DB::table('welfare_cases')->value('uuid');

        $this->postJson("/api/v1/events/{$event}/registration");
        $this->postJson("/api/v1/newsfeed/{$post}/comments", ['body' => 'Thank you for the notice.']);

        /*
         * NOW POISON EVERY TABLE THE CITIZEN CAN REACH.
         *
         * This is the part that makes the scan mean something. Written directly rather than
         * through an API, because the whole point is that these values exist in rows the resident
         * owns — a projection that widened would return them, and a scan against clean data would
         * not notice.
         */
        DB::table('event_registrations')->update(['staff_notes' => 'INTERNAL: difficult at the door.']);
        /*
         * A REAL UUID, because these are uuid columns.
         *
         * This wrote `'officer-7'`, which SQLite stores as text and PostgreSQL refuses outright —
         * so the whole scan errored on the database this system actually runs on. What the fixture
         * needs is an internal value present in the row; which staff member it names is immaterial.
         */
        $officer = '01a04d5a-0000-7000-8000-00000000f007';

        DB::table('newsfeed_comments')->update([
            'moderation_reason' => 'INTERNAL: borderline.',
            'moderated_by' => $officer,
        ]);

        DB::table('welfare_cases')->update(['assigned_to' => $officer]);

        /*
         * THE POISON MUST HAVE LANDED.
         *
         * An `UPDATE` that matched no rows returns 0 and throws nothing, so a fixture that
         * silently wrote to an empty table would leave this whole scan green against clean data —
         * passing for the reason it exists to rule out. Each of these is the row a projection
         * would have to widen to leak, so if one is missing the scan is not testing what it
         * claims.
         */
        $this->assertSame(1, DB::table('welfare_cases')->where('assigned_to', $officer)->count());
        $this->assertSame(1, DB::table('event_registrations')->whereNotNull('staff_notes')->count());
        $this->assertSame(1, DB::table('newsfeed_comments')->whereNotNull('moderation_reason')->count());

        Sanctum::actingAs($citizen);

        return [$citizen, (string) $case, (string) $event];
    }

    /**
     * Every citizen URL the scan reads.
     *
     * Kept honest by `the_scan_actually_covers_the_declared_citizen_reads`, which fails if a
     * declared citizen `GET` route is missing from this list.
     *
     * @return list<string>
     */
    private function readableCitizenUrls(string $case, string $event): array
    {
        $slug = (string) Event::query()->where('uuid', $event)->value('slug');
        $post = (string) DB::table('newsfeed_posts')->value('uuid');
        $registration = (string) DB::table('event_registrations')->value('uuid');
        /*
         * A grant that does not exist is still worth calling: the endpoint answers `404`, and a
         * `404` body is a citizen response like any other — it carries a request id, and it must
         * not carry anything else.
         */
        // The grant's identifier is `uuid`; there has never been a `token` column. SQLite let the
        // read pass on an empty table, PostgreSQL rejects the column name outright.
        $document = (string) (DB::table('file_access_grants')->value('uuid') ?? 'none');

        return [
            '/api/v1/health',
            '/api/v1/app/bootstrap',
            // Read before a resident has an account, so it is scanned like every
            // other citizen read rather than trusted because it looks harmless.
            '/api/v1/barangays',
            '/api/v1/me',
            '/api/v1/me/profile',
            '/api/v1/me/household',
            '/api/v1/me/kyc',
            // F28. The scan reads it because an applicant's own document list is exactly
            // the kind of payload a reviewer field could leak into.
            '/api/v1/me/kyc/documents',
            '/api/v1/me/credential',
            '/api/v1/me/sessions',
            '/api/v1/me/devices',
            '/api/v1/me/profile/corrections',
            '/api/v1/me/cases',
            '/api/v1/me/cases/'.$case,
            '/api/v1/me/cases/'.$case.'/requirements',
            '/api/v1/me/assistance-history',
            '/api/v1/me/assistance/drafts',
            '/api/v1/me/referrals',
            '/api/v1/me/notifications',
            '/api/v1/me/notification-preferences',
            '/api/v1/me/event-registrations',
            '/api/v1/me/event-registrations/'.$registration,
            '/api/v1/services',
            '/api/v1/programs',
            '/api/v1/programs/AICS',
            '/api/v1/newsfeed',
            '/api/v1/newsfeed/'.$post,
            '/api/v1/newsfeed/'.$post.'/comments',
            '/api/v1/events',
            '/api/v1/events/'.$slug,
            '/api/v1/documents/'.$document,
            '/api/v1/privacy/notice',
            '/api/v1/me/privacy/consents',
            '/api/v1/me/event-registrations/'.$registration,
        ];
    }
}
