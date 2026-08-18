<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Application\FileStore;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\FileClassification;
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

    private ?object $applicant = null;

    private ?string $threadRoot = null;

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

        $url = "/api/v1/admin/cases/{$case}/requirements";

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

        $url = "/api/v1/admin/cases/{$case}/document-requests";

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

    /**
     * Grows the data to `$rows`, then counts the queries one request costs.
     */
    private function measure(
        string $url,
        callable $addOne,
        int $rows,
        bool $asCitizen = false,
        bool $asApplicant = false,
        bool $asReviewer = false,
        ?callable $beforeMeasuring = null,
    ): int {
        if ($asReviewer) {
            Sanctum::actingAs($this->reviewer('lgu_admin'));
        }

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
            str_contains($url, '/document-requests') => DB::table('document_requests')->count(),
            str_contains($url, '/registrations') => DB::table('event_registrations')->count(),
            str_contains($url, '/newsfeed') => DB::table('newsfeed_posts')->where('status', 'published')->count(),
            str_contains($url, '/events') => DB::table('events')->where('status', 'published')->count(),
            str_contains($url, '/me/cases') => DB::table('welfare_cases')->count(),
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

        $this->postJson("/api/v1/admin/cases/{$case}/requirements/{$slot->uuid}/document-requests", [
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
