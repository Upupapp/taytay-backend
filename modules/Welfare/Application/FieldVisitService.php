<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Welfare\Contracts\VisitFollowUpDue;
use Modules\Welfare\Domain\ObservationKind;
use Modules\Welfare\Domain\VisitStatus;
use Modules\Welfare\Infrastructure\Eloquent\FieldVisit;
use Modules\Welfare\Infrastructure\Eloquent\VisitChecklistItem;
use Modules\Welfare\Infrastructure\Eloquent\VisitObservation;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Scheduling field work and recording what was found (ADR 0022).
 *
 * NOTHING HERE RECORDS WHERE A WORKER WAS. The address visited is copied from the household
 * record at scheduling; there is no coordinate, no check-in and no device-taken arrival time, and
 * `NoLocationTrackingTest` fails the build if one appears.
 */
final class FieldVisitService
{
    public function __construct(
        private readonly ResidentDirectory $residents,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function schedule(array $attributes, ActorContext $actor): FieldVisit
    {
        $resident = $this->residents->summaryFor((string) $attributes['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $case = $this->resolveCase($attributes['case_id'] ?? null, $resident->id);

        $scheduledFor = Carbon::parse((string) $attributes['scheduled_for']);

        if ($scheduledFor->startOfDay()->isBefore(Carbon::now()->startOfDay())) {
            // A visit scheduled into the past is overdue the moment it is created, which puts a
            // worker in default for something they were never given time to do.
            throw new ApiException(ErrorCode::ValidationFailed, 'That date has already passed.');
        }

        return DB::transaction(function () use ($attributes, $actor, $resident, $case, $scheduledFor): FieldVisit {
            /** @var FieldVisit $visit */
            $visit = FieldVisit::query()->create([
                'resident_id' => $resident->id,
                'household_id' => $attributes['household_id'] ?? null,
                'welfare_case_id' => $case?->id,
                'status' => VisitStatus::Scheduled,
                'purpose' => (string) $attributes['purpose'],
                'assigned_to' => $attributes['assigned_to'] ?? $actor->subjectId,
                'scheduled_by' => $actor->subjectId,
                'scheduled_for' => $scheduledFor->toDateString(),
                'scheduled_window' => $attributes['scheduled_window'] ?? null,
                /*
                 * Copied, not referenced. A household that moves must not silently rewrite where
                 * a past visit was made — the record would then claim the worker went somewhere
                 * they did not.
                 */
                'address_visited' => (string) ($attributes['address_visited'] ?? $this->addressFor($resident->id)),
            ]);

            foreach ((array) ($attributes['checklist'] ?? []) as $item) {
                VisitChecklistItem::query()->create([
                    'field_visit_id' => $visit->id,
                    'code' => (string) $item['code'],
                    'label' => (string) $item['label'],
                    'checked' => false,
                ]);
            }

            $this->audit->record(
                $actor->subjectId,
                'visit.scheduled',
                'Field visit scheduled',
                $case === null ? null : (string) $case->uuid,
            );

            return $visit;
        });
    }

    /**
     * Records one observation, carrying whose claim it is.
     */
    public function observe(
        FieldVisit $visit,
        ObservationKind $kind,
        string $body,
        ?string $attributedTo,
        ActorContext $actor,
    ): VisitObservation {
        if (mb_strlen(trim($body)) < 8) {
            throw new ApiException(ErrorCode::ValidationFailed, 'An observation needs something in it.');
        }

        /*
         * "A neighbour said" with no neighbour named is a rumour the office cannot check and
         * cannot answer for — and it is the form in which a grudge enters a family's file.
         */
        if ($kind->needsAttribution() && trim((string) $attributedTo) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Name who said this.');
        }

        // And the inverse. An attribution on the worker's own observation would read as though
        // somebody else vouched for it.
        if (! $kind->needsAttribution() && trim((string) $attributedTo) !== '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Only something said by a third party carries an attribution.',
            );
        }

        /** @var VisitObservation $observation */
        $observation = VisitObservation::query()->create([
            'field_visit_id' => $visit->id,
            'kind' => $kind,
            'body' => $body,
            'attributed_to' => $kind->needsAttribution() ? $attributedTo : null,
            'recorded_by' => $actor->subjectId,
            'recorded_at' => now(),
        ]);

        return $observation;
    }

    /**
     * Ticks a checklist item.
     */
    public function check(FieldVisit $visit, string $code, bool $checked, ?string $note): VisitChecklistItem
    {
        /** @var VisitChecklistItem|null $item */
        $item = VisitChecklistItem::query()
            ->where('field_visit_id', $visit->id)
            ->where('code', $code)
            ->first();

        if ($item === null) {
            throw ResourceNotFoundException::make('That checklist item was not found.');
        }

        $item->forceFill(['checked' => $checked, 'note' => $note])->save();

        return $item->refresh();
    }

    /**
     * Closes a visit out.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function conclude(
        FieldVisit $visit,
        VisitStatus $status,
        array $attributes,
        ActorContext $actor,
    ): FieldVisit {
        return DB::transaction(function () use ($visit, $status, $attributes, $actor): FieldVisit {
            /** @var FieldVisit $visit */
            $visit = FieldVisit::query()->lockForUpdate()->findOrFail($visit->id);

            /*
             * Every outcome is terminal. A visit that happened, happened — a second attempt is a
             * second visit, so "how many times did we go?" keeps exactly one answer and a
             * household is never shown as visited once when a worker travelled three times.
             */
            if (! $visit->status->canMoveTo($status)) {
                throw InvalidStateTransitionException::between($visit->status->value, $status->value);
            }

            if ($status->requiresOutcome() && trim((string) ($attributes['outcome'] ?? '')) === '') {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'Record what was found before completing this visit.',
                );
            }

            $visit->forceFill([
                'status' => $status,
                'outcome' => $attributes['outcome'] ?? $visit->outcome,
                'service_needs' => $attributes['service_needs'] ?? $visit->service_needs,
                // Only meaningful when the household declined; recording one otherwise would
                // attribute a refusal to a family that was simply out.
                'declined_reason' => $status === VisitStatus::Refused
                    ? ($attributes['declined_reason'] ?? null)
                    : null,
                'next_action' => $attributes['next_action'] ?? null,
                'follow_up_on' => $attributes['follow_up_on'] ?? null,
                'completed_at' => $status->wasAttended() ? now() : null,
            ])->save();

            $this->audit->record(
                $actor->subjectId,
                'visit.'.$status->value,
                'Field visit recorded as '.$status->value,
                $visit->welfare_case_id === null
                    ? null
                    : (string) WelfareCase::query()->whereKey($visit->welfare_case_id)->value('uuid'),
            );

            /*
             * The follow-up seam.
             *
             * A next action with a date is work somebody owes. Announced rather than written into
             * a task table that does not exist yet — TAB 19 listens for this, and until then the
             * intention is recorded on the visit where a supervisor can see it.
             */
            if ($visit->follow_up_on !== null && trim((string) $visit->next_action) !== '') {
                Event::dispatch(new VisitFollowUpDue(
                    visitUuid: (string) $visit->uuid,
                    referenceNumber: (string) $visit->reference_number,
                    assignedToSubjectId: $visit->assigned_to,
                    dueOn: $visit->follow_up_on->toDateString(),
                    // The action, not the observations. What somebody must DO is safe to put in a
                    // task queue; what a family said is not (Article 8.4).
                    nextAction: (string) $visit->next_action,
                ));
            }

            return $visit->refresh();
        });
    }

    /**
     * @return Builder<FieldVisit>
     */
    public function query(): Builder
    {
        return FieldVisit::query();
    }

    /**
     * Visits still scheduled after their date.
     *
     * **The worker owes these, not the family.** Kept separate from the document-request queue
     * for that reason: a queue that mixes "we have not been yet" with "the applicant has not
     * brought their papers" tells a supervisor nothing about who needs help.
     *
     * @return Builder<FieldVisit>
     */
    public function overdueQuery(?Carbon $on = null): Builder
    {
        return FieldVisit::query()
            ->where('status', VisitStatus::Scheduled->value)
            ->whereDate('scheduled_for', '<', ($on ?? Carbon::now())->toDateString());
    }

    private function addressFor(string $residentUuid): string
    {
        $facts = $this->residents->disclosureFactsFor($residentUuid);

        // An address the office does not hold is recorded as such rather than as an empty string:
        // a blank address on a visit record reads as though nobody filled the form in.
        return $facts['address'] ?? 'Address not recorded';
    }

    private function resolveCase(mixed $caseUuid, string $residentUuid): ?WelfareCase
    {
        if ($caseUuid === null || $caseUuid === '') {
            /*
             * A visit may precede any case: a barangay reports a family and somebody goes to
             * look. Requiring a case would force a fictitious one, and a fictitious case distorts
             * every count built on cases afterwards.
             */
            return null;
        }

        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', (string) $caseUuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        if ((string) $case->resident_id !== $residentUuid) {
            throw new ApiException(ErrorCode::Conflict, 'That case belongs to a different resident.');
        }

        return $case;
    }
}
