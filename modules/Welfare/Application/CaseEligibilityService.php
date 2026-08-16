<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ServiceCatalog\Application\EligibilityGuidance;
use Modules\ServiceCatalog\Application\ProgramCatalog;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Infrastructure\Eloquent\CaseEligibilityCheck;
use Modules\Welfare\Infrastructure\Eloquent\CaseEligibilityResult;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Running a programme's guidance against a case, and keeping the result (ADR 0018 §6).
 *
 * THE POINT OF PERSISTING THIS is the acceptance criterion "the eligibility guidance version
 * used in a case is retained for audit". A check is a snapshot of an opinion formed at a
 * moment, against rules that will change: pinning the version and every criterion outcome is
 * what lets somebody defend — or overturn — a decision two years later.
 *
 * This is also the snapshot pinning ADR 0015 §3 deferred. It lands here rather than in TAB 10
 * because here it finally has a caller and a thing to justify.
 *
 * A CHECK DECIDES NOTHING. It writes a row and returns an advisory outcome. No status moves, no
 * priority changes, no case is refused. Refusal is `CaseStatus::Rejected`, which needs
 * `request.reject`, a mandatory reason and a human (ADR 0016).
 */
final class CaseEligibilityService
{
    public function __construct(
        private readonly ProgramCatalog $programs,
        private readonly EligibilityGuidance $guidance,
        private readonly ResidentDirectory $residents,
        private readonly CaseTimeline $timeline,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Evaluates a case against a programme and records the result.
     */
    public function check(WelfareCase $case, string $programUuid, ActorContext $actor): CaseEligibilityCheck
    {
        return DB::transaction(function () use ($case, $programUuid, $actor): CaseEligibilityCheck {
            $program = $this->programs->findByUuid($programUuid);

            if ($program === null) {
                throw ResourceNotFoundException::make('That programme was not found.');
            }

            /*
             * ResidentProfile decides what may be read for eligibility, not this module.
             *
             * A caller who cannot see income simply gets no income fact, the criterion resolves
             * to `unknown`, and the outcome degrades to `needs-review` — a human looks. That is
             * the right failure, and it happens without this service knowing anything about
             * what it was not shown.
             */
            $facts = $this->residents->eligibilityFactsFor((string) $case->resident_id);

            $evaluation = $this->guidance->evaluate($program, $facts);

            $check = CaseEligibilityCheck::query()->create([
                'welfare_case_id' => $case->id,
                'program_id' => (string) $program->uuid,
                'program_code' => (string) $program->code,
                // Pinned. The whole reason this table exists.
                'guidance_version' => $evaluation['guidance_version'],
                'outcome' => $evaluation['outcome']->value,
                'evaluated_by' => $actor->subjectId,
                'evaluated_at' => now(),
            ]);

            foreach ($evaluation['results'] as $result) {
                CaseEligibilityResult::query()->create([
                    'welfare_case_eligibility_check_id' => $check->id,
                    'criterion_code' => $result['criterion_code'],
                    'fact' => $result['fact'],
                    'result' => $result['result'],
                    'explanation' => $result['explanation'],
                    'observed_value' => $result['observed_value'],
                    'is_blocking' => $result['is_blocking'],
                ]);
            }

            $this->timeline->record(
                $case,
                'eligibility.checked',
                "Eligibility guidance run for {$program->code} ({$evaluation['outcome']->value}, guidance {$evaluation['guidance_version']})",
                /*
                 * Staff-only, deliberately.
                 *
                 * Telling an applicant "you are likely ineligible" mid-case reads as a refusal
                 * however it is worded, and it is not one — nobody has decided anything. They
                 * hear the outcome when a person with authority makes it, and can act on that.
                 */
                null,
                false,
                $actor,
            );

            $this->audit->record(
                $actor->subjectId,
                'eligibility.checked',
                "Eligibility guidance evaluated against {$program->code}",
                (string) $case->uuid,
            );

            return $check->refresh();
        });
    }

    /**
     * Every check ever run on a case, newest first.
     *
     * @return Collection<int, CaseEligibilityCheck>
     */
    public function historyFor(WelfareCase $case): Collection
    {
        return CaseEligibilityCheck::query()
            ->where('welfare_case_id', $case->id)
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->get();
    }
}
