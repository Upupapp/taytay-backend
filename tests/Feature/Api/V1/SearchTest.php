<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function caseFor(Resident $resident): string
    {
        return $this->postJson('/api/v1/admin/assistance-intakes', [
            'resident_id' => (string) $resident->uuid,
            'category' => 'food',
            'narrative' => 'Assistance needed.',
        ])->assertCreated()->json('data.case_id');
    }
}
