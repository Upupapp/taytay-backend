<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\AssistanceHistory;
use Modules\Welfare\Application\EnrollmentService;
use Modules\Welfare\Domain\EnrollmentStatus;
use Modules\Welfare\Infrastructure\Eloquent\ProgramEnrollment;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Programme rolls and assistance history, for staff (ADR 0019).
 *
 * ENROLMENT IS A HUMAN DECISION. Nothing here reads a guidance outcome, an assessment
 * recommendation or a vulnerability score. The chain of deliberately-not-automatic steps now
 * runs end to end: guidance advises, an assessment recommends, a case is approved, and only then
 * does somebody with `enrollment.manage` put a name on a roll — recorded against theirs.
 */
final class EnrollmentController
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly AssistanceHistory $history,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The roll, filtered.
     *
     * `as_of` answers the question a release audit actually asks: who was on this roll when the
     * October tranche went out. Answered from the effective dates rather than today's status.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EnrollmentView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->enrollments->query();

        $programId = $request->query('program_id');

        if (is_string($programId) && $programId !== '') {
            $query->where('program_id', $programId);
        }

        $status = $request->query('status');

        if (is_string($status) && EnrollmentStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $residentId = $request->query('resident_id');

        if (is_string($residentId) && $residentId !== '') {
            $query->where('resident_id', $residentId);
        }

        // Period filters. `from`/`to` bound when the enrolment started; `as_of` asks which
        // enrolments were in force on a date, which is a different and more useful question.
        $from = $request->query('from');
        $to = $request->query('to');

        if (is_string($from) && $from !== '') {
            $query->whereDate('effective_from', '>=', $from);
        }

        if (is_string($to) && $to !== '') {
            $query->whereDate('effective_from', '<=', $to);
        }

        $asOf = $request->query('as_of');

        if (is_string($asOf) && $asOf !== '') {
            $query->whereDate('effective_from', '<=', $asOf)
                ->where(function ($builder) use ($asOf): void {
                    $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf);
                });
        }

        /*
         * Barangay scope is applied through the source case, because an enrolment has no
         * barangay of its own — the beneficiary's barangay is ResidentProfile's fact and
         * denormalising it here would be a second copy that moves whenever they do
         * (ADR 0008 §10).
         *
         * An enrolment with no source case is visible only to an unrestricted actor: a bulk or
         * legacy enrolment has no barangay evidence attached, and guessing one would be worse
         * than admitting it.
         */
        if (! $actor->scope->isUnrestricted()) {
            $scopedCases = $this->authorization->scopeToBarangays($actor, WelfareCase::query()->select('uuid'));
            $query->whereIn('source_case_id', $scopedCases);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ProgramEnrollment $enrollment): array => $this->projection($enrollment),
        );
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EnrollmentManage);

        $validated = $request->validate([
            'program_id' => ['required', 'string', 'max:64'],
            'resident_id' => ['required', 'string', 'max:64'],
            'household_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_case_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'effective_from' => ['sometimes', 'date'],
            'entry_reason' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $resident = $this->residents->summaryFor($validated['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        // A beneficiary must be inside the caller's scope, or a clerk could put somebody from
        // another barangay onto a roll they cannot then see or audit.
        $this->authorization->authorizeBarangay($actor, $resident->barangayId, 'That resident was not found.');

        return ApiResponse::created($this->projection(
            $this->enrollments->enroll($validated['program_id'], $resident->id, $actor, $validated),
        ));
    }

    public function changeStatus(Request $request, ActorContext $actor, string $enrollment): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EnrollmentManage);

        $model = $this->enrollmentOrFail($actor, $enrollment);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->projection($this->enrollments->changeStatus(
            $model,
            EnrollmentStatus::from($validated['status']),
            $actor,
            $validated['note'] ?? null,
        )));
    }

    public function exit(Request $request, ActorContext $actor, string $enrollment): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EnrollmentManage);

        $model = $this->enrollmentOrFail($actor, $enrollment);

        $validated = $request->validate([
            // Mandatory. "Graduated", "moved out", "no longer eligible" and "found to be
            // duplicate" are four different facts, and an unexplained exit is indistinguishable
            // from an unauthorised removal.
            'exit_reason' => ['required', 'string', 'max:64'],
            'effective_to' => ['sometimes', 'date'],
        ]);

        return ApiResponse::item($this->projection($this->enrollments->exit(
            $model,
            $actor,
            $validated['exit_reason'],
            $validated['effective_to'] ?? null,
        )));
    }

    /**
     * A beneficiary's full assistance history — what was granted, and which rolls they hold.
     */
    public function historyForResident(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::EnrollmentView);

        $summary = $this->residents->summaryFor($resident);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $summary->barangayId, 'That resident was not found.');

        return ApiResponse::item($this->history->historyFor($summary->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(ProgramEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->uuid,
            'program_id' => $enrollment->program_id,
            'program_code' => $enrollment->program_code,
            'resident_id' => $enrollment->resident_id,
            'household_id' => $enrollment->household_id,
            'source_case_id' => $enrollment->source_case_id,
            'status' => $enrollment->status->value,
            'effective_from' => $enrollment->effective_from?->toDateString(),
            'effective_to' => $enrollment->effective_to?->toDateString(),
            'entry_reason' => $enrollment->entry_reason,
            'exit_reason' => $enrollment->exit_reason,
            'note' => $enrollment->note,
            'enrolled_by' => $enrollment->enrolled_by,
            'exited_by' => $enrollment->exited_by,
        ];
    }

    /**
     * Loads an enrolment and enforces scope through its beneficiary.
     */
    private function enrollmentOrFail(ActorContext $actor, string $uuid): ProgramEnrollment
    {
        /** @var ProgramEnrollment|null $enrollment */
        $enrollment = ProgramEnrollment::query()->where('uuid', $uuid)->first();

        if ($enrollment === null) {
            throw ResourceNotFoundException::make('That enrolment was not found.');
        }

        $resident = $this->residents->summaryFor((string) $enrollment->resident_id);

        // Out-of-scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay(
            $actor,
            $resident?->barangayId,
            'That enrolment was not found.',
        );

        return $enrollment;
    }
}
