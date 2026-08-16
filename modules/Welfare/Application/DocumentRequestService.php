<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Infrastructure\Eloquent\CaseRequirement;
use Modules\Welfare\Infrastructure\Eloquent\DocumentRequest;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Recording that the office asked an applicant for something (ADR 0020 §6).
 *
 * NOT A CASE TASK. A task is owed by staff and is late when staff are late; this is owed by the
 * applicant. Mixing the two produces an overdue queue that tells a supervisor nothing, because
 * half of it is work nobody in the building can do.
 *
 * It exists because "we told them to bring the barangay certificate" used to live in somebody's
 * memory, which fails the applicant twice: a different clerk asks again on their next visit, and
 * if they say nobody told them, the office has nothing to check.
 */
final class DocumentRequestService
{
    public function __construct(private readonly WelfareAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(
        WelfareCase $case,
        CaseRequirement $requirement,
        array $attributes,
        ActorContext $actor,
    ): DocumentRequest {
        $message = trim((string) ($attributes['message'] ?? ''));

        /*
         * The message is what the applicant was actually told, in the words used. Mandatory.
         *
         * An empty one leaves a record that something was asked for without saying what, which
         * is worse than no record at all: it looks like the office followed up, and cannot show
         * what it said.
         */
        if ($message === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Record what the applicant was told.',
            );
        }

        $neededBy = $attributes['needed_by'] ?? null;

        if ($neededBy !== null && Carbon::parse((string) $neededBy)->isPast()) {
            // A deadline already gone is a request that is overdue the moment it is made, which
            // puts the applicant in default for something they have just been told.
            throw new ApiException(ErrorCode::ValidationFailed, 'That deadline has already passed.');
        }

        $request = DocumentRequest::query()->create([
            'welfare_case_id' => $case->id,
            'welfare_case_requirement_id' => $requirement->id,
            'state' => 'open',
            'channel' => (string) $attributes['channel'],
            'message' => $message,
            'needed_by' => $neededBy,
            'requested_by' => $actor->subjectId,
            'requested_at' => now(),
        ]);

        $this->audit->record(
            $actor->subjectId,
            'case.document-requested',
            'Document requested from the applicant',
            (string) $case->uuid,
        );

        return $request;
    }

    /**
     * The office no longer needs it.
     *
     * Withdrawn rather than deleted, and with a reason: an applicant who was chasing a document
     * deserves a record showing they were released from it, and a request that simply vanishes
     * looks like one that was never made.
     */
    public function withdraw(DocumentRequest $request, string $reason, ActorContext $actor): DocumentRequest
    {
        if ($request->state !== 'open') {
            throw new ApiException(ErrorCode::Conflict, 'That request is already closed.');
        }

        if (trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why this is no longer needed.');
        }

        $request->forceFill([
            'state' => 'withdrawn',
            'withdrawn_reason' => $reason,
            'closed_at' => now(),
        ])->save();

        return $request->refresh();
    }

    /**
     * Open requests first, then most recently asked — the console's `byRequestUrgency`.
     *
     * @return Collection<int, DocumentRequest>
     */
    public function forCase(WelfareCase $case): Collection
    {
        return DocumentRequest::query()
            ->where('welfare_case_id', $case->id)
            ->orderByRaw("CASE WHEN state = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->get();
    }
}
