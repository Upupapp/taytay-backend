<?php

declare(strict_types=1);

namespace Modules\Search\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Search\Application\GlobalSearch;
use Modules\Search\Domain\FilterGrammar;
use Modules\Search\Infrastructure\Eloquent\SavedView;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Staff search and saved views (ADR 0027).
 *
 * **THERE IS NO CITIZEN SEARCH ENDPOINT HERE, AND THAT IS THE POINT.** The master command is
 * explicit: a citizen does not receive global resident or case search, and their own records are
 * already reachable through `me/*` — which resolves the resident from the token and has no
 * identifier to tamper with.
 *
 * A citizen search endpoint that "only returned their own records" would be a resident-enumeration
 * endpoint one authorization bug away, and the bug would be invisible because the endpoint would
 * still look like it was working. The absence is the control (ADR 0027 §5).
 */
final class SearchController
{
    public function __construct(
        private readonly GlobalSearch $search,
        private readonly AuthorizationService $authorization,
    ) {}

    public function search(Request $request, ActorContext $actor): JsonResponse
    {
        /*
         * A staff permission gate on top of the per-entity gates inside the service. A citizen
         * account reaching this route is refused before any query runs — belt and braces, because
         * this is the one endpoint whose whole job is finding records.
         */
        $this->authorization->authorize($actor, Permission::RequestView);

        return ApiResponse::item(
            $this->search->search($actor, (string) $request->query('q', '')),
        );
    }

    // ── saved views ───────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $views = SavedView::query()
            ->where(function ($query) use ($actor): void {
                $query->where('owner_subject_id', (string) $actor->subjectId)
                    // Shared views are visible to any staff member who may use the entity. The
                    // FILTER is shared, never the results — see the projection note.
                    ->orWhere('is_shared', true);
            })
            ->orderBy('entity')
            ->orderBy('name')
            ->get();

        return ApiResponse::item([
            'views' => $views->map(fn (SavedView $view): array => $this->projection($view, $actor))->all(),
            /*
             * The grammar is published so a client builds its filter UI from the server's own
             * list rather than a copy that drifts — the same reasoning as publishing upload limits
             * in ADR 0020.
             */
            'grammar' => array_map(
                static fn (string $entity): array => ['entity' => $entity, 'fields' => FilterGrammar::fieldsFor($entity)],
                FilterGrammar::entities(),
            ),
        ]);
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $validated = $request->validate([
            'entity' => ['required', 'string', 'in:'.implode(',', FilterGrammar::entities())],
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['sometimes', 'array'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:48'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        /*
         * Sharing costs a permission. A shared view appears in every colleague's list, so it is a
         * small piece of the office's shared furniture rather than a personal preference — and one
         * badly-named shared view ("Suspicious households") is a judgement broadcast to everybody.
         */
        if ($validated['is_shared'] ?? false) {
            $this->authorization->authorize($actor, Permission::SavedViewShare);
        }

        /*
         * VALIDATED BEFORE IT IS STORED, not merely before it is run.
         *
         * A saved view is executed later, by whoever loads it. A filter checked only at execution
         * time is a stored query waiting for a code path that forgets — and the one that forgets
         * will be added in a hurry two years from now, by which point the row has been in the
         * database long enough to look trustworthy.
         */
        $filters = FilterGrammar::validate($validated['entity'], $validated['filters'] ?? []);

        /** @var SavedView $view */
        $view = SavedView::query()->create([
            'owner_subject_id' => (string) $actor->subjectId,
            'entity' => $validated['entity'],
            'name' => $validated['name'],
            'filters' => $filters,
            'columns' => $validated['columns'] ?? null,
            'sort' => $this->validatedSort($validated['entity'], $validated['sort'] ?? null),
            'is_shared' => (bool) ($validated['is_shared'] ?? false),
        ]);

        return ApiResponse::created($this->projection($view, $actor));
    }

    public function destroy(Request $request, ActorContext $actor, string $view): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        /** @var SavedView|null $model */
        $model = SavedView::query()
            ->where('uuid', $view)
            // Scoped to the caller's own views: a shared view is somebody else's to delete.
            ->where('owner_subject_id', (string) $actor->subjectId)
            ->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That view was not found.');
        }

        $model->delete();

        return ApiResponse::item(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(SavedView $view, ActorContext $actor): array
    {
        return [
            'id' => $view->uuid,
            'entity' => $view->entity,
            'name' => $view->name,
            'filters' => $view->filters,
            'columns' => $view->columns,
            'sort' => $view->sort,
            'is_shared' => (bool) $view->is_shared,
            'is_mine' => (string) $view->owner_subject_id === (string) $actor->subjectId,
            /*
             * Stated so nobody mistakes a shared view for shared data.
             *
             * Two people opening the same view see different rows, because each query is scoped
             * to whoever runs it. A view carrying its author's scope would be a way to hand
             * somebody a caseload they cannot otherwise reach.
             */
            'note' => 'A view saves a question. Results are always scoped to whoever runs it.',
        ];
    }

    /**
     * A sort is a field name with an optional `-` prefix, and the field must be filterable.
     *
     * Same defence as the filter grammar: the column is looked up in a closed table, never
     * interpolated. `sort=id;DROP TABLE` cannot become a column reference because it is not a key.
     */
    private function validatedSort(string $entity, ?string $sort): ?string
    {
        if ($sort === null || $sort === '') {
            return null;
        }

        $field = ltrim($sort, '-');

        if (! array_key_exists($field, FilterGrammar::fieldsFor($entity))) {
            throw new ApiException(ErrorCode::ValidationFailed, "`{$field}` is not a sortable field.");
        }

        return $sort;
    }
}
