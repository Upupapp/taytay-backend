<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Reporting\Application\MetricsService;
use Modules\ServiceCatalog\Application\ProgramCatalog;
use Modules\ServiceCatalog\Domain\EligibilityFact;
use Modules\ServiceCatalog\Domain\PublicationStatus;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramEligibilityCriterion;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirementDocument;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The programme catalogue: public browsing and staff authoring (ADR 0018).
 *
 * The public and staff listings use the SAME controller and the same application service, as
 * the service catalogue already does. The only difference is which query the caller's
 * permissions unlock — never which URL they used (ADR 0002).
 *
 * THE CITIZEN PROJECTION CARRIES NO GUIDANCE CRITERIA. An applicant is told the requirements
 * they must bring and the plain-language conditions, but not the comparator, the threshold or
 * the blocking flag. Publishing the exact numbers would turn an assistance programme into a
 * form to be gamed, and the people who would game it successfully are not the ones it exists
 * for.
 */
final class ProgramController
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly ProgramCatalog $catalog,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The public catalogue. No authentication.
     *
     * Filtered at the query on published AND citizen-visible, so an internal referral programme
     * is absent from the rows and from the pagination total alike.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $pagination = PaginationParams::fromRequest($request);

        $query = $this->authorization->allows($actor, Permission::ProgramView)
            ? $this->catalog->adminQuery()
            : $this->catalog->publicQuery();

        if ($request->boolean('accepting_only')) {
            $query->whereNotNull('id');
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        $staff = $this->authorization->allows($actor, Permission::ProgramView);

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Program $program): array => $staff
                ? $this->staffProjection($program)
                : $this->citizenProjection($program),
        );
    }

    public function show(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $staff = $this->authorization->allows($actor, Permission::ProgramView);

        $model = $this->catalog->findByUuid($program);

        // An unpublished programme is NOT FOUND to the public, never FORBIDDEN: a 403 would
        // confirm that a programme the LGU has not announced exists.
        if ($model === null || (! $staff && ! $model->isPubliclyVisible())) {
            throw ResourceNotFoundException::make('That programme was not found.');
        }

        return ApiResponse::item($staff
            ? $this->staffDetail($model)
            : $this->citizenDetail($model));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $validated = $request->validate($this->programRules() + [
            'code' => ['required', 'string', 'max:32', 'unique:programs,code'],
            'name' => ['required', 'string', 'max:191'],
            'owner_office' => ['required', 'string', 'max:96'],
            'service_type' => ['required', 'string', 'max:48'],
            'benefit_type' => ['required', 'string', 'max:32'],
        ]);

        return ApiResponse::created($this->staffDetail($this->catalog->create($validated, $actor)));
    }

    public function update(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $validated = $request->validate($this->programRules() + [
            'name' => ['sometimes', 'string', 'max:191'],
            'owner_office' => ['sometimes', 'string', 'max:96'],
        ]);

        return ApiResponse::item($this->staffDetail($this->catalog->update($model, $validated, $actor)));
    }

    public function changeStatus(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:draft,published,retired'],
        ]);

        return ApiResponse::item($this->staffDetail(
            $this->catalog->changeStatus($model, PublicationStatus::from($validated['status']), $actor),
        ));
    }

    /**
     * Every requirement template a programme has published, newest version first (TAB 07).
     *
     * The read side of `storeRequirement` below, which had none. A write with no read is how a
     * catalogue silently accumulates duplicates — an officer republishes a requirement, cannot see
     * what the previous wording was, and the office loses the ability to say what it asked an
     * applicant for last March.
     *
     * Grouped by version rather than flattened, because the superseded wording **is** the
     * evidence: a request approved under version 1 has to stay explicable after version 2 is
     * published, which is the same reason a replaced document keeps its old version.
     *
     * `program.manage` rather than `program.view`. The current requirements are already on the
     * programme detail for anyone who may read the catalogue; the *history* of what the office
     * used to demand is administration of the catalogue, not use of it.
     */
    public function requirementTemplates(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $current = $this->catalog->currentRequirements($model)
            ->map(static fn (ProgramRequirement $r): string => $r->code.'@'.$r->template_version)
            ->all();

        $templates = $this->catalog->requirementsFor($model)
            ->groupBy('template_version')
            ->map(fn (Collection $requirements, string $version): array => [
                'template_version' => (string) $version,
                'requirements' => $requirements
                    ->map(fn (ProgramRequirement $r): array => $this->requirementProjection($r) + [
                        // Named per requirement, not per version: a version can be current for one
                        // code and superseded for another, because each is republished separately.
                        'is_current' => in_array($r->code.'@'.$r->template_version, $current, true),
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortKeysDesc()
            ->values()
            ->all();

        return ApiResponse::page(Page::fromArray($templates, PaginationParams::fromRequest($request)));
    }

    /**
     * What one programme has actually delivered (TAB 07).
     *
     * Answers *"is this programme reaching anybody"*, which is the question an office is asked
     * about a programme it is still funding.
     *
     * Runs the **same metric** as the reporting module rather than counting again here. A second
     * implementation of "how much has gone out" is a second answer, and the two disagree the first
     * time one of them forgets that an in-kind release has no peso value. It therefore inherits
     * small-cell suppression unchanged: a programme that reached fewer than the minimum is
     * withheld, because "1 recipient" plus a programme name is an identification.
     */
    public function utilization(Request $request, ActorContext $actor, ?string $program = null): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramView);

        $filters = [];

        if ($program !== null) {
            $filters['program_id'] = $this->programOrFail($program)->uuid;
        }

        return ApiResponse::item([
            'program_id' => $filters['program_id'] ?? null,
            'rows' => $this->metrics->programUtilization($actor, $filters),
        ], meta: [
            'suppression' => [
                'minimum_cell' => MetricsService::MINIMUM_CELL,
                'note' => 'Counts below the minimum are withheld so a small cell cannot identify a household.',
                'method' => 'withheld',
            ],
        ]);
    }

    /**
     * Adds a requirement.
     */
    public function storeRequirement(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'label' => ['required', 'string', 'max:191'],
            'obligation' => ['required', 'string', 'in:required,optional,conditional'],
            'condition_note' => ['nullable', 'string', 'max:255'],
            // Mandatory for anything a citizen must produce. A requirement with no instructions
            // sends somebody to a counter to be told what they should have brought.
            'citizen_instructions' => ['required', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'accepted_documents' => ['sometimes', 'array'],
            'accepted_documents.*' => ['string', 'max:48'],
        ]);

        /*
         * REPUBLISHING APPENDS A VERSION. It used to write `'1'` unconditionally, which made the
         * table's own unique key — (program_id, code, template_version) — refuse the second
         * publication of any requirement. The effect was that a requirement could be created once
         * and **never amended**: an office that worded one badly had no way to correct it, and the
         * versioning the schema already supported was unreachable through the API.
         *
         * Found by TAB 07 while building the read side. Appending rather than updating is the
         * point: a request approved in March under the old wording has to stay explicable in
         * December, and an overwrite destroys the evidence of what was actually asked for.
         */
        $nextVersion = (int) (ProgramRequirement::query()
            ->where('program_id', $model->id)
            ->where('code', $validated['code'])
            ->max('template_version') ?? 0) + 1;

        $requirement = ProgramRequirement::query()->create([
            'program_id' => $model->id,
            'code' => $validated['code'],
            'label' => $validated['label'],
            'obligation' => $validated['obligation'],
            'condition_note' => $validated['condition_note'] ?? null,
            'citizen_instructions' => $validated['citizen_instructions'],
            'display_order' => $validated['display_order'] ?? 0,
            'template_version' => (string) $nextVersion,
        ]);

        foreach ($validated['accepted_documents'] ?? [] as $documentType) {
            ProgramRequirementDocument::query()->create([
                'program_requirement_id' => $requirement->id,
                'document_type' => $documentType,
            ]);
        }

        return ApiResponse::created($this->requirementProjection($requirement));
    }

    /**
     * Adds an eligibility criterion to the current guidance version.
     */
    public function storeCriterion(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'fact' => ['required', 'string', 'in:'.implode(',', EligibilityFact::values())],
            'comparator' => ['required', 'string', 'in:at-least,at-most,between,is,is-one-of'],
            'value' => ['nullable', 'string', 'max:191'],
            'value_max' => ['nullable', 'string', 'max:191'],
            /*
             * Mandatory, and this is the control that keeps the engine out of "opaque denial"
             * territory. A criterion nobody can explain to the person it excludes is the opaque
             * denial itself, so there is no way to store one.
             */
            'citizen_explanation' => ['required', 'string', 'max:255'],
            'is_blocking' => ['sometimes', 'boolean'],
        ]);

        $fact = EligibilityFact::from($validated['fact']);

        if (! in_array($validated['comparator'], $fact->supportedComparators(), true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "`{$validated['comparator']}` cannot be applied to `{$fact->value}`.",
            );
        }

        $criterion = ProgramEligibilityCriterion::query()->create([
            'program_id' => $model->id,
            'code' => $validated['code'],
            'fact' => $fact,
            'comparator' => $validated['comparator'],
            'value' => $validated['value'] ?? null,
            'value_max' => $validated['value_max'] ?? null,
            'citizen_explanation' => $validated['citizen_explanation'],
            'is_blocking' => (bool) ($validated['is_blocking'] ?? false),
            'guidance_version' => $model->eligibility_guidance_version,
        ]);

        return ApiResponse::created($this->criterionProjection($criterion));
    }

    /**
     * Opens a new guidance version, copying the current criteria forward.
     */
    public function publishGuidanceVersion(Request $request, ActorContext $actor, string $program): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProgramManage);

        $model = $this->programOrFail($program);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:32'],
        ]);

        return ApiResponse::item($this->staffDetail(
            $this->catalog->publishGuidanceVersion($model, $validated['version'], $actor),
        ));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function citizenProjection(Program $program): array
    {
        return [
            'id' => $program->uuid,
            'code' => $program->code,
            'name' => $program->name,
            'description' => $program->description,
            'owner_office' => $program->owner_office,
            'target_population' => $program->target_population,
            'benefit_type' => $program->benefit_type,
            'accepts_applications' => $program->acceptsApplicationsNow(),
            'applications_close_at' => $program->applications_close_at?->toIso8601ZuluString(),
            // Told plainly, because an applicant deciding whether to travel to an office
            // deserves to know the LGU does not control the answer.
            'decided_by' => $program->isLocallyDetermined() ? 'lgu' : $program->authority,
            'turnaround_target_days' => $program->turnaround_target_days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function citizenDetail(Program $program): array
    {
        return $this->citizenProjection($program) + [
            'requirements' => $this->catalog->currentRequirements($program)
                ->map(fn (ProgramRequirement $requirement): array => [
                    'code' => $requirement->code,
                    'label' => $requirement->label,
                    'obligation' => $requirement->obligation,
                    'condition_note' => $requirement->condition_note,
                    'instructions' => $requirement->citizen_instructions,
                    'accepted_documents' => $requirement->acceptedDocuments()->pluck('document_type')->all(),
                ])->all(),
            /*
             * The conditions in words, and nothing else.
             *
             * No comparator, no threshold, no blocking flag. Publishing the exact numbers would
             * turn an assistance programme into a form to be gamed, and the people who would
             * game it successfully are not the ones it exists for.
             */
            'conditions' => $this->catalog->currentCriteria($program)
                ->map(fn (ProgramEligibilityCriterion $criterion): string => (string) $criterion->citizen_explanation)
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffProjection(Program $program): array
    {
        return $this->citizenProjection($program) + [
            'status' => $program->status->value,
            'is_citizen_visible' => (bool) $program->is_citizen_visible,
            'authority' => $program->authority,
            'funding_source_label' => $program->funding_source_label,
            'active_from' => $program->active_from?->toDateString(),
            'active_to' => $program->active_to?->toDateString(),
            'eligibility_guidance_version' => $program->eligibility_guidance_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffDetail(Program $program): array
    {
        return $this->staffProjection($program) + [
            'requirements' => $this->catalog->currentRequirements($program)
                ->map(fn (ProgramRequirement $r): array => $this->requirementProjection($r))->all(),
            'criteria' => $this->catalog->currentCriteria($program)
                ->map(fn (ProgramEligibilityCriterion $c): array => $this->criterionProjection($c))->all(),
            'intake_channels' => $program->intakeChannels()->pluck('channel')->all(),
            'approver_roles' => $program->approvers()->pluck('role')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementProjection(ProgramRequirement $requirement): array
    {
        return [
            'id' => $requirement->uuid,
            'code' => $requirement->code,
            'label' => $requirement->label,
            'obligation' => $requirement->obligation,
            'condition_note' => $requirement->condition_note,
            'citizen_instructions' => $requirement->citizen_instructions,
            'template_version' => $requirement->template_version,
            'display_order' => $requirement->display_order,
            'accepted_documents' => $requirement->acceptedDocuments()->pluck('document_type')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criterionProjection(ProgramEligibilityCriterion $criterion): array
    {
        return [
            'id' => $criterion->uuid,
            'code' => $criterion->code,
            'fact' => $criterion->fact->value,
            'comparator' => $criterion->comparator,
            'value' => $criterion->value,
            'value_max' => $criterion->value_max,
            'citizen_explanation' => $criterion->citizen_explanation,
            'is_blocking' => (bool) $criterion->is_blocking,
            'guidance_version' => $criterion->guidance_version,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function programRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'funding_source_label' => ['sometimes', 'nullable', 'string', 'max:96'],
            'target_population' => ['sometimes', 'nullable', 'string', 'max:191'],
            'authority' => ['sometimes', 'string', 'in:local,national,partner'],
            'active_from' => ['sometimes', 'nullable', 'date'],
            'active_to' => ['sometimes', 'nullable', 'date'],
            'applications_open_at' => ['sometimes', 'nullable', 'date'],
            'applications_close_at' => ['sometimes', 'nullable', 'date'],
            'turnaround_target_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'is_citizen_visible' => ['sometimes', 'boolean'],
            'service_type' => ['sometimes', 'string', 'max:48'],
            'benefit_type' => ['sometimes', 'string', 'max:32'],
        ];
    }

    private function programOrFail(string $uuid): Program
    {
        $program = $this->catalog->findByUuid($uuid);

        if ($program === null) {
            throw ResourceNotFoundException::make('That programme was not found.');
        }

        return $program;
    }
}
