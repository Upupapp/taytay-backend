<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ServiceCatalog\Application\ProgramCatalog;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Domain\EnrollmentStatus;
use Modules\Welfare\Infrastructure\Eloquent\ProgramEnrollment;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Putting a beneficiary on a programme's roll, and taking them off (ADR 0019).
 *
 * THE INVARIANT: **at most one open enrolment per programme per resident.** Two would mean the
 * same person counted twice in every roll, every distribution manifest and every payment run —
 * which is double payment, arriving quietly and from a direction nobody watches.
 *
 * It is enforced in two places on purpose. This service returns the existing row rather than
 * opening a second, so a double-tapped "enrol" is harmless; and the database refuses the second
 * open row outright, through the derived `open_key` column, so a future write path that forgets
 * to ask cannot create one either. The service is the good manners; the index is the guarantee.
 *
 * ENROLMENT IS NOT ELIGIBILITY AND NOT APPROVAL. Nothing here reads a guidance outcome, an
 * assessment recommendation or a vulnerability score. A person is enrolled because a human with
 * `enrollment.manage` decided to enrol them, and that decision is recorded against their name.
 * The chain of things that are deliberately not automatic now runs: guidance advises (ADR 0018),
 * an assessment recommends (ADR 0017), a case is approved (ADR 0016), and only then does
 * somebody enrol.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly ProgramCatalog $programs,
        private readonly CaseTimeline $timeline,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Enrols a beneficiary.
     *
     * Idempotent: an already-open enrolment on the same programme is returned rather than a
     * second one opened.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function enroll(
        string $programUuid,
        string $residentUuid,
        ActorContext $actor,
        array $attributes = [],
    ): ProgramEnrollment {
        return DB::transaction(function () use ($programUuid, $residentUuid, $actor, $attributes): ProgramEnrollment {
            $program = $this->programs->findByUuid($programUuid);

            if ($program === null) {
                throw ResourceNotFoundException::make('That programme was not found.');
            }

            /*
             * A retired programme takes no new enrolments.
             *
             * Existing enrolments survive — people already on a closed programme still have a
             * history, and removing them would erase what they received. Only the door closes.
             */
            if ($program->status->value === 'retired') {
                throw new ApiException(ErrorCode::Conflict, 'That programme is retired and accepts no new enrolments.');
            }

            /** @var ProgramEnrollment|null $open */
            $open = ProgramEnrollment::query()
                ->where('program_id', (string) $program->uuid)
                ->where('resident_id', $residentUuid)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                // The same person counted twice is double payment. Returning the existing row
                // makes a double-tapped "enrol" harmless.
                return $open;
            }

            $enrollment = ProgramEnrollment::query()->create([
                'program_id' => (string) $program->uuid,
                'program_code' => (string) $program->code,
                'resident_id' => $residentUuid,
                'household_id' => $attributes['household_id'] ?? null,
                'source_case_id' => $attributes['source_case_id'] ?? null,
                'status' => EnrollmentStatus::Active,
                'effective_from' => $attributes['effective_from'] ?? now()->toDateString(),
                'entry_reason' => $attributes['entry_reason'] ?? null,
                'note' => $attributes['note'] ?? null,
                'enrolled_by' => $actor->subjectId,
            ]);

            $this->recordOnSourceCase($enrollment, $actor, "Enrolled in {$program->code}", 'You have been enrolled in this programme.');

            $this->audit->record(
                $actor->subjectId,
                'enrollment.created',
                "Beneficiary enrolled in {$program->code}",
                $attributes['source_case_id'] ?? null,
            );

            return $enrollment;
        });
    }

    /**
     * Suspends or reactivates an enrolment without ending it.
     *
     * A household under review for a possible double claim is neither receiving nor removed,
     * and forcing that into exit-and-rejoin fills the roll with fragments every time somebody is
     * queried and cleared.
     */
    public function changeStatus(
        ProgramEnrollment $enrollment,
        EnrollmentStatus $status,
        ActorContext $actor,
        ?string $note,
    ): ProgramEnrollment {
        return DB::transaction(function () use ($enrollment, $status, $actor, $note): ProgramEnrollment {
            /** @var ProgramEnrollment $enrollment */
            $enrollment = ProgramEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            if (! $enrollment->status->canChange()) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That enrolment has ended. Enrol the beneficiary again rather than reviving it.',
                );
            }

            if ($status === EnrollmentStatus::Exited) {
                // Exit has its own method, because it needs a reason and closes the period.
                throw new ApiException(ErrorCode::BadRequest, 'Use the exit endpoint to end an enrolment.');
            }

            $enrollment->forceFill(['status' => $status, 'note' => $note ?? $enrollment->note])->save();

            $this->audit->record(
                $actor->subjectId,
                'enrollment.status-changed',
                "Enrolment {$status->value} for {$enrollment->program_code}",
                $enrollment->source_case_id,
            );

            return $enrollment->refresh();
        });
    }

    /**
     * Ends an enrolment.
     *
     * Closes the period; never deletes the row. "Was this household on the roll in October" must
     * stay answerable after they leave in November — which is exactly the question asked when
     * somebody alleges a payment went to a person who should not have had one.
     */
    public function exit(
        ProgramEnrollment $enrollment,
        ActorContext $actor,
        string $exitReason,
        ?string $effectiveTo = null,
    ): ProgramEnrollment {
        return DB::transaction(function () use ($enrollment, $actor, $exitReason, $effectiveTo): ProgramEnrollment {
            /** @var ProgramEnrollment $enrollment */
            $enrollment = ProgramEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            if (! $enrollment->isOpen()) {
                throw new ApiException(ErrorCode::Conflict, 'That enrolment has already ended.');
            }

            $enrollment->forceFill([
                'status' => EnrollmentStatus::Exited,
                'effective_to' => $this->closingDate($enrollment->effective_from, $effectiveTo),
                /*
                 * Required. "Graduated", "moved out of Taytay", "no longer eligible" and "found
                 * to be duplicate" are four completely different facts about a person, and an
                 * unexplained exit is indistinguishable from all of them later — including from
                 * an unauthorised removal.
                 */
                'exit_reason' => $exitReason,
                'exited_by' => $actor->subjectId,
            ])->save();

            $this->recordOnSourceCase(
                $enrollment,
                $actor,
                "Exited {$enrollment->program_code} ({$exitReason})",
                'Your enrolment in this programme has ended.',
            );

            $this->audit->record(
                $actor->subjectId,
                'enrollment.exited',
                "Enrolment ended for {$enrollment->program_code}",
                $enrollment->source_case_id,
            );

            return $enrollment->refresh();
        });
    }

    /**
     * The staff roll query. Returns a builder so filters and scope compose at the query.
     *
     * @return Builder<ProgramEnrollment>
     */
    public function query(): Builder
    {
        return ProgramEnrollment::query()->orderByDesc('effective_from')->orderByDesc('id');
    }

    /**
     * Every enrolment a resident has ever held, newest first.
     *
     * Includes exited ones — that is the acceptance criterion "historical enrolments remain
     * queryable after exit", and it is the only way to see that somebody was removed from a
     * programme and by whom.
     *
     * @return Collection<int, ProgramEnrollment>
     */
    public function forResident(string $residentUuid): Collection
    {
        return ProgramEnrollment::query()
            ->where('resident_id', $residentUuid)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Moves enrolments from an absorbed resident to the survivor of a merge.
     *
     * Called by the `ResidentMerged` listener. Overlaps are collapsed rather than carried
     * across: if both records were enrolled on the same programme, moving both would leave the
     * survivor with two open enrolments — the double-payment state this service exists to
     * prevent, arriving through the one path that bypasses `enroll()`.
     */
    public function reassignOnMerge(string $fromResidentUuid, string $toResidentUuid): int
    {
        if ($fromResidentUuid === $toResidentUuid) {
            return 0;
        }

        $survivorPrograms = ProgramEnrollment::query()
            ->where('resident_id', $toResidentUuid)
            ->whereNull('effective_to')
            ->pluck('program_id')
            ->all();

        $moved = 0;

        /*
         * Row by row, not a mass `update()`.
         *
         * The volume is one duplicate's enrolments, so the cost is nothing, and it buys two
         * things a bulk statement cannot have. Model events fire, so the derived `open_key` that
         * carries the one-open-enrolment invariant stays correct on the single path that does not
         * go through `enroll()`. And the closing date is computed per row, so an enrolment that
         * had not started yet is not given an end date before its own beginning — a period that
         * ends before it starts is excluded by every date query, which would erase the
         * beneficiary from history rather than from the roll.
         */
        foreach (ProgramEnrollment::query()->where('resident_id', $fromResidentUuid)->cursor() as $enrollment) {
            $attributes = ['resident_id' => $toResidentUuid];

            // Where the survivor already holds an open enrolment on this programme, the absorbed
            // one is closed as it moves: the survivor is already on that roll, and carrying both
            // across would count one person twice on every payment run.
            if ($enrollment->isOpen() && in_array($enrollment->program_id, $survivorPrograms, true)) {
                $attributes += [
                    'status' => EnrollmentStatus::Exited,
                    'effective_to' => $this->closingDate($enrollment->effective_from, null),
                    'exit_reason' => 'merged-duplicate-enrolment',
                ];
            }

            $enrollment->forceFill($attributes)->save();
            $moved++;
        }

        return $moved;
    }

    /**
     * Puts an enrolment event on the source case's timeline, when there is one.
     *
     * Through {@see CaseTimeline::record()} rather than writing the table, so every row passes
     * the same visibility decision (ADR 0016 §5).
     */
    private function recordOnSourceCase(
        ProgramEnrollment $enrollment,
        ActorContext $actor,
        string $summary,
        string $citizenMessage,
    ): void {
        if ($enrollment->source_case_id === null) {
            return;
        }

        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $enrollment->source_case_id)->first();

        if ($case === null) {
            return;
        }

        $this->timeline->record($case, 'enrollment.changed', $summary, $citizenMessage, true, $actor);
    }

    /**
     * Never before the enrolment began — a period that ended before it started is excluded by
     * every date query, so the beneficiary disappears from history rather than from the roll.
     */
    private function closingDate(mixed $effectiveFrom, ?string $requested): string
    {
        $start = $effectiveFrom instanceof Carbon ? $effectiveFrom : Carbon::parse((string) $effectiveFrom);
        $end = $requested === null ? Carbon::now() : Carbon::parse($requested);

        return $end->lt($start) ? $start->toDateString() : $end->toDateString();
    }
}
