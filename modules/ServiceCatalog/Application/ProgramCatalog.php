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
use Modules\Shared\Support\Identifier;

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
        /*
         * THE LATEST VERSION OF EACH REQUIREMENT, which is what this method has always claimed
         * and did not do.
         *
         * `program_requirements` is unique on (program_id, code, template_version), so republishing
         * a requirement writes a **second row** rather than updating the first — correctly, because
         * a request approved in March under version 1 must stay explicable in December, and an
         * overwrite destroys the evidence of what was actually asked for. See {@see DL-77} on the
         * console side for the same rule about document versions.
         *
         * The bug was in the read: with no version filter, a programme detail listed both rows and
         * showed the same requirement twice, one with the old wording. Found by TAB 07 while
         * building the versioned read below.
         */
        return $this->requirementsFor($program)
            ->groupBy('code')
            ->map(static fn (Collection $versions): ProgramRequirement => $versions
                ->sortBy(static fn (ProgramRequirement $r): int => (int) $r->template_version)
                ->last())
            ->sortBy([['display_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Every requirement ever published for a programme, all versions.
     *
     * The read side of `POST admin/programs/{program}/requirements`, which had none (TAB 07). A
     * write with no read is how a catalogue silently accumulates duplicates: an officer republishes
     * a requirement, cannot see what the previous wording was, and the office loses the ability to
     * say what it asked an applicant for last March.
     *
     * @return Collection<int, ProgramRequirement>
     */
    public function requirementsFor(Program $program): Collection
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

    /**
     * A programme by its public UUID, or null.
     *
     * **Null for anything that is not a UUID at all**, rather than a 500. `GET programs/{program}`
     * takes whatever is in the path, and a request for `programs/AICS` — a programme *code*, which
     * is what somebody would try — sent that straight into a `uuid` comparison. PostgreSQL refuses
     * it and the endpoint answered 500 where 404 is the truthful answer.
     *
     * Whether the route should also resolve a code is a separate question and deliberately not
     * decided here. What is decided is that a malformed identifier is *not found*, not a crash.
     */
    public function findByUuid(string $uuid): ?Program
    {
        if (! Identifier::isUuid($uuid)) {
            return null;
        }

        /** @var Program|null $program */
        $program = Program::query()->where('uuid', $uuid)->first();

        return $program;
    }
}
