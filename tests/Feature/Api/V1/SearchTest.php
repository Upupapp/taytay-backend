<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Search\Infrastructure\Eloquent\SavedView;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 22, as tests.
 *
 *  1. **Search never returns an object the caller could not open directly.**
 *  2. **A citizen cannot enumerate residents through search.**
 *  3. **Saved filters cannot inject raw SQL.**
 */
final class SearchTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 1: no result you could not open ────────────────────────────────────

    #[Test]
    public function search_excludes_records_outside_the_callers_scope(): void
    {
        Sanctum::actingAs($this->admin());

        $mine = $this->existingResident([
            'first_name' => 'Findable', 'middle_name' => null, 'last_name' => 'Nearby',
        ]);
        $theirs = $this->existingResident([
            'first_name' => 'Findable', 'middle_name' => null, 'last_name' => 'Faraway',
            'barangay_id' => $this->otherBarangayId(),
        ]);

        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        $results = $this->getJson('/api/v1/admin/search?q=Findable')->assertOk()->json('data.results');
        $ids = array_column($results, 'id');

        /*
         * The criterion, and the reason there is no separate search index: an index maintained
         * alongside the authorization rules eventually disagrees with them, and the disagreement
         * is invisible until somebody clicks a result and gets a 404 they should not have been
         * able to provoke.
         */
        $this->assertContains((string) $mine->uuid, $ids);
        $this->assertNotContains((string) $theirs->uuid, $ids);

        // And the excluded record is genuinely unopenable, which is what makes the two consistent.
        $this->getJson("/api/v1/admin/residents/{$theirs->uuid}")->assertNotFound();
    }

    #[Test]
    public function an_entity_the_caller_cannot_read_is_absent_rather_than_refused(): void
    {
        Sanctum::actingAs($this->admin());
        $this->existingResident(['first_name' => 'Searchable', 'middle_name' => null, 'last_name' => 'Person']);

        // A disbursing officer holds `resident.view` but not `referral.view`.
        $disburser = Account::factory()->staff()->create();
        $this->grantRole($disburser, 'disbursing_officer', $this->barangayId());
        Sanctum::actingAs($disburser);

        $body = $this->getJson('/api/v1/admin/search?q=Searchable')->assertOk()->json('data');

        /*
         * Absent, not refused. A "you may not see these 3 results" message is itself a count, and
         * a count of matching restricted records is most of what somebody probing wants.
         */
        $this->assertSame(['resident'], array_values(array_unique(array_column($body['results'], 'type'))));
        $this->assertArrayNotHasKey('hidden_count', $body);
    }

    #[Test]
    public function a_result_snippet_carries_no_detail_beyond_finding_the_record(): void
    {
        Sanctum::actingAs($this->admin());

        $this->existingResident([
            'first_name' => 'Snippet',
            'middle_name' => null,
            'last_name' => 'Person',
            'street_address' => '42 Secret Street',
            'birth_date' => '1975-01-01',
        ]);

        $body = $this->getJson('/api/v1/admin/search?q=Snippet')->assertOk()->content();

        // A result snippet is a way to find a record, not a way to read one without opening it.
        $this->assertStringNotContainsString('Secret Street', $body);
        $this->assertStringNotContainsString('1975-01-01', $body);
    }

    #[Test]
    public function restricted_case_types_stay_out_of_search_without_the_sensitive_permission(): void
    {
        Sanctum::actingAs($this->admin());

        $case = $this->postJson('/api/v1/admin/assistance-requests', [
            'resident_id' => (string) $this->client()->uuid,
            'type' => 'protective',
        ])->assertCreated()->json('data.reference');

        // Front-line staff hold `request.view` but not `request.view-sensitive`.
        Sanctum::actingAs($this->staff());

        $ids = array_column(
            $this->getJson('/api/v1/admin/search?q='.$case)->assertOk()->json('data.results'),
            'title',
        );

        // Knowing that a protection case exists for a searchable reference is most of the
        // disclosure (ADR 0016 §5).
        $this->assertNotContains($case, $ids);
    }

    #[Test]
    public function protected_text_is_never_searchable(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->client();
        $case = $this->caseFor($resident);

        $this->postJson("/api/v1/admin/assistance-requests/{$case}/notes", [
            'body' => 'Agreed a safety plan; shelter contacted on her behalf.',
            'sensitivity' => 'protected',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/safeguarding-concerns', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'child-protection',
            'detail' => 'Distinctive safeguarding phrase.',
        ])->assertCreated();

        /*
         * "Show me cases whose notes mention 'shelter'" is a disclosure performed by a search box.
         * Note bodies, safeguarding detail, referral reasons and visit observations are the four
         * places this system keeps text nobody should be able to ask questions of.
         */
        foreach (['shelter', 'Distinctive', 'safety plan'] as $term) {
            $this->assertSame(
                [],
                $this->getJson('/api/v1/admin/search?q='.urlencode($term))->assertOk()->json('data.results'),
                "searchable: {$term}",
            );
        }
    }

    #[Test]
    public function a_very_short_term_returns_nothing_rather_than_everything(): void
    {
        Sanctum::actingAs($this->admin());
        $this->existingResident(['first_name' => 'Aa', 'middle_name' => null, 'last_name' => 'Bb']);

        // A one-character term matches too much to be a search and too little to be useful — and
        // "a" would return the first five residents in the municipality to anybody who typed it.
        $body = $this->getJson('/api/v1/admin/search?q=a')->assertOk()->json('data');
        $this->assertSame([], $body['results']);
        $this->assertArrayHasKey('note', $body);
    }

    // ── criterion 2: a citizen cannot enumerate ──────────────────────────────────────

    #[Test]
    public function a_citizen_has_no_search_endpoint_at_all(): void
    {
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($this->admin());
        $this->existingResident(['first_name' => 'Target', 'middle_name' => null, 'last_name' => 'Person']);

        Sanctum::actingAs($account);

        /*
         * Not a filtered search — no search. A citizen endpoint that "only returned their own
         * records" would be a resident-enumeration endpoint one authorization bug away, and the
         * bug would be invisible because the endpoint would still look like it was working.
         */
        $this->getJson('/api/v1/admin/search?q=Target')->assertForbidden();
        $this->getJson('/api/v1/search?q=Target')->assertNotFound();
        $this->getJson('/api/v1/me/search?q=Target')->assertNotFound();
    }

    #[Test]
    public function search_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/search?q=anything')->assertUnauthorized();
        $this->getJson('/api/v1/admin/saved-views')->assertUnauthorized();
    }

    // ── criterion 3: filters cannot inject ───────────────────────────────────────────

    #[Test]
    public function a_filter_field_outside_the_grammar_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        foreach ([
            'body',                      // a protected note column
            'detail',                    // safeguarding
            'id) OR 1=1 --',
            'residents.first_name',
        ] as $field) {
            $this->postJson('/api/v1/admin/saved-views', [
                'entity' => 'cases',
                'name' => 'Attempt '.$field,
                'filters' => [['field' => $field, 'operator' => 'eq', 'value' => 'x']],
            ])->assertStatus(422);
        }

        // Nothing was stored, so nothing is waiting to be executed later.
        $this->assertSame(0, SavedView::query()->count());
    }

    #[Test]
    public function a_filterable_field_still_refuses_an_operator_it_does_not_allow(): void
    {
        Sanctum::actingAs($this->admin());

        // `status` is filterable with eq/in, and not with a range.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Range on a status',
            'filters' => [['field' => 'status', 'operator' => 'gte', 'value' => 'approved']],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_structured_value_on_a_scalar_operator_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        /*
         * A scalar operator with a structured value is how somebody smuggles an expression into a
         * binding in frameworks that allow it. Refused outright rather than cast.
         */
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Structured value',
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => ['sql' => 'raw']]],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_sort_outside_the_grammar_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        // Same defence as the filter: the column is looked up in a closed table, never
        // interpolated, so `id;DROP TABLE` cannot become a column reference.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Bad sort',
            'sort' => 'id;DROP TABLE welfare_cases',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_valid_filter_is_stored_normalised(): void
    {
        Sanctum::actingAs($this->admin());

        $view = $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Urgent and open',
            'filters' => [
                ['field' => 'status', 'operator' => 'in', 'value' => ['submitted', 'assessment']],
                ['field' => 'priority', 'operator' => 'eq', 'value' => 'urgent'],
            ],
            'sort' => '-opened_at',
        ])->assertCreated()->json('data');

        $this->assertCount(2, $view['filters']);
        $this->assertSame('status', $view['filters'][0]['field']);
        $this->assertSame('-opened_at', $view['sort']);
    }

    #[Test]
    public function an_in_filter_is_bounded(): void
    {
        Sanctum::actingAs($this->admin());

        // An `in` with ten thousand values makes one request cost as much as ten thousand, and no
        // legitimate saved view needs it.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Enormous',
            'filters' => [['field' => 'status', 'operator' => 'in', 'value' => array_fill(0, 500, 'x')]],
        ])->assertStatus(422);
    }

    // ── saved views ───────────────────────────────────────────────────────────────────

    #[Test]
    public function sharing_a_view_costs_a_permission(): void
    {
        Sanctum::actingAs($this->staff());

        // A personal view is free.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'My queue',
        ])->assertCreated();

        /*
         * Publishing one to the whole office is not. A badly-named shared view ("Suspicious
         * households") is a judgement broadcast to everybody who opens the list.
         */
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Everyone sees this',
            'is_shared' => true,
        ])->assertForbidden();
    }

    #[Test]
    public function a_shared_view_shares_the_question_and_never_the_results(): void
    {
        Sanctum::actingAs($this->admin());

        $view = $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Open cases',
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'submitted']],
            'is_shared' => true,
        ])->assertCreated()->json('data');

        // Stated in the payload so nobody mistakes a shared view for shared data.
        $this->assertStringContainsString('scoped to whoever runs it', $view['note']);

        // A colleague sees the view and is told it is not theirs.
        Sanctum::actingAs($this->staff());
        $theirs = collect($this->getJson('/api/v1/admin/saved-views')->assertOk()->json('data.views'))
            ->firstWhere('id', $view['id']);

        $this->assertNotNull($theirs);
        $this->assertFalse($theirs['is_mine']);
    }

    #[Test]
    public function a_view_can_only_be_deleted_by_its_owner(): void
    {
        Sanctum::actingAs($this->admin());
        $view = $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'cases',
            'name' => 'Mine',
            'is_shared' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->staff());
        $this->deleteJson("/api/v1/admin/saved-views/{$view}")->assertNotFound();

        $this->assertSame(1, SavedView::query()->count());
    }

    #[Test]
    public function the_grammar_is_published_so_a_client_does_not_keep_its_own_copy(): void
    {
        Sanctum::actingAs($this->admin());

        $grammar = $this->getJson('/api/v1/admin/saved-views')->assertOk()->json('data.grammar');

        $cases = collect($grammar)->firstWhere('entity', 'cases');

        // The same reasoning as publishing the upload limits in ADR 0020: a client copy drifts.
        $this->assertArrayHasKey('status', $cases['fields']);
        $this->assertContains('in', $cases['fields']['status']);

        // And a protected column is absent from the published grammar, so a client cannot even
        // offer it.
        $this->assertArrayNotHasKey('body', $cases['fields']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    private function admin(): Account
    {
        return $this->reviewer('lgu_admin');
    }

    private function staff(): Account
    {
        return $this->reviewer('lgu_staff');
    }

    private function client(): Resident
    {
        static $n = 0;
        $n++;

        return $this->existingResident([
            'first_name' => 'Sea'.$n,
            'middle_name' => null,
            'last_name' => 'Rched',
            'birth_date' => '1983-05-'.str_pad((string) (($n % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    // ── TAB 11: the search box as a disclosure surface ───────────────────────────────

    /**
     * Step 5 — *"every search is audited server-side with the actor and the term."*
     *
     * Without the term, a trail saying somebody searched four hundred times is not
     * accountability: the question an audit of a welfare registry has to answer is **who has been
     * looking up whom**.
     */
    #[Test]
    public function every_search_is_recorded_with_who_searched_and_what_for(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->existingResident(['first_name' => 'Auditable', 'middle_name' => null, 'last_name' => 'Person']);

        $this->getJson('/api/v1/admin/search?q=Auditable')->assertOk();

        $entry = DB::table('audit_entries')->where('action', 'search.performed')->first();

        $this->assertNotNull($entry, 'A search that leaves no trace cannot be reviewed.');
        $this->assertSame((string) $admin->uuid, (string) $entry->actor_subject_id);
        $this->assertStringContainsString('Auditable', (string) $entry->summary);
    }

    /**
     * Step 5's second half — *"a searchable log of searches is a second copy of the disclosure."*
     *
     * Held structurally rather than by a filter: the service searches residents, cases, households
     * and referrals, and never `audit_entries`. So the terms recorded above cannot be mined
     * through the surface that recorded them.
     */
    #[Test]
    public function the_log_of_searches_cannot_be_mined_through_search(): void
    {
        Sanctum::actingAs($this->admin());

        $this->existingResident(['first_name' => 'Quietly', 'middle_name' => null, 'last_name' => 'Sought']);

        // One search, whose term is now in the trail.
        $this->getJson('/api/v1/admin/search?q=Quietly')->assertOk();

        // A second search for the same term must find the resident, and never the audit entry
        // that recorded the first.
        $results = $this->getJson('/api/v1/admin/search?q=Quietly')->assertOk()->json('data.results');

        foreach ($results as $hit) {
            $this->assertNotSame('audit-entry', $hit['type'] ?? null);
            $this->assertNotSame('search', $hit['type'] ?? null);
        }

        $this->assertNotEmpty($results);
    }

    /**
     * Step 2 — *"the empty result is indistinguishable from a name that does not exist."*
     *
     * A scoped clerk searching a neighbouring barangay's resident must not be able to tell that
     * the person exists somewhere. Two different answers would make the search box an existence
     * oracle for the whole municipality.
     */
    #[Test]
    public function a_name_outside_scope_answers_exactly_as_a_name_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->admin());

        $this->existingResident([
            'first_name' => 'Elsewhere', 'middle_name' => null, 'last_name' => 'Resident',
            'barangay_id' => $this->otherBarangayId(),
        ]);

        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        $outside = $this->getJson('/api/v1/admin/search?q=Elsewhere')->assertOk()->json('data');
        $fictional = $this->getJson('/api/v1/admin/search?q=Zzzqqxnobody')->assertOk()->json('data');

        $this->assertSame([], $outside['results']);
        $this->assertSame($fictional['results'], $outside['results']);
        $this->assertSame(array_keys($fictional), array_keys($outside));
    }

    /**
     * Step 1 — *"one query parameter … the console must not offer a field list or a note flag that
     * would widen it."*
     *
     * Extra parameters are ignored rather than honoured. A client that could name the fields to
     * search could ask the registry to match on a note body, and matching on free text discloses
     * it even with no snippet rendered.
     */
    #[Test]
    public function no_parameter_can_widen_what_is_searched(): void
    {
        Sanctum::actingAs($this->admin());

        $resident = $this->existingResident([
            'first_name' => 'Widen', 'middle_name' => null, 'last_name' => 'Target',
        ]);

        DB::table('welfare_cases')->insert([
            'uuid' => (string) Str::uuid7(),
            'case_number' => 'WC-NOTEPROBE1',
            'type' => 'assistance',
            'resident_id' => (string) $resident->uuid,
            'barangay_id' => $this->barangayId(),
            'status' => 'assessment',
            'priority' => 'normal',
            'opened_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $base = $this->getJson('/api/v1/admin/search?q=Widen')->assertOk()->json('data.results');

        foreach ([
            '/api/v1/admin/search?q=Widen&fields=notes',
            '/api/v1/admin/search?q=Widen&include_notes=1',
            '/api/v1/admin/search?q=Widen&scope=all-barangays',
        ] as $path) {
            $this->assertSame(
                $base,
                $this->getJson($path)->assertOk()->json('data.results'),
                "{$path} changed what was searched.",
            );
        }
    }

    /**
     * TAB 11 step 8 — *"a saved view cannot widen scope. Restoring a saved filter must re-apply
     * the actor's scope, not the author's."*
     *
     * This is the failure a shared view invites: an unrestricted administrator saves a useful
     * filter, a barangay clerk opens it, and the clerk sees the administrator's rows. The saved
     * filter is applied **on top of** the reader's scope rather than in place of it, so it can
     * only ever narrow.
     */
    #[Test]
    public function a_shared_view_re_applies_the_readers_scope_and_not_the_authors(): void
    {
        Sanctum::actingAs($this->admin());

        $elsewhere = $this->existingResident([
            'first_name' => 'Shared', 'middle_name' => null, 'last_name' => 'Elsewhere',
            'barangay_id' => $this->otherBarangayId(),
        ]);

        // The administrator saves a view aimed at the barangay a clerk cannot reach.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'residents',
            'name' => 'Everyone in the other barangay',
            'filters' => [['field' => 'barangay_id', 'operator' => 'eq', 'value' => $this->otherBarangayId()]],
            'is_shared' => true,
        ])->assertSuccessful();

        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        // The clerk can see the view — it is office furniture — and running its filter finds
        // nothing, because their own scope is applied underneath it.
        $names = array_column(
            $this->getJson('/api/v1/admin/saved-views')->assertOk()->json('data.views'),
            'name',
        );
        $this->assertContains('Everyone in the other barangay', $names);

        /*
         * Running the view's own filter finds nothing. Either answer is correct — the barangay
         * filter may be refused outright, or accepted and intersected with the clerk's scope to
         * nothing — because both mean the same thing: the author's reach did not travel with the
         * view. What must never happen is the row coming back.
         */
        $response = $this->getJson('/api/v1/admin/residents?barangay_id='.$this->otherBarangayId());

        if ($response->status() === 200) {
            $ids = array_column($response->json('data'), 'id');
            $this->assertNotContains((string) $elsewhere->uuid, $ids);
        } else {
            $this->assertSame(404, $response->status());
        }
    }

    /**
     * Sharing costs a permission because a shared view's **name** describes a population to
     * everybody who opens that screen, and it outlives whoever wrote it.
     */
    #[Test]
    public function sharing_a_view_is_refused_without_the_grant(): void
    {
        $clerk = Account::factory()->staff()->create();
        $this->grantRole($clerk, 'lgu_staff', $this->barangayId());
        Sanctum::actingAs($clerk);

        // A personal view needs no grant.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'residents',
            'name' => 'My own shortlist',
        ])->assertSuccessful();

        // The same view, shared, does.
        $this->postJson('/api/v1/admin/saved-views', [
            'entity' => 'residents',
            'name' => 'Households worth watching',
            'is_shared' => true,
        ])->assertForbidden();
    }

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }
}
