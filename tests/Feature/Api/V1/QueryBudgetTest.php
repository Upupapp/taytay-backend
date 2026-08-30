<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Application\FileStore;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\FileClassification;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramEligibilityCriterion;
use Modules\Shared\Application\ActorContext;
use Modules\Welfare\Application\CaseRequirementService;
use Modules\Welfare\Domain\RequirementApplicability;
use Modules\Welfare\Domain\RequirementObligation;
use Modules\Welfare\Infrastructure\Eloquent\CaseRequirement;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A list endpoint's query count must not grow with its row count (ADR 0042).
 *
 * **THIS IS AN N+1 REGRESSION TEST, AND IT ASSERTS THE SHAPE RATHER THAN A NUMBER.**
 *
 * Asserting "the feed costs 7 queries" would fail every time somebody legitimately adds a lookup,
 * and would be relaxed by whoever hit it until it asserted nothing. What matters is not how many
 * queries a page costs — it is whether that number **changes when the page gets longer**. One is a
 * budget somebody argues about; the other is a defect.
 *
 * ── WHAT IS DELIBERATELY NOT MEASURED, AND WHY ──────────────────────────────────────
 *
 * Each of these was investigated and ruled out. They are listed so the next person counting
 * coverage does not spend the afternoon re-deriving them:
 *
 *  * **`/services` and `/admin/services`** — config-backed.
 *    `ConfigServiceCatalogRepository::all()` reads `service_catalog.services` from config, so
 *    the rows never touch the database and no fixture can grow them. `ListServicesQuery` slices
 *    in memory and says so. There is no per-row query to catch.
 *  * **`/me/assistance-history`** — an `ApiResponse::item` envelope, not a page, and
 *    `grantedCases()` caps at `limit(200)` with a pure-column projection.
 *  * **`/admin/audit-entries`** — pure columns, and the endpoint WRITES an audit entry on every
 *    read. The table a budget would count grows by one per measurement, so the fixture would
 *    perturb its own subject for no per-row work.
 *  * **`/me/privacy/consents`** — bounded by a closed vocabulary. `consentPurposes()` derives
 *    the list from `privacy.legal_bases` where the basis is `consent`, and there are FOUR. A
 *    citizen cannot hold a fifth, so the page is capped at four rows.
 *  * **`/admin/newsfeed-metrics`** — an `ApiResponse::item` of five fixed `count()` queries. A
 *    constant, not a page; it has no rows to grow.
 *  * **`/admin/privacy/classifications` and `/admin/privacy/retention`** — config registers.
 *    `RetentionPolicy::classifications()` and `schedule()` read `privacy.classifications` and
 *    `privacy.retention.*` from config, returned in an `item` envelope. No rows, no growth.
 *  * **`/admin/reports`** — the catalogue iterates `ReportCatalog::cases()`, a PHP enum. Bounded
 *    by code rather than data.
 *  * **`/me/kyc/documents`** — an `item` envelope listing one case's documents, bounded by the
 *    document set that case requires.
 *  * **`/admin/residents/{id}/kinship-history`** — already batched, explicitly: it resolves every
 *    family the resident has ever been in with one `whereIn` and says so in a comment.
 *  * **`/admin/residents/{id}/families`** — reads like a certain N+1: `familiesOf()` has no
 *    `withCount` and the projection falls back to a per-row count. It is unreachable. A resident
 *    may hold only ONE open family membership (a second is refused 409), so the page is capped
 *    at one row. **A domain invariant can make an apparent N+1 impossible — check what the
 *    endpoint can contain before writing the test.**
 *
 * ── WHERE COVERAGE ACTUALLY STANDS ──────────────────────────────────────────────────
 *
 * **26 of the 54 endpoints that call `ApiResponse::page` have a budget.**
 *
 * **COUNT THE `measure()` CALLS, NOT THE URLS IN THIS FILE.** An earlier tally said 29 and was
 * wrong: it was built by grepping every `/api/v1/...` string here, which counts FIXTURE TARGETS
 * as though they were measured. Posts are created through `POST /admin/newsfeed`, residents
 * through `POST /admin/residents` — so both appeared "covered" while neither was measured at
 * all. `/admin/newsfeed` was the expensive one: its `withCount(['reactions', 'comments'])` can be
 * deleted and every budget stays green, which is how that gap was found. It is measured now, and
 * that deletion fails it 7 → 21.
 *
 * The honest recipe:
 *
 *     grep -oE "measure\(\s*['\"][^'\"]+" tests/Feature/Api/V1/QueryBudgetTest.php
 *
 * Three genuinely unmeasured endpoints remain — `/admin/residents`, `/admin/events` and
 * `/admin/households` — all high-traffic staff lists, all previously miscounted as covered. They fall into four kinds, and the kinds matter
 * more than the count:
 *
 *  * **No rows to grow** — config registers (`/services`, `/admin/services`, the two
 *    `/admin/privacy` registers), a PHP enum (`/admin/reports`), a single record plus config
 *    (`/privacy/notice`), or a handful of fixed `count()` queries (`/admin/newsfeed-metrics`).
 *  * **Bounded by the domain** — a closed vocabulary (`/me/privacy/consents`, four purposes),
 *    a one-open-membership invariant (`/admin/residents/{id}/families`), or a `limit(200)` cap
 *    (`/me/assistance-history`).
 *
 *    **"Scoped to one subject" is NOT a reason, and it was the reason first written here.** One
 *    event's history can have hundreds of rows; a per-row query in it is an N+1 like any other.
 *    The single-subject pages — one event's history, one post's history, one family, one
 *    programme's templates, a resident's duplicate findings, an event's registration summary —
 *    are excluded because each was re-read and none does per-row work. The findings page in fact
 *    batches explicitly, with `Resident::whereIn(...)->pluck('uuid', 'id')` for the whole set,
 *    and the summary is a fixed set of aggregates for one event.
 *  * **Already batched, verifiably** — `/admin/residents/{id}/kinship-history` resolves every
 *    family in one `whereIn`; `/me/event-registrations` resolves its events the same way;
 *    `/admin/work/team` is a single grouped query and `/admin/work/alerts` returns at most two
 *    rows.
 *  * ~~**Self-perturbing** — the audit endpoints~~ **WRONG, and now measured.** Reading the trail
 *    does write to it (`audit.searched`), so I recorded the audit endpoints as unmeasurable: the
 *    fixture would grow the table it counts. It perturbs the COUNT, not the SLOPE. The extra row
 *    is one per request whether the page holds two entries or twelve, so it lands identically in
 *    both samples and cancels out of the growth. `assertBudget` compares two measurements; a
 *    constant added to both is invisible to it. What that reasoning would have broken is an
 *    assertion on an absolute number — which this harness deliberately never makes, and which is
 *    the first thing its own docblock says.
 *
 * **So "54 paginating endpoints, 30 measured" reads worse than the truth.** The honest figure is
 * that the measurable surface is close to fully covered, and the remaining names are there
 * because they call `ApiResponse::page`, not because anything is unguarded. A count of endpoints
 * is not a measure of risk; what each one can CONTAIN is.
 *
 * The three fixed here were all real, and all found by measuring rather than reading:
 *
 *  * the staff registrant list resolved a resident name **per row** — 11 queries for one
 *    registrant, 18 for eight. At a feeding programme with two hundred, two hundred round trips;
 *  * the citizen newsfeed loaded a post's media **per post** — 7 for one, 14 for eight;
 *  * a post's public image URLs cost **three queries per post** — 10 for one, 25 for six. A feed
 *    page is twenty-five posts, so seventy-five avoidable round trips on the endpoint every
 *    resident opens first, over the connection least able to afford them.
 *
 * The third was the one worth finding, and it is the one a reading of the code would have missed:
 * the per-post cost was hidden two calls deep, inside a method whose name promised a lookup rather
 * than a query.
 */
final class QueryBudgetTest extends KycTestCase
{
    use RefreshDatabase;

    /**
     * How much a page is allowed to grow between the small and large samples.
     *
     * ZERO. Not "a few" — a per-row query either exists or it does not, and a tolerance is a place
     * for one to hide.
     */
    private const ALLOWED_GROWTH = 0;

    /** The entity whose history the for-entity budget renders. Fixed, so the arm can count it. */
    private const BUDGET_AUDIT_ENTITY = '01a05100-0000-7000-8000-00000000beef';

    private ?object $applicant = null;

    private ?string $threadRoot = null;

    private ?Program $program = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('object-storage');
        Storage::fake('public-media');
        config()->set('files.disk', 'object-storage');
        config()->set('files.public_disk', 'public-media');
    }

    #[Test]
    public function the_staff_registrant_list_does_not_grow_with_registrants(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishedEvent();

        $url = "/api/v1/admin/events/{$event}/registrations";

        $small = $this->measure($url, fn () => $this->registerOneCitizen($event), 1);
        $large = $this->measure($url, fn () => $this->registerOneCitizen($event), 8);

        $this->assertBudget('admin registrant list', $small, $large);
    }

    /**
     * THE SAME LIST, WHEN A RESIDENT CANNOT BE RESOLVED — which the test above cannot see.
     *
     * `staffProjection()` took `array $names = []` and did `$names[$id] ?? summaryFor($id)`. An
     * empty default is indistinguishable from a supplied page that has no entry for this row, so
     * every unresolvable registrant cost a query **on top of** the batch: 12 for one, 17 for six,
     * against 11 flat when every resident resolves.
     *
     * That is the fallback ADR 0042 section 1 records as measuring worse than the N+1 it replaced,
     * still present in the endpoint that section fixed — invisible because the fixture always
     * produced residents that resolve.
     *
     * Residents become unresolvable through an operation this system performs deliberately:
     * duplicate merging.
     */
    #[Test]
    public function the_staff_registrant_list_does_not_grow_when_a_resident_cannot_be_resolved(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $event = $this->publishedEvent();

        $url = "/api/v1/admin/events/{$event}/registrations";
        $orphan = static fn () => DB::table('residents')->delete();

        $small = $this->measure($url, fn () => $this->registerOneCitizen($event), 1, beforeMeasuring: $orphan);
        $large = $this->measure($url, fn () => $this->registerOneCitizen($event), 6, beforeMeasuring: $orphan);

        $this->assertSame(0, DB::table('residents')->count(), 'The fixture left residents resolvable.');
        $this->assertFixtureProduced(6, DB::table('event_registrations')->count(), 'registrations to render');
        $this->assertBudget('registrant list, unresolvable residents', $small, $large);
    }

    #[Test]
    public function the_citizen_newsfeed_does_not_grow_with_posts(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $small = $this->measure('/api/v1/newsfeed', fn () => $this->publishPost(), 1, asCitizen: true);
        $large = $this->measure('/api/v1/newsfeed', fn () => $this->publishPost(), 8, asCitizen: true);

        $this->assertBudget('citizen newsfeed', $small, $large);
    }

    #[Test]
    public function the_citizen_newsfeed_does_not_grow_with_posts_that_have_images(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        /*
         * THE EXPENSIVE ONE. Posts WITH pictures, published so their public renditions actually
         * exist — a fixture whose images never decode would measure the empty path rather than the
         * one production takes.
         */
        $small = $this->measure('/api/v1/newsfeed', fn () => $this->publishPostWithImage(), 1, asCitizen: true);
        $large = $this->measure('/api/v1/newsfeed', fn () => $this->publishPostWithImage(), 6, asCitizen: true);

        /*
         * The fixture must have produced actual renditions. A stub image that passes the upload's
         * type check and then fails to decode derives nothing, and the lookup this test exists to
         * measure returns an empty answer without querying — the empty path, passing silently.
         */
        $this->assertFixtureProduced(6, DB::table('media_variants')->count(), 'derived public image renditions');
        $this->assertBudget('citizen newsfeed with images', $small, $large);
    }

    /**
     * WITH COVER IMAGES, and that is the whole point.
     *
     * This test passed at 4 → 4 for two rounds while the endpoint actually cost **7 queries for
     * one event and 22 for six**. The fixture published events with no cover, and
     * `publicMediaUrls(null)` returns without querying — so the gate measured the empty path and
     * reported the endpoint safe. A poster is the normal state of an LGU event.
     */
    #[Test]
    public function the_public_events_list_does_not_grow_with_events(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $small = $this->measure('/api/v1/events', fn () => $this->publishedEventWithCover(), 1, asCitizen: true);
        $large = $this->measure('/api/v1/events', fn () => $this->publishedEventWithCover(), 8, asCitizen: true);

        $this->assertFixtureProduced(8, DB::table('events')->whereNotNull('cover_file_id')->count(), 'events with a cover image');
        $this->assertBudget('public events list', $small, $large);
    }

    /**
     * WITH REPLIES. A top-level comment resolves no parent, so a thread of them measures nothing —
     * the endpoint cost one query per REPLY, 6 → 11.
     */
    #[Test]
    public function a_comment_thread_does_not_grow_with_replies(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->publishPost();
        $this->threadRoot = $this->addComment($post, null);

        $url = "/api/v1/newsfeed/{$post}/comments";

        $small = $this->measure($url, fn () => $this->addComment($post, $this->threadRoot), 1, asCitizen: true);
        $large = $this->measure($url, fn () => $this->addComment($post, $this->threadRoot), 6, asCitizen: true);

        $this->assertFixtureProduced(6, DB::table('newsfeed_comments')->whereNotNull('parent_id')->count(), 'comments with a parent');
        $this->assertBudget('comment thread', $small, $large);
    }

    /**
     * THE WORST ONE, AND THE ONE A FIXTURE ALMOST HID: 17 queries for one requirement, 77 for six.
     *
     * Twelve per row, because the projection resolved the same document four times — once for the
     * version, again through `isSatisfied()`, a third time through `isOutstanding()`, and a fourth
     * when `outstandingFor()` re-ran the whole list for the count — and each resolution cost three
     * queries.
     *
     * The first measurement of this endpoint said **flat**, because the fixture created
     * requirements with no document and every one of those lookups returns null without querying
     * when the document id is null. It measured the empty path. Hence `assertHasDocuments()`.
     */
    #[Test]
    public function the_staff_requirements_page_does_not_grow_with_requirements(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $case = $this->caseWithRequirements();

        $url = "/api/v1/admin/assistance-requests/{$case}/requirements";

        $small = $this->measure($url, fn () => $this->recordOneDocument($case), 1);
        $large = $this->measure($url, fn () => $this->recordOneDocument($case), 6);

        $this->assertFixtureProduced(6, DB::table('welfare_case_requirements')->whereNotNull('document_id')->count(), 'requirements with a document');
        $this->assertBudget('staff requirements page', $small, $large);
    }

    #[Test]
    public function a_citizens_own_requirements_page_does_not_grow_with_requirements(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $case = $this->caseWithRequirements();

        $url = "/api/v1/me/cases/{$case}/requirements";

        $small = $this->measure($url, fn () => $this->recordOneDocument($case), 1, asApplicant: true);
        $large = $this->measure($url, fn () => $this->recordOneDocument($case), 6, asApplicant: true);

        $this->assertFixtureProduced(6, DB::table('welfare_case_requirements')->whereNotNull('document_id')->count(), 'requirements with a document');
        $this->assertBudget('citizen requirements page', $small, $large);
    }

    /**
     * The KYC review queue counted each case's undecided candidates with its own `COUNT`.
     * Unconditional — the count runs whether or not a case has candidates — so it was 5 queries
     * for one case and 10 for six. Now a subquery on the page's own select.
     */
    #[Test]
    public function the_kyc_review_queue_does_not_grow_with_cases(): void
    {
        $small = $this->measure('/api/v1/admin/kyc-cases', fn () => $this->submittedCase(), 1, asReviewer: true);
        $large = $this->measure('/api/v1/admin/kyc-cases', fn () => $this->submittedCase(), 6, asReviewer: true);

        $this->assertBudget('kyc review queue', $small, $large);
    }

    /**
     * Each document request resolved its requirement's uuid with its own query.
     */
    #[Test]
    public function the_document_request_list_does_not_grow_with_requests(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $case = $this->caseWithRequirements();

        $url = "/api/v1/admin/assistance-requests/{$case}/document-requests";

        $small = $this->measure($url, fn () => $this->openDocumentRequest($case), 1);
        $large = $this->measure($url, fn () => $this->openDocumentRequest($case), 6);

        $this->assertBudget('document request list', $small, $large);
    }

    /**
     * The staff directory described each person's authority with its own two queries — the roles,
     * then the scope — 8 for one staff member and 18 for six.
     *
     * Found by grep rather than by the detector, which cannot follow a call through an interface:
     * `RoleAssignmentRepository` is a contract, and the detector reads the named class for a
     * method body an interface does not have. This codebase inverts dependencies deliberately
     * (Article 2.2), so that blind spot covers exactly the calls the constitution requires.
     */
    #[Test]
    public function the_staff_directory_does_not_grow_with_staff(): void
    {
        $small = $this->measure('/api/v1/staff', fn () => $this->reviewer('lgu_admin'), 1, asReviewer: true);
        $large = $this->measure('/api/v1/staff', fn () => $this->reviewer('lgu_admin'), 6, asReviewer: true);

        $this->assertFixtureProduced(6, DB::table('role_assignments')->count(), 'staff with a role assignment');
        $this->assertBudget('staff directory', $small, $large);
    }

    /**
     * Each eligibility check read its results with its own query — 5 for one check, 10 for six.
     * Unconditional: a check always has results, so no shape of data avoided it.
     */
    #[Test]
    public function the_eligibility_history_does_not_grow_with_checks(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $case = $this->caseForEligibility();
        $url = "/api/v1/admin/assistance-requests/{$case}/eligibility-checks";

        $small = $this->measure($url, fn () => $this->runEligibilityCheck($case), 1);
        $large = $this->measure($url, fn () => $this->runEligibilityCheck($case), 6);

        $this->assertFixtureProduced(6, DB::table('welfare_case_eligibility_results')->count(), 'eligibility results');
        $this->assertBudget('eligibility history', $small, $large);
    }

    /**
     * A resident's own correction requests read their changed fields per row — 7 for one, 12 for
     * six. Only one request may be pending at a time, so the fixture closes each before filing
     * the next, which is also how this list gets long in practice.
     */
    #[Test]
    public function a_citizens_own_corrections_do_not_grow_with_requests(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        $this->applicant = $citizen;

        $small = $this->measure('/api/v1/me/profile/corrections', fn () => $this->fileAndCloseCorrection($citizen), 1, asApplicant: true);
        $large = $this->measure('/api/v1/me/profile/corrections', fn () => $this->fileAndCloseCorrection($citizen), 6, asApplicant: true);

        $this->assertFixtureProduced(6, DB::table('resident_correction_fields')->count(), 'correction fields');
        $this->assertBudget('own corrections', $small, $large);
    }

    /**
     * TWO queries per row — the changed fields, and the resident — 7 for one, 17 for six.
     *
     * A DIFFERENT resident per row, which is what the batch resident lookup has to survive: a
     * fixture reusing one resident would resolve a single-entry map and hide nothing.
     */
    #[Test]
    public function the_correction_review_queue_does_not_grow_with_requests(): void
    {
        $url = '/api/v1/admin/resident-corrections?status=pending';

        $small = $this->measure($url, fn () => $this->fileCorrectionAsNewResident(), 1, asReviewer: true);
        $large = $this->measure($url, fn () => $this->fileCorrectionAsNewResident(), 6, asReviewer: true);

        $this->assertFixtureProduced(
            6,
            DB::table('resident_correction_requests')->where('status', 'pending')->distinct()->count('resident_id'),
            'pending requests from DISTINCT residents',
        );
        $this->assertBudget('correction review queue', $small, $large);
    }

    #[Test]
    public function a_citizens_own_case_list_does_not_grow_with_cases(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $small = $this->measure('/api/v1/me/cases', fn () => $this->submitCase(), 1);
        $large = $this->measure('/api/v1/me/cases', fn () => $this->submitCase(), 5);

        $this->assertBudget('me/cases', $small, $large);
    }

    // ── the harness ───────────────────────────────────────────────────────────────────

    private function assertBudget(string $label, int $small, int $large): void
    {
        $growth = $large - $small;

        $this->assertLessThanOrEqual(self::ALLOWED_GROWTH, $growth, implode("\n", [
            sprintf('[%s] costs %d queries for the small page and %d for the large one.', $label, $small, $large),
            '',
            'A list endpoint whose query count grows with its row count is an N+1. Resolve whatever',
            'the projection needs ONCE for the whole page — eager-load the relation, or use the',
            'batch form of the lookup — and pass the resolved map into the projection.',
            '',
            'Note the trap: falling back to a per-row lookup when the batch has no entry for a row',
            'is not a fix. An absent entry is a real answer, and the fallback measured WORSE than',
            'the N+1 it replaced, because it paid for the batch and then did the work anyway.',
        ]));
    }

    /**
     * THE FIXTURE MUST HAVE PRODUCED THE DATA THE ENDPOINT IS CHARGED FOR.
     *
     * Every per-row lookup measured here is conditional on something: a requirement with a
     * document, an event with a cover, a comment with a parent, an image that actually decoded.
     * When the condition is absent the lookup returns without querying, the endpoint measures
     * flat, and the gate reports it safe.
     *
     * That is not hypothetical. `/events` passed this suite at 4 → 4 for two rounds while really
     * costing three queries per row, and the requirements page did the same — both because their
     * fixtures produced rows the projection never had to resolve. A measurement that does not
     * assert its own reach is not evidence.
     */
    private function assertFixtureProduced(int $atLeast, int $actual, string $what): void
    {
        $this->assertGreaterThanOrEqual($atLeast, $actual, sprintf(
            'The fixture produced %d %s and needed at least %d. Without them the per-row lookup '
            .'this test exists to measure never runs, so the endpoint measures flat whether or '
            .'not it has an N+1.',
            $actual,
            $what,
            $atLeast,
        ));
    }

    // ── TAB 15 step 4: the endpoints TAB 07 added ────────────────────────────────────

    /**
     * The family list resolves a member count and a household reference per row.
     *
     * I wrote an N+1 into this one and removed it before it shipped: `uuidOf()` looked up each
     * resident identifier with its own query, and the kinship history resolved a family per
     * membership row. This is what stops either coming back.
     */
    #[Test]
    public function the_family_list_does_not_grow_with_families(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $household = $this->householdForBudget();

        $small = $this->measure(
            '/api/v1/admin/families',
            fn () => $this->familyForBudget($household),
            1,
        );
        $large = $this->measure(
            '/api/v1/admin/families',
            fn () => $this->familyForBudget($household),
            6,
        );

        $this->assertBudget('family list', $small, $large);
    }

    /**
     * The beneficiary registry is the one most exposed to this: every row needs assistance facts
     * from another module, so a per-row lookup would be a cross-module call per resident.
     *
     * It is batched deliberately — `AssistanceHistory::factsFor()` takes the whole page — and this
     * asserts the batching survives.
     */
    #[Test]
    public function the_beneficiary_registry_does_not_grow_with_residents(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $small = $this->measure('/api/v1/admin/beneficiaries', fn () => $this->existingResident([
            'first_name' => 'Budget'.mt_rand(1000, 9999),
            'middle_name' => null,
            'last_name' => 'Beneficiary',
        ]), 1);

        $large = $this->measure('/api/v1/admin/beneficiaries', fn () => $this->existingResident([
            'first_name' => 'Budget'.mt_rand(1000, 9999),
            'middle_name' => null,
            'last_name' => 'Beneficiary',
        ]), 6);

        $this->assertBudget('beneficiary registry', $small, $large);
    }

    /**
     * A work queue is the screen an office refreshes most, so an N+1 here is paid for constantly.
     */
    #[Test]
    public function the_work_queue_does_not_grow_with_tasks(): void
    {
        $me = $this->reviewer('lgu_admin');
        Sanctum::actingAs($me);

        $small = $this->measure('/api/v1/admin/work/mine', fn () => $this->taskForBudget((string) $me->uuid), 1);
        $large = $this->measure('/api/v1/admin/work/mine', fn () => $this->taskForBudget((string) $me->uuid), 6);

        $this->assertBudget('my work queue', $small, $large);
    }

    private function auditEntryForBudget(int $n, ?string $entityId = null): void
    {
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => (string) Str::uuid7(),
            'actor_label' => "Budget actor {$n}",
            'action' => 'budget.probe',
            'entity_type' => 'Welfare.Case',
            'entity_id' => $entityId ?? (string) Str::uuid7(),
            'summary' => "A recorded act, number {$n}.",
            'created_at' => now(),
        ]);
    }

    private function residentForBudget(int $n): void
    {
        $this->postJson('/api/v1/admin/residents', [
            'first_name' => "Budget{$n}",
            'last_name' => 'Resident',
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated();
    }

    private function householdForBudget(): string
    {
        $head = (string) $this->postJson('/api/v1/admin/residents', [
            'first_name' => 'Budget',
            'last_name' => 'Head',
            'birth_date' => '1990-01-15',
            'sex' => 'female',
            'civil_status' => 'single',
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');

        return (string) $this->postJson('/api/v1/admin/households', [
            'head_resident_id' => $head,
            'barangay_id' => $this->barangayId(),
            'street_address' => '12 Rizal Street',
        ])->assertCreated()->json('data.id');
    }

    private function familyForBudget(string $household): void
    {
        $this->postJson("/api/v1/admin/households/{$household}/families", [
            'label' => 'Budget family '.mt_rand(1000, 9999),
        ])->assertCreated();
    }

    private function releaseForBudget(int $caseId, string $residentUuid, int $sequence): void
    {
        DB::table('releases')->insert([
            'uuid' => (string) Str::uuid7(),
            'reference_number' => 'RL-'.strtoupper(Str::random(10)),
            'welfare_case_id' => $caseId,
            'resident_id' => $residentUuid,
            'sequence' => $sequence,
            'kind' => 'cash',
            // Integer minor units, as Article 4 requires. A budget fixture is still a money row.
            'amount_centavos' => 150000,
            'currency' => 'PHP',
            'release_mode' => 'cash-pickup',
            'status' => 'released',
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function publishedProgramForBudget(int $n): void
    {
        Program::query()->create([
            'code' => "BUDGETPROG{$n}",
            'name' => "Budget programme {$n}",
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'published',
            // Both are required by `publicQuery()`; published alone is not enough to appear.
            'is_citizen_visible' => true,
            'eligibility_guidance_version' => '1',
        ]);
    }

    private function enrollmentForBudget(string $residentUuid, int $n): void
    {
        DB::table('program_enrollments')->insert([
            'uuid' => (string) Str::uuid7(),
            'program_id' => (string) Str::uuid7(),
            'program_code' => "BUDGET{$n}",
            'resident_id' => $residentUuid,
            'status' => 'active',
            'effective_from' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notificationForBudget(string $recipient): void
    {
        DB::table('notifications')->insert([
            'uuid' => (string) Str::uuid7(),
            'recipient_subject_id' => $recipient,
            'type' => 'general.notice',
            'category' => 'optional',
            'title' => 'Budget notice '.mt_rand(1000, 9999),
            'body' => 'A notice created to grow the inbox.',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function taskForBudget(string $assignee): void
    {
        DB::table('tasks')->insert([
            'uuid' => (string) Str::uuid7(),
            'type' => 'general',
            'title' => 'Budget task '.mt_rand(1000, 9999),
            'assigned_to' => $assignee,
            'priority' => 'normal',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Grows the data to `$rows`, then counts the queries one request costs.
     */
    /**
     * The duplicate queue resolves BOTH SIDES of every pair, one query each.
     *
     * `pairProjection()` calls `Resident::withTrashed()->find()` for `lower_resident_id` and again
     * for `higher_resident_id`, so a page of pairs costs two round trips per row on top of the
     * page itself. `withTrashed()` is correct and is why the obvious fix — an eager-loaded
     * relation — is not a one-liner: a merged-away resident must still render, which a default
     * relation would drop.
     *
     * Unmeasured until now. 16 of this API's 54 paginating endpoints had a budget, and this was
     * among the 38 that did not; the projection was written after the harness was pointed at its
     * neighbours.
     */
    #[Test]
    public function the_duplicate_queue_does_not_grow_with_pairs(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $family = 0;

        /*
         * One pair per call: two residents sharing an identity fingerprint, then detection.
         * The names vary per call so each duo collides only with its own partner — one shared
         * fingerprint across every resident would produce pairs combinatorially and the row count
         * would outrun the loop.
         */
        $addPair = function () use (&$family): void {
            $family++;

            foreach (['12 Rizal Street', '48 Bonifacio Street'] as $address) {
                $this->existingResident([
                    'first_name' => "Ana{$family}",
                    'last_name' => "Cruz{$family}",
                    'street_address' => $address,
                ]);
            }

            $this->postJson('/api/v1/admin/resident-duplicates/detect')->assertOk();
        };

        $url = '/api/v1/admin/resident-duplicates';

        $small = $this->measure($url, $addPair, 1);
        $large = $this->measure($url, $addPair, 6);

        $this->assertFixtureProduced(
            6,
            DB::table('resident_duplicate_pairs')->where('decision', 'undecided')->count(),
            'undecided pairs to render',
        );

        $this->assertBudget('duplicate review queue', $small, $large);
    }

    /**
     * The task list, which is `/admin/work/mine`'s neighbour over the same table.
     *
     * `admin/work/mine` has had a budget since TAB 15; `tasks` never did, and the two render
     * different projections of the same rows. An endpoint measured because its neighbour was is
     * exactly the coverage gap that hid the duplicate-queue N+1.
     */
    #[Test]
    public function the_task_list_does_not_grow_with_tasks(): void
    {
        $reviewer = $this->reviewer('lgu_admin');
        Sanctum::actingAs($reviewer);

        /*
         * The account's `uuid` IS the subject id — `ActorContextFactory` reads exactly that.
         *
         * This said `$reviewer->subject_id`, a column that does not exist on `accounts`, so it was
         * always null and `(string) null` is `''`. SQLite stored the empty string in a uuid column
         * without complaint; PostgreSQL refuses it, and the budget measured a table nobody owned.
         */
        $assignee = (string) $reviewer->uuid;
        $addTask = fn () => $this->taskForBudget($assignee);

        $url = '/api/v1/tasks';

        $small = $this->measure($url, $addTask, 1);
        $large = $this->measure($url, $addTask, 8);

        $this->assertFixtureProduced(
            8,
            DB::table('tasks')->where('status', 'open')->count(),
            'open tasks to render',
        );

        $this->assertBudget('task list', $small, $large);
    }

    /**
     * The citizen inbox — the screen a resident opens most, over the connection least able to
     * afford a per-row lookup.
     *
     * Unmeasured until now. Its projection reads columns today, and that is exactly the state in
     * which a budget is worth adding: the cost of a `subject` preview or a sender name being
     * resolved per row is paid by every resident on the platform, and nothing would have caught
     * it.
     */
    #[Test]
    public function the_citizen_inbox_does_not_grow_with_notifications(): void
    {
        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        /*
         * Authenticated BEFORE measuring rather than via `asCitizen:` — that flag calls
         * `activeCitizenWithResident()` itself, which mints a DIFFERENT citizen, and an inbox
         * addressed to the first one would then measure an empty page.
         */
        $recipient = (string) $citizen->uuid;
        $addNotice = fn () => $this->notificationForBudget($recipient);

        $url = '/api/v1/me/notifications';

        $small = $this->measure($url, $addNotice, 1);
        $large = $this->measure($url, $addNotice, 8);

        $this->assertFixtureProduced(
            8,
            DB::table('notifications')->where('recipient_subject_id', $recipient)->count(),
            'notices addressed to the measured citizen',
        );

        $this->assertBudget('citizen inbox', $small, $large);
    }

    /**
     * The release ledger — the money surface, and the one an auditor pages through.
     *
     * Its projection reads columns today. The budget is added while that is true, because the
     * screen that lists payouts is the one where a per-row resident or case lookup would be added
     * next: "show who it went to" is the obvious next request, and the obvious implementation of
     * it is a lookup inside the projection.
     */
    #[Test]
    public function the_release_ledger_does_not_grow_with_releases(): void
    {
        // Authenticated FIRST: `caseForEligibility()` files a staff intake, and an unauthenticated
        // one answers 401 rather than creating the case the rest of this test measures.
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $case = $this->caseForEligibility();

        $caseId = (int) DB::table('welfare_cases')->where('uuid', $case)->value('id');
        $residentUuid = (string) DB::table('welfare_cases')->where('uuid', $case)->value('resident_id');

        $sequence = 0;
        $addRelease = function () use ($caseId, $residentUuid, &$sequence): void {
            $sequence++;
            $this->releaseForBudget($caseId, $residentUuid, $sequence);
        };

        $url = '/api/v1/admin/releases';

        $small = $this->measure($url, $addRelease, 1);
        $large = $this->measure($url, $addRelease, 8);

        $this->assertFixtureProduced(8, DB::table('releases')->count(), 'releases to render');

        $this->assertBudget('release ledger', $small, $large);
    }

    /**
     * The referral queue — a disclosure record, and the list a caseworker chases.
     *
     * Every row names a resident and an outside destination, which is the shape most likely to
     * acquire a "who and where" lookup per row the first time somebody asks for a readable list.
     */
    #[Test]
    public function the_referral_queue_does_not_grow_with_referrals(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident(['first_name' => 'Ref', 'last_name' => 'Erral']);

        $addReferral = function () use ($resident): void {
            $this->postJson('/api/v1/admin/referrals', [
                'resident_id' => (string) $resident->uuid,
                'destination_name' => 'District hospital',
                'service_requested' => 'Medical social work assessment',
                'reason' => 'Unable to meet hospital bill.',
            ])->assertCreated();
        };

        $url = '/api/v1/admin/referrals';

        $small = $this->measure($url, $addReferral, 1);
        $large = $this->measure($url, $addReferral, 6);

        $this->assertFixtureProduced(
            6,
            DB::table('referrals')->whereNotIn('status', ['closed', 'declined'])->count(),
            'open referrals to render',
        );

        $this->assertBudget('referral queue', $small, $large);
    }

    /**
     * The service-provider directory — read by front-line staff while preparing a referral.
     *
     * Reference data rather than casework, and small today. It is measured because "small today"
     * is how every list starts, and this one is read on the path to raising a referral.
     */
    #[Test]
    public function the_provider_directory_does_not_grow_with_providers(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $n = 0;
        $addProvider = function () use (&$n): void {
            $n++;
            $this->postJson('/api/v1/admin/service-providers', [
                'name' => "Budget partner {$n}",
                'destination_type' => 'ngo-partner',
                'services_offered' => ['counselling'],
                'channels' => ['phone'],
            ])->assertCreated();
        };

        $url = '/api/v1/admin/service-providers';

        $small = $this->measure($url, $addProvider, 1);
        $large = $this->measure($url, $addProvider, 8);

        $this->assertFixtureProduced(8, DB::table('service_providers')->count(), 'providers to render');

        $this->assertBudget('provider directory', $small, $large);
    }

    /**
     * The staff case list — the busiest screen in the admin console.
     *
     * Its projection reads columns today and the query paginates properly, so this measures flat.
     * It is worth pinning anyway, and for a specific reason: `resident_id` and `barangay_id` are
     * emitted as bare identifiers, and the obvious next request on a case queue is a resident
     * name beside each row. That is one `summaryFor()` away from being an N+1 on the page staff
     * open first, and the duplicate queue fixed earlier today is what that mistake looks like
     * once it is made.
     */
    #[Test]
    public function the_staff_case_list_does_not_grow_with_cases(): void
    {
        // Authenticated first: `caseForEligibility()` files a staff intake and answers 401 without it.
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $addCase = fn () => $this->caseForEligibility();

        $url = '/api/v1/admin/assistance-requests';

        $small = $this->measure($url, $addCase, 1);
        $large = $this->measure($url, $addCase, 6);

        $this->assertFixtureProduced(6, DB::table('welfare_cases')->count(), 'cases to render');

        $this->assertBudget('staff case list', $small, $large);
    }

    /**
     * The field-visit schedule — the list a caseworker opens before going out for the day.
     *
     * Every row names a resident by identifier. Like the case list, the obvious next request is a
     * name and an address beside each visit, and this is what stops that arriving as a per-row
     * lookup on a screen read on a phone in the field.
     */
    #[Test]
    public function the_visit_schedule_does_not_grow_with_visits(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident(['first_name' => 'Vis', 'last_name' => 'Itor']);

        $day = 0;
        $addVisit = function () use ($resident, &$day): void {
            $day++;
            $this->postJson('/api/v1/admin/visits', [
                'resident_id' => (string) $resident->uuid,
                'purpose' => 'verification',
                'scheduled_for' => now()->addDays($day)->toDateString(),
            ])->assertCreated();
        };

        /*
         * `scope=all` rather than the default. Unscoped, the list narrows to the caller's own
         * assignments, and a fixture that creates unassigned visits would measure an empty page —
         * a flat budget over nothing, which is the failure this harness exists to refuse.
         */
        $url = '/api/v1/admin/visits?scope=all';

        $small = $this->measure($url, $addVisit, 1);
        $large = $this->measure($url, $addVisit, 6);

        $this->assertFixtureProduced(6, DB::table('field_visits')->count(), 'visits to render');

        $this->assertBudget('visit schedule', $small, $large);
    }

    /**
     * The moderation queue, WITH the reasons now eager-loaded.
     *
     * `reportedComments()` gained `->with('reports')` because `report_reasons` was rendering null
     * for every row — the projection only emits it when the relation is loaded, and `withCount`
     * counts without loading. This measures the fix did not trade a silent omission for an N+1:
     * loading the reasons per comment would be one query per row on the screen a moderator uses
     * to triage a morning's reports.
     */
    #[Test]
    public function the_moderation_queue_does_not_grow_with_reported_comments(): void
    {
        // Publishing is a staff act; the reporting below re-authenticates per resident.
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $post = $this->publishPost();

        $addReportedComment = function () use ($post): void {
            [$author] = $this->activeCitizenWithResident();
            Sanctum::actingAs($author);
            $comment = (string) $this->postJson("/api/v1/newsfeed/{$post}/comments", [
                'body' => 'A comment to be reported '.mt_rand(1000, 9999),
            ])->assertCreated()->json('data.id');

            [$reporter] = $this->activeCitizenWithResident();
            Sanctum::actingAs($reporter);
            $this->postJson("/api/v1/newsfeed-comments/{$comment}/reports", ['reason' => 'abusive'])
                ->assertStatus(202);
        };

        $url = '/api/v1/admin/newsfeed-comments?reported=true';

        $small = $this->measure($url, $addReportedComment, 1, asReviewer: true);
        $large = $this->measure($url, $addReportedComment, 6, asReviewer: true);

        $this->assertFixtureProduced(
            6,
            DB::table('newsfeed_comment_reports')->count(),
            'reported comments to render',
        );

        $this->assertBudget('moderation queue', $small, $large);
    }

    /**
     * The programme enrolment roll — who is on which programme.
     *
     * Pure columns today and it paginates at the query, so this measures flat. Pinned because
     * every row carries a `resident_id` and a `program_code` and nothing else: a roll is read to
     * find PEOPLE, so "show the name and the programme title" is the obvious next request, and
     * both are cross-module lookups that would land per row.
     */
    #[Test]
    public function the_enrolment_roll_does_not_grow_with_enrolments(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $resident = $this->existingResident(['first_name' => 'Enrol', 'last_name' => 'Ment']);
        $residentUuid = (string) $resident->uuid;

        $n = 0;
        $addEnrolment = function () use ($residentUuid, &$n): void {
            $n++;
            $this->enrollmentForBudget($residentUuid, $n);
        };

        $url = '/api/v1/admin/enrollments';

        $small = $this->measure($url, $addEnrolment, 1);
        $large = $this->measure($url, $addEnrolment, 8);

        $this->assertFixtureProduced(8, DB::table('program_enrollments')->count(), 'enrolments to render');

        $this->assertBudget('enrolment roll', $small, $large);
    }

    /**
     * The public programme catalogue — what a resident sees before signing in, and for many the
     * first request the app ever makes.
     *
     * Measured ANONYMOUSLY. The same route renders a staff projection when the caller holds
     * `program.view`, so measuring as an admin would budget a different projection than the one
     * the public actually gets.
     */
    #[Test]
    public function the_public_programme_catalogue_does_not_grow_with_programmes(): void
    {
        $n = 0;
        $addProgramme = function () use (&$n): void {
            $n++;
            $this->publishedProgramForBudget($n);
        };

        $url = '/api/v1/programs';

        $small = $this->measure($url, $addProgramme, 1);
        $large = $this->measure($url, $addProgramme, 8);

        $this->assertFixtureProduced(
            8,
            DB::table('programs')->where('status', 'published')->where('is_citizen_visible', true)->count(),
            'published, citizen-visible programmes',
        );

        $this->assertBudget('public programme catalogue', $small, $large);
    }

    /**
     * The legal-hold register — the list the Data Protection Officer works from.
     *
     * A hold is what stops a retention purge, so this register is read before anything is
     * deleted. Every row names an entity by type and opaque id and nothing else; "show me what
     * this hold is actually on" is the obvious next request, and resolving a subject per row
     * would be a cross-module lookup on the screen that gates deletions.
     *
     * Measured as the DPO: `privacy.manage` places a hold, and `lgu_admin` does not hold it —
     * the separation is deliberate, so the budget has to run as the role that can.
     */
    #[Test]
    public function the_legal_hold_register_does_not_grow_with_holds(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $n = 0;
        $addHold = function () use (&$n): void {
            $n++;
            $this->postJson('/api/v1/admin/privacy/legal-holds', [
                'entity_type' => 'Welfare.Case',
                'entity_id' => (string) Str::uuid7(),
                'reference' => sprintf('NPC-2026-%03d', $n),
                'reason' => 'Complaint under investigation by the National Privacy Commission.',
            ])->assertCreated();
        };

        $url = '/api/v1/admin/privacy/legal-holds';

        $small = $this->measure($url, $addHold, 1);
        $large = $this->measure($url, $addHold, 6);

        $this->assertFixtureProduced(6, DB::table('legal_holds')->count(), 'legal holds to render');

        $this->assertBudget('legal-hold register', $small, $large);
    }

    /**
     * The release-batch list — payout runs, the unit an office schedules money by.
     *
     * Pure columns today, so this measures flat. Pinned because a batch is a CONTAINER: the
     * obvious next column is how many releases it holds and what they total, and both are
     * per-batch aggregates that arrive as a query per row unless somebody reaches for
     * `withCount` or a grouped sum.
     *
     * Every write here carries an `Idempotency-Key`, which is what `money()` supplies. A batch
     * created without one is refused and the fixture would produce no rows.
     */
    #[Test]
    public function the_release_batch_list_does_not_grow_with_batches(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $n = 0;
        $addBatch = function () use (&$n): void {
            $n++;
            $this->money()->postJson('/api/v1/admin/release-batches', [
                'name' => "Budget payout run {$n}",
                'scheduled_for' => now()->addWeeks($n)->toDateString(),
            ])->assertCreated();
        };

        $url = '/api/v1/admin/release-batches';

        $small = $this->measure($url, $addBatch, 1);
        $large = $this->measure($url, $addBatch, 6);

        $this->assertFixtureProduced(6, DB::table('release_batches')->count(), 'batches to render');

        $this->assertBudget('release batch list', $small, $large);
    }

    /**
     * The staff newsfeed list — and the endpoint that showed this harness was over-reporting its
     * own coverage.
     *
     * `adminProjection()` renders `reaction_count` and `comment_count` as
     * `$post->reactions_count ?? $post->reactions()->count()`. The alias comes from a
     * `withCount(['reactions', 'comments'])` on this query alone, and the fallback is a query per
     * row per count — two extra round trips per post if it is ever dropped.
     *
     * **It was NOT measured, and I had counted it as measured.** A coverage tally built by
     * grepping every `/api/v1/...` string in this file counts fixture POST targets as though they
     * were measured URLs; `/admin/newsfeed` appears here only because posts are CREATED through
     * it. Proven by removing the `withCount` and watching the whole budget suite stay green.
     */
    #[Test]
    public function the_staff_newsfeed_list_does_not_grow_with_posts(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $url = '/api/v1/admin/newsfeed';

        $small = $this->measure($url, fn () => $this->publishPost(), 1);
        $large = $this->measure($url, fn () => $this->publishPost(), 8);

        $this->assertFixtureProduced(8, DB::table('newsfeed_posts')->count(), 'posts to render');

        $this->assertBudget('staff newsfeed list', $small, $large);
    }

    /**
     * The staff resident registry — the busiest list in the console, and one of three that a
     * miscounted coverage tally had reported as measured while it was only a fixture target.
     */
    #[Test]
    public function the_resident_registry_does_not_grow_with_residents(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $n = 0;
        $addResident = function () use (&$n): void {
            $n++;
            $this->residentForBudget($n);
        };

        $url = '/api/v1/admin/residents';

        $small = $this->measure($url, $addResident, 1);
        $large = $this->measure($url, $addResident, 8);

        $this->assertFixtureProduced(8, DB::table('residents')->count(), 'residents to render');

        $this->assertBudget('resident registry', $small, $large);
    }

    /**
     * The household register. Each fixture call creates a head resident AND the household, so the
     * residents table grows alongside — which is why this counts households, not residents.
     */
    #[Test]
    public function the_household_register_does_not_grow_with_households(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $url = '/api/v1/admin/households';

        $small = $this->measure($url, fn () => $this->householdForBudget(), 1);
        $large = $this->measure($url, fn () => $this->householdForBudget(), 6);

        $this->assertFixtureProduced(6, DB::table('households')->count(), 'households to render');

        $this->assertBudget('household register', $small, $large);
    }

    /**
     * The staff event list — every status, unlike the public `/events` the harness already
     * measures. Two projections behind one route again, and only one of them was covered.
     */
    #[Test]
    public function the_staff_event_list_does_not_grow_with_events(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $url = '/api/v1/admin/events';

        $small = $this->measure($url, fn () => $this->publishedEvent(), 1);
        $large = $this->measure($url, fn () => $this->publishedEvent(), 6);

        $this->assertFixtureProduced(6, DB::table('events')->count(), 'events to render');

        $this->assertBudget('staff event list', $small, $large);
    }

    /**
     * The audit trail — the last paginating endpoint in this API without a budget, and the one
     * this harness previously ruled out as unmeasurable.
     *
     * **THAT RULING WAS TOO QUICK.** Reading the trail WRITES to it — `audit.searched`, with the
     * query keys — so the table a budget counts grows by one on every measured request, and I
     * recorded it as a fixture that would perturb its own subject.
     *
     * It perturbs the COUNT, not the SLOPE. The extra row is one per request whether the page
     * holds one entry or eight, so it lands identically in both samples and cancels out of the
     * growth. `assertBudget` compares two measurements; a constant added to both is invisible to
     * it. What would have broken is an assertion on an absolute number, which this harness
     * deliberately does not make.
     *
     * Measured as the DPO: `audit.view` is withheld from `lgu_admin` on purpose, so the trail
     * recording the head's approvals is not read by the head.
     */
    #[Test]
    public function the_audit_trail_does_not_grow_with_entries(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $n = 0;
        $addEntry = function () use (&$n): void {
            $n++;
            $this->auditEntryForBudget($n);
        };

        $url = '/api/v1/admin/audit-entries';

        $small = $this->measure($url, $addEntry, 2);
        $large = $this->measure($url, $addEntry, 12);

        $this->assertBudget('audit trail', $small, $large);
    }

    /**
     * One entity's history — the page a Data Protection Officer opens to answer "who touched this
     * record". Its rows are the same shape as the trail above, filtered to one subject.
     */
    #[Test]
    public function one_entitys_audit_history_does_not_grow_with_entries(): void
    {
        Sanctum::actingAs($this->reviewer('data_protection_officer'));

        $n = 0;
        $addEntry = function () use (&$n): void {
            $n++;
            $this->auditEntryForBudget($n, self::BUDGET_AUDIT_ENTITY);
        };

        $url = '/api/v1/admin/audit-entries/for-entity?entity_type=Welfare.Case&entity_id='
            .self::BUDGET_AUDIT_ENTITY;

        $small = $this->measure($url, $addEntry, 2);
        $large = $this->measure($url, $addEntry, 12);

        $this->assertBudget("one entity's audit history", $small, $large);
    }

    private function measure(
        string $url,
        callable $addOne,
        int $rows,
        bool $asCitizen = false,
        bool $asApplicant = false,
        bool $asReviewer = false,
        ?callable $beforeMeasuring = null,
    ): int {
        $admin = auth()->user();

        /*
         * Guarded, and the guard FAILS rather than breaking quietly. A fixture that stops
         * producing rows — a changed validation rule, a renamed field — would otherwise spin this
         * loop until the suite's timeout killed it with no indication of which test or why.
         */
        $attempts = 0;

        while ($this->rowsSoFar($url) < $rows) {
            $this->assertLessThan(
                $rows * 3 + 5,
                ++$attempts,
                "[{$url}] the fixture stopped producing rows before reaching {$rows}.",
            );

            $addOne();
        }

        // Runs after the rows exist and before anything is counted, for a test whose subject is
        // the state of the data rather than its volume.
        if ($beforeMeasuring !== null) {
            $beforeMeasuring();
        }

        // AFTER the growth loop, never before: a fixture that files a request as the citizen
        // leaves that citizen authenticated, and measuring as them answers 403.
        if ($asReviewer) {
            Sanctum::actingAs($this->reviewer('lgu_admin'));
        }

        if ($asCitizen) {
            [$citizen] = $this->activeCitizenWithResident();
            Sanctum::actingAs($citizen);
        }

        // The applicant whose case this is — a different citizen would get a 404, and the
        // assertion on the status below would catch it.
        if ($asApplicant && $this->applicant !== null) {
            Sanctum::actingAs($this->applicant);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($url);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(200, $response->status(), "[{$url}] did not answer 200.");

        if ($admin !== null) {
            Sanctum::actingAs($admin);
        }

        return $count;
    }

    /**
     * How many rows the endpoint currently has to render.
     *
     * Counted from the table rather than from the response, so the loop cannot spin forever when
     * a fixture fails to produce what the endpoint shows.
     */
    private function rowsSoFar(string $url): int
    {
        return match (true) {
            // BEFORE the '/me/cases' arm, which the citizen requirements URL also contains.
            // Counted as rows WITH a document, because a requirement without one costs nothing.
            str_contains($url, '/requirements') => DB::table('welfare_case_requirements')
                ->whereNotNull('document_id')->count(),
            // Replies only — a top-level comment costs no parent lookup, so counting all comments
            // would satisfy the loop without ever creating what is under test.
            str_contains($url, '/comments') => DB::table('newsfeed_comments')->whereNotNull('parent_id')->count(),
            str_contains($url, '/kyc-cases') => DB::table('kyc_cases')->count(),
            str_contains($url, '/staff') => DB::table('role_assignments')->count(),
            str_contains($url, '/eligibility-checks') => DB::table('welfare_case_eligibility_checks')->count(),
            // Pending only: a closed request leaves the queue, so counting all of them would end
            // the loop with a page the endpoint does not render.
            str_contains($url, '/admin/resident-corrections') => DB::table('resident_correction_requests')
                ->where('status', 'pending')->count(),
            str_contains($url, '/me/profile/corrections') => DB::table('resident_correction_requests')->count(),
            str_contains($url, '/document-requests') => DB::table('document_requests')->count(),
            str_contains($url, '/registrations') => DB::table('event_registrations')->count(),
            /*
             * BEFORE the '/newsfeed' arm below, which it would otherwise match: the string
             * "/admin/newsfeed-comments" CONTAINS "/newsfeed", so the posts arm answered first and
             * counted published posts — one — and the growth loop gave up at "the fixture stopped
             * producing rows". Reported comments only, since the queue is measured with
             * `?reported=true` and counting every comment would end the loop with rows that page
             * does not render.
             */
            str_contains($url, '/admin/newsfeed-comments') => DB::table('newsfeed_comments')
                ->whereIn('id', DB::table('newsfeed_comment_reports')->select('newsfeed_comment_id'))
                ->count(),
            /*
             * BEFORE the '/newsfeed' arm below, which "/admin/newsfeed" also matches. The staff
             * list renders every status, so it counts all posts rather than published ones.
             */
            str_contains($url, '/admin/newsfeed') => DB::table('newsfeed_posts')->count(),
            str_contains($url, '/newsfeed') => DB::table('newsfeed_posts')->where('status', 'published')->count(),
            /*
             * BEFORE the '/events' arm below, which "/admin/events" also matches. The staff list
             * renders every status, so it counts all events rather than published ones — and the
             * registrations sub-resource is unaffected because '/registrations' is matched further
             * up.
             */
            str_contains($url, '/admin/events') => DB::table('events')->count(),
            str_contains($url, '/events') => DB::table('events')->where('status', 'published')->count(),
            /*
             * BEFORE the '/me/cases' arm, and note it does NOT collide with the '/requirements'
             * arm at the top: this URL is "assistance-requests", not "requirements".
             */
            str_contains($url, '/admin/assistance-requests') => DB::table('welfare_cases')->count(),
            str_contains($url, '/me/notifications') => DB::table('notifications')->count(),
            str_contains($url, '/admin/releases') => DB::table('releases')->count(),
            /*
             * OPEN referrals only. The list excludes `closed` and `declined` by default, so
             * counting every row would end the growth loop with a page the endpoint does not
             * render — the same trap the corrections and duplicate arms above record.
             */
            str_contains($url, '/admin/referrals') => DB::table('referrals')
                ->whereNotIn('status', ['closed', 'declined'])->count(),
            str_contains($url, '/admin/service-providers') => DB::table('service_providers')->count(),
            str_contains($url, '/admin/visits') => DB::table('field_visits')->count(),
            str_contains($url, '/admin/enrollments') => DB::table('program_enrollments')->count(),
            /*
             * BEFORE the plain '/admin/audit-entries' arm: the for-entity page renders only ONE
             * entity's history, so counting the whole table would end the growth loop with rows
             * that page never shows.
             */
            str_contains($url, '/admin/audit-entries/for-entity') => DB::table('audit_entries')
                ->where('entity_id', self::BUDGET_AUDIT_ENTITY)->count(),
            str_contains($url, '/admin/audit-entries') => DB::table('audit_entries')->count(),
            str_contains($url, '/admin/privacy/legal-holds') => DB::table('legal_holds')->count(),
            /*
             * No ordering constraint against the '/admin/releases' arm, checked rather than
             * assumed: "/admin/release-batches" does NOT contain "/admin/releases" — the hyphen
             * sits where the `s` would be. Several arms in this match DO collide that way, so the
             * absence of one here is worth stating.
             */
            str_contains($url, '/admin/release-batches') => DB::table('release_batches')->count(),
            /*
             * The PUBLIC catalogue's own filter: published and citizen-visible. Counting every
             * programme would end the growth loop with rows an anonymous caller never sees —
             * `publicQuery()` narrows on both columns.
             */
            str_contains($url, '/programs') => DB::table('programs')
                ->where('status', 'published')->where('is_citizen_visible', true)->count(),
            str_contains($url, '/me/cases') => DB::table('welfare_cases')->count(),
            // ── TAB 15: the endpoints TAB 07 added ────────────────────────────────
            /*
             * Undecided only — the queue's default. Counting every pair would satisfy the loop
             * with decided ones the endpoint does not render without `?decision=`, which is the
             * same trap the resident-corrections arm above records.
             */
            str_contains($url, '/admin/resident-duplicates') => DB::table('resident_duplicate_pairs')
                ->where('decision', 'undecided')->count(),
            // Neither collides with an arm above: "/admin/residents" is not a substring of
            // "/admin/resident-corrections" or "/admin/resident-duplicates", and nothing matches
            // "/admin/households". Checked rather than assumed.
            str_contains($url, '/admin/residents') => DB::table('residents')->count(),
            str_contains($url, '/admin/households') => DB::table('households')->count(),
            str_contains($url, '/admin/families') => DB::table('families')->count(),
            str_contains($url, '/admin/beneficiaries') => DB::table('residents')->count(),
            str_contains($url, '/admin/work/') => DB::table('tasks')->where('status', 'open')->count(),
            // AFTER the '/admin/work/' arm, which is also backed by `tasks`. The citizen-facing
            // task list is a different endpoint over the same table.
            str_contains($url, '/tasks') => DB::table('tasks')->where('status', 'open')->count(),
            /*
             * ZERO IS NOT A SAFE DEFAULT, and this arm exists because it was.
             *
             * A URL with no arm above counts zero rows forever, so the loop exhausts its attempts
             * and the guard fails with "the fixture stopped producing rows" — which reads as a
             * broken fixture and is actually a missing arm. Three tests said exactly that when
             * these endpoints were added.
             *
             * Left as a failure rather than made to pass: an unknown URL measuring nothing would
             * be a green budget test asserting a growth of zero over zero rows.
             */
            default => 0,
        };
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * A submitted case, remembering the applicant so the citizen view can be read as its owner.
     */
    private function caseWithRequirements(): string
    {
        $admin = auth()->user();

        [$citizen] = $this->activeCitizenWithResident();
        $this->applicant = $citizen;
        Sanctum::actingAs($citizen);

        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help.',
            'consent_reference' => 'budget-requirements',
        ])->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit");

        if ($admin !== null) {
            Sanctum::actingAs($admin);
        }

        return (string) DB::table('welfare_cases')->orderByDesc('id')->value('uuid');
    }

    /**
     * One more requirement with a document recorded against it, through the service the staff
     * upload endpoint calls.
     */
    private function recordOneDocument(string $case): void
    {
        $model = WelfareCase::query()->where('uuid', $case)->firstOrFail();

        $slot = CaseRequirement::query()->create([
            'welfare_case_id' => $model->id,
            'requirement_code' => 'DOC_'.DB::table('welfare_case_requirements')->count(),
            'label' => 'Barangay certificate',
            'template_version' => '1',
            'obligation' => RequirementObligation::Required,
            'applicability' => RequirementApplicability::Applies,
        ]);

        $file = app(DocumentLibrary::class)->store(
            UploadedFile::fake()->createWithContent('barangay-cert.jpg', $this->realJpeg()),
            FileClassification::Personal,
            ActorContext::system(),
        );

        app(CaseRequirementService::class)->recordDocument($slot, (string) $file->id, [
            'source' => DocumentSource::Scanned,
            'document_type' => 'barangay-certificate',
            'document_number' => null,
            'issued_on' => null,
            'expires_on' => null,
            'expiry_unknown' => true,
            'replaces_because' => null,
        ], ActorContext::system());
    }

    /**
     * A published event with a real cover image, so the cover lookup actually runs.
     */
    private function publishedEventWithCover(): void
    {
        $file = app(FileStore::class)->store(
            UploadedFile::fake()->createWithContent('poster.jpg', $this->realJpeg()),
            FileClassification::PublicReference,
            ActorContext::system(),
        );

        $this->publishedEvent([
            'cover_file_id' => (string) $file->uuid,
            'cover_alt_text' => 'Residents queueing outside the barangay hall.',
        ]);
    }

    private function caseForEligibility(): string
    {
        $resident = $this->existingResident(['first_name' => 'Elig', 'last_name' => 'Ible']);

        return (string) $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }

    private function runEligibilityCheck(string $case): void
    {
        $this->postJson("/api/v1/admin/assistance-requests/{$case}/eligibility-checks", [
            'program_id' => (string) $this->eligibilityProgram()->uuid,
        ])->assertCreated();
    }

    /**
     * Several criteria, so every check has several RESULTS — the relation read per row.
     */
    private function eligibilityProgram(): Program
    {
        if ($this->program !== null) {
            return $this->program;
        }

        $program = Program::query()->create([
            'code' => 'budget-program',
            'name' => 'Budget assistance programme',
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'draft',
            'is_citizen_visible' => true,
            'eligibility_guidance_version' => '1',
        ]);

        foreach ([['age', 'at-least', '18'], ['age2', 'at-most', '99'], ['age3', 'at-least', '1']] as [$code, $comparator, $value]) {
            ProgramEligibilityCriterion::query()->create([
                'program_id' => $program->id,
                'code' => $code,
                'fact' => 'age',
                'comparator' => $comparator,
                'value' => $value,
                'citizen_explanation' => 'A criterion.',
                'guidance_version' => '1',
                'is_blocking' => false,
            ]);
        }

        $program->forceFill(['status' => 'published'])->save();

        return $this->program = $program->refresh();
    }

    private function fileAndCloseCorrection(object $citizen): void
    {
        Sanctum::actingAs($citizen);

        $id = $this->postJson('/api/v1/me/profile/corrections', [
            'note' => 'My name is spelt wrong on my papers.',
            'changes' => ['first_name' => 'Corrected'.DB::table('resident_correction_requests')->count()],
        ])->assertCreated()->json('data.id');

        // Only one may be pending at a time, so it is closed before the next is filed.
        Sanctum::actingAs($this->reviewer('lgu_admin'));
        $this->postJson("/api/v1/admin/resident-corrections/{$id}/reject", [
            'review_note' => 'Please bring your birth certificate.',
        ])->assertOk();
    }

    private function fileCorrectionAsNewResident(): void
    {
        [$other] = $this->activeCitizenWithResident();
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/me/profile/corrections', [
            'note' => 'Wrong spelling.',
            'changes' => ['last_name' => 'Fixed'.DB::table('resident_correction_requests')->count()],
        ])->assertCreated();
    }

    private function openDocumentRequest(string $case): void
    {
        $model = WelfareCase::query()->where('uuid', $case)->firstOrFail();

        $slot = CaseRequirement::query()->create([
            'welfare_case_id' => $model->id,
            'requirement_code' => 'REQ_'.DB::table('welfare_case_requirements')->count(),
            'label' => 'Barangay certificate',
            'template_version' => '1',
            'obligation' => RequirementObligation::Required,
            'applicability' => RequirementApplicability::Applies,
        ]);

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/requirements/{$slot->uuid}/document-requests", [
            'channel' => 'in-person',
            'message' => 'Please bring your barangay certificate.',
        ])->assertCreated();
    }

    private function addComment(string $post, ?string $parent): string
    {
        $admin = auth()->user();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $id = $this->postJson("/api/v1/newsfeed/{$post}/comments", array_filter([
            'body' => 'Is the office open on Tuesday?',
            'parent_id' => $parent,
        ]))->json('data.id');

        if ($admin !== null) {
            Sanctum::actingAs($admin);
        }

        return (string) $id;
    }

    private function registerOneCitizen(string $event): void
    {
        $admin = auth()->user();

        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);
        $this->postJson("/api/v1/events/{$event}/registration");

        if ($admin !== null) {
            Sanctum::actingAs($admin);
        }
    }

    private function publishPost(): string
    {
        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Advisory '.DB::table('newsfeed_posts')->count(),
            'category' => 'advisory',
        ])->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published']);

        return (string) $post;
    }

    private function publishPostWithImage(): void
    {
        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'Advisory with a picture '.DB::table('newsfeed_posts')->count(),
            'category' => 'advisory',
        ])->json('data.id');

        $file = app(FileStore::class)->store(
            UploadedFile::fake()->createWithContent('poster.jpg', $this->realJpeg()),
            FileClassification::PublicReference,
            ActorContext::system(),
        );

        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) $file->uuid,
            'alt_text' => 'Residents queueing outside the barangay hall.',
        ]);

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published']);
    }

    private function submitCase(): void
    {
        $draft = $this->postJson('/api/v1/me/assistance/drafts', [
            'category' => 'food',
            'narrative' => 'Requesting help.',
            'consent_reference' => 'budget-'.DB::table('welfare_cases')->count(),
        ])->json('data.id');

        $this->postJson("/api/v1/me/assistance/drafts/{$draft}/submit");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedEvent(array $overrides = []): string
    {
        $event = $this->postJson('/api/v1/admin/events', $overrides + [
            'title' => 'Barangay feeding programme '.DB::table('events')->count(),
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
            'capacity' => 100,
        ])->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published']);

        return (string) $event;
    }

    /**
     * A genuinely decodable JPEG, so publication derives real public variants.
     *
     * A byte-signature stub passes the upload's type check and then fails to decode, so no
     * rendition is produced — and the measurement would be of the empty path rather than the one
     * production takes.
     */
    private function realJpeg(): string
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 180, 160));
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
