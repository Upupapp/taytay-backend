<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ServiceCatalog\Contracts\ProgramSummary;
use Modules\ServiceCatalog\Domain\PublicationStatus;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramEligibilityCriterion;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramRequirement;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Programme authoring and lookup (ADR 0018).
 *
 * POLICY CHANGES ARE ROWS, NOT DEPLOYS. That is the acceptance criterion for this TAB —
 * "policy/config updates do not require rewriting controllers" — and it is why programmes are a
 * table where the vulnerability ruleset and the assessment forms are config. An MSWDO officer
 * opens a relief programme on Tuesday because a storm landed on Monday; a config deploy is the
 * wrong instrument for that, and would make disaster response wait on a developer.
 *
 * BUMPING GUIDANCE IS EXPLICIT. Editing criteria under a published version would rewrite the
 * rules a past decision was made against. `publishGuidanceVersion()` copies the criteria
 * forward, so old checks keep resolving to the rules that actually applied to them.
 */
final class ProgramCatalog
{
    /**
     * The public catalogue.
     *
     * Filtered at the query on both conditions. A programme that is published but not
     * citizen-visible — an internal referral route, say — must not appear, and the pagination
     * total must not count it either.
     *
     * @return Builder<Program>
     */
    public function publicQuery(): Builder
    {
        return Program::query()
            ->where('status', PublicationStatus::Published->value)
            ->where('is_citizen_visible', true)
            ->orderBy('name');
    }

    /**
     * The staff catalogue: everything, including drafts and retired programmes.
     *
     * @return Builder<Program>
     */
    public function adminQuery(): Builder
    {
        return Program::query()->orderBy('name');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ActorContext $actor): Program
    {
        return DB::transaction(function () use ($attributes): Program {
            return Program::query()->create($attributes + [
                // A programme starts as a draft and invisible. Publishing and exposing are two
                // separate deliberate acts, and neither should be a side effect of creation.
                'status' => PublicationStatus::Draft,
                'is_citizen_visible' => false,
                'eligibility_guidance_version' => '1',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Program $program, array $changes, ActorContext $actor): Program
    {
        $program->fill($changes);
        $program->save();

        return $program->refresh();
    }

    /**
     * Publishes, retires, or returns a programme to draft.
     */
    public function changeStatus(Program $program, PublicationStatus $status, ActorContext $actor): Program
    {
        if ($status === PublicationStatus::Published && $program->requirements()->count() === 0) {
            /*
             * A published programme with no requirements tells an applicant to bring nothing,
             * and then the office asks for documents at the counter. That is the commonest way
             * a person makes two trips they cannot afford.
             */
            throw new ApiException(
                ErrorCode::Conflict,
                'A programme cannot be published before its requirements are defined.',
            );
        }

        $program->forceFill(['status' => $status])->save();

        return $program->refresh();
    }

    /**
     * Opens a new guidance version by copying the current criteria forward.
     *
     * Editing criteria in place under a published version would rewrite the rules a past
     * decision was made against — and the check rows pinned to that version would then resolve
     * to criteria that never applied to them. Copying forward keeps history honest and makes
     * the change visible as an act (ADR 0018 §6).
     */
    public function publishGuidanceVersion(Program $program, string $version, ActorContext $actor): Program
    {
        return DB::transaction(function () use ($program, $version): Program {
            /** @var Program $program */
            $program = Program::query()->lockForUpdate()->findOrFail($program->id);

            if ((string) $program->eligibility_guidance_version === $version) {
                throw new ApiException(ErrorCode::Conflict, 'That guidance version is already current.');
            }

            $existing = ProgramEligibilityCriterion::query()
                ->where('program_id', $program->id)
                ->where('guidance_version', $program->eligibility_guidance_version)
                ->get();

            foreach ($existing as $criterion) {
                ProgramEligibilityCriterion::query()->create([
                    'program_id' => $program->id,
                    'code' => $criterion->code,
                    'fact' => $criterion->fact,
                    'comparator' => $criterion->comparator,
                    'value' => $criterion->value,
                    'value_max' => $criterion->value_max,
                    'citizen_explanation' => $criterion->citizen_explanation,
                    'is_blocking' => $criterion->is_blocking,
                    'guidance_version' => $version,
                ]);
            }

            $program->forceFill(['eligibility_guidance_version' => $version])->save();

            return $program->refresh();
        });
    }

    /**
     * The requirements a programme currently asks for.
     *
     * @return Collection<int, ProgramRequirement>
     */
    public function currentRequirements(Program $program): Collection
    {
        return ProgramRequirement::query()
            ->where('program_id', $program->id)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ProgramEligibilityCriterion>
     */
    public function currentCriteria(Program $program): Collection
    {
        return ProgramEligibilityCriterion::query()
            ->where('program_id', $program->id)
            ->where('guidance_version', $program->eligibility_guidance_version)
            ->orderBy('id')
            ->get();
    }

    /**
     * The published cross-module view.
     */
    public function summaryFor(string $programUuid): ?ProgramSummary
    {
        /** @var Program|null $program */
        $program = Program::query()->where('uuid', $programUuid)->first();

        if ($program === null) {
            return null;
        }

        return new ProgramSummary(
            id: (string) $program->uuid,
            code: (string) $program->code,
            name: (string) $program->name,
            acceptsApplications: $program->acceptsApplicationsNow(),
            locallyDetermined: $program->isLocallyDetermined(),
            guidanceVersion: (string) $program->eligibility_guidance_version,
        );
    }

    public function findByUuid(string $uuid): ?Program
    {
        /** @var Program|null $program */
        $program = Program::query()->where('uuid', $uuid)->first();

        return $program;
    }
}
