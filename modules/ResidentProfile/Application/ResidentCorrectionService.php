<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\CorrectableField;
use Modules\ResidentProfile\Contracts\CorrectionStatus;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionField;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionRequest;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * The controlled path by which a resident changes their own record (ADR 0013 §4).
 *
 * Two doors, and which one a field goes through is decided by {@see CorrectableField},
 * never by the caller:
 *
 *  * **Self-service** — address lines and contact details are applied immediately. A
 *    resident who has moved must be able to say so at 11pm without an appointment; if that
 *    is hard, the LGU's contact details rot and it stops being able to reach the people it
 *    is trying to help.
 *  * **Reviewed** — name, birth date, sex, civil status and barangay are *proposed*. These
 *    are the fields a reviewer checked against documents and the ones a fraudulent claim
 *    would target, so a human confirms them against evidence.
 *
 * RA 10173 §16(d) gives a data subject the right to have inaccurate personal data
 * corrected. This class is how that right is exercised without also becoming a way to
 * rewrite a verified identity.
 */
final class ResidentCorrectionService
{
    public function __construct(
        private readonly ResidentRegistry $registry,
        private readonly ResidentProfileAudit $audit,
    ) {}

    /**
     * A resident files a correction.
     *
     * Self-service fields are applied here and now, and are still recorded as an approved
     * request so that "who changed this address, and when" has one answer regardless of
     * which door the change came through.
     *
     * @param  array<string, mixed>  $proposed  keyed by {@see CorrectableField} value
     */
    public function request(
        Resident $resident,
        array $proposed,
        ActorContext $actor,
        ?string $note = null,
    ): ResidentCorrectionRequest {
        return DB::transaction(function () use ($resident, $proposed, $actor, $note): ResidentCorrectionRequest {
            if ($proposed === []) {
                throw new ApiException(ErrorCode::BadRequest, 'A correction request must propose at least one change.');
            }

            /*
             * One open request at a time. Two pending requests against the same record can
             * be approved in either order, and the second one applies values computed
             * against a record that has already moved — so the resident ends up with a
             * field they never asked for.
             */
            $open = ResidentCorrectionRequest::query()
                ->where('resident_id', $resident->id)
                ->where('status', CorrectionStatus::Pending->value)
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'You already have a correction request awaiting review.',
                );
            }

            $selfService = [];
            $reviewed = [];

            foreach ($proposed as $field => $value) {
                $enum = CorrectableField::tryFrom((string) $field);

                if ($enum === null) {
                    throw new ApiException(ErrorCode::BadRequest, "`{$field}` cannot be corrected.");
                }

                $enum->isSelfService()
                    ? $selfService[$enum->value] = $value
                    : $reviewed[$enum->value] = $value;
            }

            $request = ResidentCorrectionRequest::query()->create([
                'resident_id' => $resident->id,
                'requested_by' => $actor->subjectId,
                // Approved outright only when nothing needs a human. A mixed request waits
                // as a whole, so a resident is never told "half of that was done".
                'status' => $reviewed === [] ? CorrectionStatus::Approved : CorrectionStatus::Pending,
                'note' => $note,
                'reviewed_at' => $reviewed === [] ? now() : null,
                'review_note' => $reviewed === [] ? 'Applied automatically: self-service fields only.' : null,
            ]);

            foreach ($proposed as $field => $value) {
                ResidentCorrectionField::query()->create([
                    'resident_correction_request_id' => $request->id,
                    'field' => $field,
                    'current_value' => $this->render($resident->getAttribute((string) $field)),
                    'proposed_value' => $this->render($value),
                ]);
            }

            if ($reviewed === []) {
                $this->registry->applyChanges(
                    $resident,
                    $selfService,
                    $actor,
                    'Self-service correction by the resident',
                );
            }

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.correction-requested',
                $reviewed === []
                    ? 'Self-service correction applied by the resident'
                    : 'Correction request filed for review',
                (string) $resident->uuid,
            );

            return $request->refresh();
        });
    }

    /**
     * A reviewer approves a pending request and the changes land on the canonical record.
     *
     * Applied through {@see ResidentRegistry::applyChanges()} rather than directly, so a
     * correction produces the same history, aliases and fingerprint rebuild as any other
     * edit. A second write path here would be a second set of rules to keep in sync, and
     * the one that gets forgotten is always the audit trail.
     */
    public function approve(
        ResidentCorrectionRequest $request,
        ActorContext $actor,
        ?string $reviewNote = null,
    ): ResidentCorrectionRequest {
        return DB::transaction(function () use ($request, $actor, $reviewNote): ResidentCorrectionRequest {
            /** @var ResidentCorrectionRequest $request */
            $request = ResidentCorrectionRequest::query()->lockForUpdate()->findOrFail($request->id);

            $this->assertDecidable($request);

            /** @var Resident $resident */
            $resident = Resident::query()->findOrFail($request->resident_id);

            $changes = [];

            foreach ($request->fields()->get() as $field) {
                $changes[(string) $field->field] = $field->proposed_value;
            }

            $this->registry->applyChanges($resident, $changes, $actor, 'Correction request approved');

            $request->forceFill([
                'status' => CorrectionStatus::Approved,
                'reviewed_by' => $actor->subjectId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ])->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.correction-approved',
                'Correction request approved and applied',
                (string) $resident->uuid,
            );

            return $request->refresh();
        });
    }

    public function reject(
        ResidentCorrectionRequest $request,
        ActorContext $actor,
        string $reviewNote,
    ): ResidentCorrectionRequest {
        return DB::transaction(function () use ($request, $actor, $reviewNote): ResidentCorrectionRequest {
            /** @var ResidentCorrectionRequest $request */
            $request = ResidentCorrectionRequest::query()->lockForUpdate()->findOrFail($request->id);

            $this->assertDecidable($request);

            $request->forceFill([
                'status' => CorrectionStatus::Rejected,
                'reviewed_by' => $actor->subjectId,
                'reviewed_at' => now(),
                // A refusal always carries a reason. "Rejected" with no explanation is not a
                // decision a resident can act on or appeal.
                'review_note' => $reviewNote,
            ])->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.correction-rejected',
                'Correction request rejected',
                null,
            );

            return $request->refresh();
        });
    }

    /**
     * The resident changes their mind before anybody rules.
     */
    public function withdraw(ResidentCorrectionRequest $request, ActorContext $actor): ResidentCorrectionRequest
    {
        $this->assertDecidable($request);

        $request->forceFill([
            'status' => CorrectionStatus::Withdrawn,
            'reviewed_at' => now(),
        ])->save();

        return $request->refresh();
    }

    private function assertDecidable(ResidentCorrectionRequest $request): void
    {
        if (! $request->status->canBeDecided()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That correction request has already been resolved.',
            );
        }
    }

    private function render(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
