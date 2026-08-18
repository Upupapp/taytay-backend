<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Events\Infrastructure\Eloquent\Event;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Http\RateLimits;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 30, as tests.
 *
 *  1. **A citizen cannot access another citizen's object by ID substitution.**
 *  2. **A lower-privileged staff user cannot call admin-only endpoints directly.**
 *  3. **A client cannot mass-assign protected status, role or approval fields.**
 *
 * These are OWASP API1 (broken object-level authorization), API5 (broken function-level
 * authorization) and API6 (mass assignment). Each is tested by *attempting the attack* rather than
 * by asserting that a check exists — a check that exists and is wrong passes the second kind of
 * test and fails the first.
 */
final class ApiSecurityTest extends KycTestCase
{
    use RefreshDatabase;

    // ── API1: object-level authorization ─────────────────────────────────────────────

    #[Test]
    public function a_citizen_cannot_reach_another_citizens_object_by_substituting_its_id(): void
    {
        [$owner] = $this->activeCitizenWithResident();
        Sanctum::actingAs($owner);

        [$owned, $lists] = $this->recordsOwnedBy();

        // Every identifier the owner's records carry. If one of these turns up in a stranger's
        // response, something leaked whatever the envelope looks like.
        $ownerIdentifiers = array_map(
            static fn (string $url): string => (string) (explode('?', basename($url))[0] ?? ''),
            array_values($owned),
        );

        [$attacker] = $this->activeCitizenWithResident();
        Sanctum::actingAs($attacker);

        $findings = [];

        /*
         * A LIST AND A DETAIL FAIL DIFFERENTLY, and conflating them would make this test wrong in
         * a way that merely looks strict. A list scoped to the caller correctly answers `200` with
         * nothing in it — there is no "somebody else's list" to refuse. Only a detail lookup by
         * identifier can be substituted, and only that must answer `404`.
         *
         * The list is checked by SEARCHING THE WHOLE BODY FOR THE OWNER'S IDENTIFIERS rather than
         * by asserting an empty `data` array. Envelopes differ across these endpoints — one
         * returns `data.drafts` — and a shape-specific assertion would pass on an endpoint whose
         * rows it never looked at, which is the failure mode this whole test exists to catch.
         */
        foreach ($lists as $label => $url) {
            $body = $this->getJson($url);

            if ($body->status() !== 200) {
                $findings[] = sprintf('%s → %s (a scoped list must answer 200, got %d)', $label, $url, $body->status());

                continue;
            }

            foreach ($ownerIdentifiers as $identifier) {
                if ($identifier !== '' && str_contains($body->content(), $identifier)) {
                    $findings[] = sprintf('%s → %s leaked the owner identifier %s', $label, $url, $identifier);
                }
            }
        }

        foreach ($owned as $label => $url) {
            $status = $this->getJson($url)->status();

            /*
             * `404`, not `403`. Answering `403` confirms the id names a real record, which is most
             * of what an enumeration attempt wants: an attacker walking identifiers learns which
             * ones exist without ever being shown one (OWASP API1).
             *
             * `401` would mean the route is unauthenticated, which is a different defect and also
             * fails here.
             */
            if ($status !== 404) {
                $findings[] = sprintf('%s → %s (expected 404, got %d)', $label, $url, $status);
            }
        }

        $this->assertSame([], $findings, implode("\n", [
            'These records were reachable by a resident who does not own them:',
            '',
            ...$findings,
            '',
            'Scope the lookup AT THE QUERY to the resident resolved from the token, so the row is',
            'absent rather than refused — a check that runs after the lookup is one the next',
            'endpoint can omit.',
        ]));
    }

    #[Test]
    public function the_substitution_scan_actually_found_records_to_try(): void
    {
        /*
         * THE SCAN ABOVE PASSES TRIVIALLY IF THE FIXTURE CREATED NOTHING. A list of zero URLs
         * yields zero findings and a green test — the exact "detector that reaches nothing" shape
         * this project has been bitten by before.
         */
        [$owner] = $this->activeCitizenWithResident();
        Sanctum::actingAs($owner);

        [$owned, $lists] = $this->recordsOwnedBy();

        $this->assertGreaterThanOrEqual(3, count($owned), 'The fixture built nothing to substitute.');
        $this->assertNotEmpty($lists);

        // And every URL must actually resolve FOR ITS OWNER, or a 404 for the attacker proves
        // nothing except that the route is broken.
        foreach ($owned + $lists as $label => $url) {
            $this->assertSame(200, $this->getJson($url)->status(), "[{$label}] does not resolve for its own owner.");
        }
    }

    #[Test]
    public function a_barangay_scoped_clerk_cannot_read_a_resident_from_another_barangay(): void
    {
        $mine = $this->barangayId();
        $theirs = $this->otherBarangayId();

        $local = $this->existingResident(['barangay_id' => $mine]);
        $elsewhere = $this->existingResident(['barangay_id' => $theirs]);

        /*
         * A GENUINELY BARANGAY-SCOPED CLERK. Scope comes from the role ASSIGNMENT's `scope_type`,
         * not from the role itself (ADR 0012) — so a fixture that just assigned `lgu_staff` would
         * get municipality-wide reach and this test would prove nothing.
         */
        $clerk = Account::factory()->staff()->create();

        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => (string) $clerk->uuid,
            'role' => 'lgu_staff',
            'scope_type' => 'own-barangay',
            'barangay_id' => $mine,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($clerk);

        // Their own barangay resolves.
        $this->getJson("/api/v1/admin/residents/{$local->uuid}")->assertOk();

        /*
         * CROSS-BARANGAY ID SUBSTITUTION. The clerk holds `resident.view` — the function-level
         * check passes — and is refused anyway, because scope is a second, object-level decision
         * (ADR 0012). A system that only checked the permission would hand a Dolores clerk every
         * resident in the municipality.
         */
        $this->getJson("/api/v1/admin/residents/{$elsewhere->uuid}")->assertNotFound();
    }

    // ── API5: function-level authorization ───────────────────────────────────────────

    #[Test]
    public function front_line_staff_cannot_call_the_endpoints_reserved_above_them(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $findings = [];

        foreach ($this->endpointsAboveFrontLineStaff() as $label => [$method, $url, $payload]) {
            $status = $this->json($method, $url, $payload)->status();

            /*
             * `403` here, not `404` — and the difference from API1 is deliberate. These are
             * *functions*, not records: refusing a staff member the ability to approve a case
             * discloses nothing, because the existence of an approval endpoint is not a secret.
             * Answering `404` would instead make a permissions problem look like a broken client.
             *
             * A `422` is also acceptable ONLY if it never reaches the action — but none of these
             * should validate before authorizing, so the authorization must answer first.
             */
            if ($status !== 403) {
                $findings[] = sprintf('%s → %s %s (expected 403, got %d)', $label, $method, $url, $status);
            }
        }

        $this->assertSame([], $findings, implode("\n", [
            'Front-line staff reached an endpoint reserved above them:',
            '',
            ...$findings,
            '',
            'Authorize BEFORE validating, so a caller who may not perform an action never learns',
            'whether their payload was well formed (OWASP API5).',
        ]));
    }

    #[Test]
    public function a_disbursing_officer_cannot_approve_and_an_approver_cannot_release(): void
    {
        /*
         * SEPARATION OF DUTIES, tested as an attack rather than as a config assertion. No single
         * non-administrator role may both approve a case and release its money (ADR 0023 §3), and
         * the way that fails in practice is somebody quietly adding one permission to a role.
         */
        $case = $this->openCase();

        /*
         * The case is `submitted`, so `intake` is a LEGAL next state and `approved` is not. Using
         * a legal one matters: an illegal transition is refused by the state machine with a `409`
         * before authorization is consulted, so a test that used one would pass whether or not the
         * permission split existed.
         */
        Sanctum::actingAs($this->reviewer('disbursing_officer'));
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/transitions", ['to' => 'intake-review'])->assertForbidden();

        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson('/api/v1/admin/releases/00000000-0000-0000-0000-000000000000/confirmation')
            ->assertForbidden();
    }

    #[Test]
    public function the_audit_trail_is_not_readable_by_the_office_it_audits(): void
    {
        // The auditee is not the auditor (ADR 0034 §7). Tested here as well as in the audit suite,
        // because it is a function-level authorization property and this is where somebody
        // reviewing OWASP API5 will look.
        foreach (['lgu_admin', 'lgu_staff', 'security_officer', 'disbursing_officer'] as $role) {
            Sanctum::actingAs($this->reviewer($role));
            $this->getJson('/api/v1/admin/audit-entries')->assertForbidden();
        }

        Sanctum::actingAs($this->reviewer('data_protection_officer'));
        $this->getJson('/api/v1/admin/audit-entries')->assertOk();
    }

    // ── API6: mass assignment ────────────────────────────────────────────────────────

    #[Test]
    public function a_citizen_cannot_smuggle_a_protected_field_into_a_correction_request(): void
    {
        [$citizen, $resident] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * REFUSED EXPLICITLY, NOT SILENTLY DROPPED — and the difference matters.
         *
         * Laravel validates the keys it was told about and ignores the rest, so a contract that
         * simply omitted `verification_tier` would accept this payload, arrive as an empty change
         * set, and answer `201` — teaching a client that self-promotion to a verified identity had
         * worked. `MyProfileController` closes that with a closure rule that names the unknown
         * fields back, so deny-by-default is visible to the caller (Article 3.4).
         *
         * A verification tier is the decision that unlocks a digital ID (ADR 0011). This is the
         * single most valuable field in the system to be able to set on yourself.
         */
        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => [
                'first_name' => 'Maria',
                'verification_tier' => 'verified',
                'status' => 'active',
                'philsys_number' => '1234-5678-9012',
            ],
            'note' => 'Spelling.',
        ])->assertStatus(422);

        /*
         * Nothing was recorded at all — the request was refused whole rather than accepted with
         * the smuggled fields dropped, which is what makes the refusal visible to the caller.
         */
        $this->assertDatabaseCount('resident_correction_requests', 0);
        $this->assertNotNull($resident);
    }

    #[Test]
    public function a_citizen_cannot_mass_assign_the_decision_on_their_own_correction_request(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['first_name' => 'Maria'],
            'note' => 'Spelling.',
            // The fields that decide the request, sent by the person making it.
            'status' => 'approved',
            'reviewed_by' => (string) $citizen->uuid,
            'reviewed_at' => now()->toIso8601ZuluString(),
        ])->assertCreated();

        $correction = DB::table('resident_correction_requests')->latest('id')->first();

        /*
         * Here the extra keys ARE silently dropped, and that is correct: they are not fields a
         * resident may request a correction to, they are fields of the *request itself*. The
         * control is that `$request->validate()` returns only validated keys, so an unlisted one
         * does not exist as far as the service is concerned — it is not ignored downstream, it
         * never arrives.
         */
        $this->assertSame('pending', (string) $correction->status);
        $this->assertNull($correction->reviewed_by);
    }

    #[Test]
    public function a_citizen_cannot_mass_assign_an_approval_field_on_an_event_registration(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishedEvent();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/v1/events/{$event}/registration", [
            // Everything an attacker would try to set on the way in.
            'status' => 'registered',
            'attendance' => 'attended',
            'staff_notes' => 'VIP — let through.',
            'promoted_at' => now()->toIso8601ZuluString(),
        ])->assertCreated();

        $row = DB::table('event_registrations')->latest('id')->first();

        /*
         * The event has a capacity of one and it was already full, so the honest outcome is
         * `waitlisted`. A client that could send `status` would have written itself a seat.
         */
        $this->assertSame('waitlisted', (string) $row->status);
        $this->assertSame('not-checked-in', (string) $row->attendance);
        $this->assertNull($row->staff_notes);
        $this->assertNull($row->promoted_at);
    }

    #[Test]
    public function staff_cannot_mass_assign_a_field_reserved_to_the_state_machine(): void
    {
        $case = $this->openCase();

        Sanctum::actingAs($this->staff());

        /*
         * Setting a priority must not be a way to move a case. The lifecycle has its own endpoint,
         * its own permission per target state, and its own recorded transition (ADR 0016 §6) — and
         * an endpoint that accepted `status` alongside `priority` would be a second, unaudited
         * path through the state machine.
         */
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/priority", [
            'priority' => 'high',
            'status' => 'approved',
            'approved_by' => 'somebody',
            'closed_at' => now()->toIso8601ZuluString(),
        ]);

        $row = DB::table('welfare_cases')->where('uuid', $case)->first();

        $this->assertNotSame('approved', (string) $row->status);
        $this->assertNull($row->closed_at);
    }

    // ── the surrounding controls ─────────────────────────────────────────────────────

    #[Test]
    public function every_response_carries_the_security_headers(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        foreach (['/api/v1/health', '/api/v1/me', '/api/v1/events'] as $url) {
            $response = $this->getJson($url);

            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $url);
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $url);
            $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'), $url);
            // The same refusal as ADR 0022 §1, stated to the browser.
            $this->assertStringContainsString('geolocation=()', (string) $response->headers->get('Permissions-Policy'), $url);
        }
    }

    #[Test]
    public function an_error_response_carries_them_too(): void
    {
        /*
         * The response most worth hardening: an unauthenticated caller is the one whose browser
         * context is least trusted, and an error page is the classic place a header is forgotten
         * because the middleware sits inside the auth stack rather than around it.
         */
        $response = $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    #[Test]
    public function hsts_is_not_sent_until_the_lgu_turns_it_on(): void
    {
        /*
         * The one header that is hard to take back: a browser that has seen it refuses plain HTTP
         * for the whole max-age, so a premature one on a domain whose certificate is not yet right
         * locks people out with no server-side fix.
         */
        $this->assertNull($this->getJson('/api/v1/health')->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function an_error_body_carries_no_internals(): void
    {
        // Force a genuine failure through a malformed identifier rather than a mocked exception,
        // so this exercises the real renderer.
        $response = $this->getJson('/api/v1/events/'.str_repeat('x', 300));

        $content = $response->content();

        foreach (['SQLSTATE', 'vendor/', 'Stack trace', 'Modules\\', '.php:', 'PDOException'] as $leak) {
            $this->assertStringNotContainsString($leak, $content, "An error body leaked [{$leak}].");
        }

        // And it still carries the correlation id a citizen can quote (Article 4).
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function every_declared_rate_limiter_is_registered(): void
    {
        $limiter = app(RateLimiter::class);

        foreach (RateLimits::names() as $name) {
            /*
             * A `throttle:` middleware naming a limiter that was never registered does not fail —
             * Laravel treats the name as a bare "N attempts per minute" string and, when it is not
             * numeric, throws only at request time. So an unregistered limiter is an endpoint that
             * breaks in production and passes every test that never calls it.
             */
            $this->assertNotNull(
                $limiter->limiter($name),
                "Rate limiter [{$name}] is declared but not registered.",
            );
        }
    }

    #[Test]
    public function the_login_response_does_not_reveal_whether_an_account_exists(): void
    {
        $known = $this->citizen(['email' => 'known.resident@example.test']);

        $withKnown = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'known.resident@example.test',
            'password' => 'definitely-not-the-password',
        ]);

        $withUnknown = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'nobody.here@example.test',
            'password' => 'definitely-not-the-password',
        ]);

        /*
         * IDENTICAL STATUS AND IDENTICAL CODE. A difference between "no such account" and "wrong
         * password" turns the login endpoint into a directory of who is registered with this LGU
         * — and for a welfare system, being registered is itself a fact about somebody.
         */
        $this->assertSame($withUnknown->status(), $withKnown->status());
        $this->assertSame($withUnknown->json('error.code'), $withKnown->json('error.code'));
        $this->assertNotNull($known);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    /**
     * URLs naming records the CURRENT actor owns.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} detail lookups, scoped lists
     */
    private function recordsOwnedBy(): array
    {
        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help with hospital bills.',
            'consent_reference' => 'ack-security',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        $case = (string) DB::table('welfare_cases')->latest('id')->value('uuid');

        $this->postJson('/api/v1/me/profile/corrections', [
            'changes' => ['first_name' => 'Maria'],
            'note' => 'Spelling.',
        ])->assertCreated();

        $this->registerForAnEvent();

        $registration = (string) DB::table('event_registrations')->latest('id')->value('uuid');

        return [
            // Detail lookups: an identifier an attacker can substitute.
            [
                'welfare case' => '/api/v1/me/cases/'.$case,
                'case requirements' => '/api/v1/me/cases/'.$case.'/requirements',
                'event registration' => '/api/v1/me/event-registrations/'.$registration,
            ],
            // Scoped lists: correctly empty for a stranger rather than refused.
            [
                'assistance drafts' => '/api/v1/me/assistance/drafts',
                'correction requests' => '/api/v1/me/profile/corrections',
                'own registrations' => '/api/v1/me/event-registrations',
                'own cases' => '/api/v1/me/cases',
            ],
        ];
    }

    /**
     * Endpoints reserved above front-line staff.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function endpointsAboveFrontLineStaff(): array
    {
        return [
            'move a case through the lifecycle' => ['POST', '/api/v1/admin/assistance-requests/'.$this->openCase().'/transitions', ['to' => 'intake-review']],
            'export person-level data' => ['POST', '/api/v1/admin/exports', ['report' => 'release-manifest', 'format' => 'csv']],
            'provision staff' => ['POST', '/api/v1/staff', ['email' => 'new.clerk@example.test', 'display_name' => 'New Clerk']],
            'read the audit trail' => ['GET', '/api/v1/admin/audit-entries', []],
            'place a legal hold' => ['POST', '/api/v1/admin/privacy/legal-holds', [
                'entity_type' => 'Welfare.Case', 'reference' => 'X', 'reason' => 'Y',
            ]],
            'publish a newsfeed post' => ['POST', '/api/v1/admin/newsfeed/'.$this->draftPost().'/status', ['status' => 'published']],
        ];
    }

    /**
     * Registers the CURRENT actor for an event, so they own a registration to substitute.
     */
    private function registerForAnEvent(): void
    {
        $citizen = auth()->user();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        if ($citizen instanceof Account) {
            Sanctum::actingAs($citizen);
            $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();
        }
    }

    private function openCase(): string
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help.',
            'consent_reference' => 'ack-case',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit")->assertCreated();

        return (string) DB::table('welfare_cases')->latest('id')->value('uuid');
    }

    private function draftPost(): string
    {
        $current = auth()->user();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        if ($current instanceof Account) {
            Sanctum::actingAs($current);
        }

        return $post;
    }

    private function publishedEvent(): string
    {
        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
            'capacity' => 1,
            'waitlist_enabled' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        // Fill the single seat, so a client that could set `status` would be writing itself past
        // a full house rather than into an empty one.
        [$first] = $this->activeCitizenWithResident();
        $admin = auth()->user();
        Sanctum::actingAs($first);
        $this->postJson("/api/v1/events/{$event}/registration")->assertCreated();

        if ($admin instanceof Account) {
            Sanctum::actingAs($admin);
        }

        Event::query()->where('uuid', $event)->exists();

        return $event;
    }
}
