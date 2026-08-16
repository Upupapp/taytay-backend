<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\InvalidStateTransitionException;
use Modules\Welfare\Domain\CasePriority;
use Modules\Welfare\Domain\CaseStatus;
use Modules\Welfare\Domain\CaseType;
use Modules\Welfare\Infrastructure\Eloquent\CaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The case lifecycle (ADR 0007, ADR 0016).
 *
 * ONE TRANSITION METHOD. Nothing else writes `status`. Nine verbs would mean nine places the
 * transition map could be forgotten, and the tenth endpoint somebody adds in a hurry would
 * be the one that skips it.
 *
 * THE ORDER OF CHECKS IS LOAD-BEARING:
 *
 *   1. Is the transition legal? → `409 INVALID_STATE_TRANSITION`
 *   2. Is a reason required and present? → `422`
 *   3. Does the actor hold the target's permission? → `403`
 *   4. Does separation of duties allow it? → `403`
 *
 * Legality is checked *before* permission on purpose (contract matrix §5): if permission came
 * first, a caller could probe which permissions they hold by watching whether an illegal
 * transition returns 403 or 409, and map the authorization table from outside.
 *
 * THE VULNERABILITY SCORE IS NOT AN INPUT HERE. Not to priority, not to routing, not to any
 * decision. It is placeholder weights awaiting MSWDO approval (gap G-20) and carries
 * `decision_support_only: true` in its own payload; wiring it into casework would make an
 * unapproved ordering consequential, and would do it invisibly (ADR 0016 §4).
 */
final class CaseService
{
    public function __construct(
        private readonly CaseTimeline $timeline,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Opens a case.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes, ActorContext $actor): WelfareCase
    {
        return DB::transaction(function () use ($attributes, $actor): WelfareCase {
            $case = WelfareCase::query()->create($attributes + [
                // Every case starts at draft and is moved from there, so the opening row in
                // the transition log exists for even the shortest-lived case.
                'status' => CaseStatus::Draft,
                'priority' => CasePriority::Normal,
                'opened_by' => $actor->subjectId,
                'opened_at' => now(),
                'last_activity_at' => now(),
            ]);

            $this->recordTransition($case, null, CaseStatus::Draft, null, null, $actor);

            $this->timeline->record(
                $case,
                'case.opened',
                'Case opened',
                'We have created your case.',
                true,
                $actor,
            );

            $this->audit->record($actor->subjectId, 'case.opened', 'Welfare case opened', (string) $case->uuid);

            return $case;
        });
    }

    /**
     * Moves a case, or refuses to.
     *
     * @param  callable(string): bool  $hasPermission  resolved by the caller from AccessControl
     */
    public function transition(
        WelfareCase $case,
        CaseStatus $target,
        ActorContext $actor,
        callable $hasPermission,
        ?string $reason = null,
        ?string $applicantMessage = null,
    ): WelfareCase {
        return DB::transaction(function () use ($case, $target, $actor, $hasPermission, $reason, $applicantMessage): WelfareCase {
            /** @var WelfareCase $case */
            $case = WelfareCase::query()->lockForUpdate()->findOrFail($case->id);

            $from = $case->status;

            // 1. Legality first — see the class docblock on why this precedes permission.
            if (! $from->canTransitionTo($target)) {
                throw InvalidStateTransitionException::between($from->value, $target->value);
            }

            // 2. A decision somebody will be asked to justify must carry its justification.
            if ($target->requiresReason() && ($reason === null || trim($reason) === '')) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    "Moving a case to `{$target->value}` requires a reason.",
                );
            }

            // 3. Permission, resolved from the target state.
            $permission = $target->requiredPermission();

            if ($permission !== null && ! $hasPermission($permission)) {
                throw new ApiException(
                    ErrorCode::Forbidden,
                    'You are not permitted to move a case to that state.',
                );
            }

            // 4. Separation of duties.
            $this->assertSeparationOfDuties($case, $target, $actor);

            $case->forceFill([
                'status' => $target,
                'last_activity_at' => now(),
                'closed_at' => $target->isTerminal() ? now() : null,
            ])->save();

            $this->recordTransition($case, $from, $target, $reason, $applicantMessage, $actor);

            /*
             * The applicant is told what happened, in the words chosen for them.
             *
             * `applicantMessage` when the caseworker wrote one, otherwise the status's own
             * plain-language line. NEVER `reason` — that is the internal justification, and
             * defaulting to it is exactly how staff deliberation reaches a citizen app.
             */
            $this->timeline->record(
                $case,
                'case.status-changed',
                "Status changed from {$from->value} to {$target->value}",
                $applicantMessage ?? $target->citizenMessage(),
                true,
                $actor,
            );

            $this->audit->record(
                $actor->subjectId,
                'case.status-changed',
                "Case status changed to {$target->value}",
                (string) $case->uuid,
            );

            return $case->refresh();
        });
    }

    /**
     * Sets priority. Urgent requires a reason.
     */
    public function changePriority(
        WelfareCase $case,
        CasePriority $priority,
        ActorContext $actor,
        ?string $reason,
    ): WelfareCase {
        if ($priority->requiresReason() && ($reason === null || trim($reason) === '')) {
            // Moving somebody ahead of everyone else waiting is a decision that needs a name
            // against it.
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Raising a case to urgent requires a reason.',
            );
        }

        $case->forceFill([
            'priority' => $priority,
            'priority_reason' => $priority->requiresReason() ? $reason : null,
            'last_activity_at' => now(),
        ])->save();

        $this->timeline->record(
            $case,
            'case.priority-changed',
            "Priority set to {$priority->value}",
            // Staff-only: telling an applicant their case is "low priority" is both
            // demoralising and an invitation to argue with the queue rather than the office.
            null,
            false,
            $actor,
        );

        return $case->refresh();
    }

    /**
     * Archives a closed case.
     *
     * Archival is a flag, not a state (ADR 0016 §1): a rejected case and a completed case both
     * archive, and collapsing them into one terminal status would lose the outcome.
     */
    public function archive(WelfareCase $case, ActorContext $actor): WelfareCase
    {
        if ($case->status->isOpen()) {
            throw new ApiException(ErrorCode::Conflict, 'An open case cannot be archived.');
        }

        $case->forceFill(['archived_at' => now()])->save();

        $this->audit->record($actor->subjectId, 'case.archived', 'Welfare case archived', (string) $case->uuid);

        return $case->refresh();
    }

    /**
     * Separation of duties, asserted over this backend's own role catalog.
     *
     * THE RULE: the person who endorsed a case may not be the person who approves it.
     *
     * An endorsement is a social worker's recommendation; an approval commits public money to
     * it. One person doing both is the single-signature path that every audit of a benefits
     * programme looks for first, and it is the reason `request.endorse` and `request.approve`
     * are separate permissions rather than one.
     *
     * Enforced per *case and actor*, not merely per role. Two staff who both happen to hold
     * both permissions still cannot self-approve their own endorsement, which a role-level
     * check alone would allow.
     */
    private function assertSeparationOfDuties(WelfareCase $case, CaseStatus $target, ActorContext $actor): void
    {
        if ($target !== CaseStatus::Approved || $actor->subjectId === null) {
            return;
        }

        $endorsedBySelf = CaseTransition::query()
            ->where('welfare_case_id', $case->id)
            ->where('to_status', CaseStatus::Endorsed->value)
            ->where('actor_subject_id', $actor->subjectId)
            ->exists();

        if ($endorsedBySelf) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'The person who endorsed a case may not approve it.',
            );
        }
    }

    private function recordTransition(
        WelfareCase $case,
        ?CaseStatus $from,
        CaseStatus $to,
        ?string $reason,
        ?string $applicantMessage,
        ActorContext $actor,
    ): void {
        CaseTransition::query()->create([
            'welfare_case_id' => $case->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'applicant_message' => $applicantMessage,
            'actor_subject_id' => $actor->subjectId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Base listing query, ordered the way a queue is worked: most urgent first, then oldest.
     *
     * The ordering is by the staff-set priority and the clock. Nothing here consults the
     * vulnerability score — see the class docblock.
     *
     * @return Builder<WelfareCase>
     */
    public function query(): Builder
    {
        return WelfareCase::query()
            ->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'normal' then 2 else 1 end desc")
            ->orderBy('opened_at');
    }

    /**
     * @return list<string>
     */
    public function availableTransitions(WelfareCase $case): array
    {
        return array_map(
            static fn (CaseStatus $status): string => $status->value,
            $case->status->allowedNext(),
        );
    }

    public static function typeFrom(string $value): CaseType
    {
        $type = CaseType::tryFrom($value);

        if ($type === null) {
            throw new ApiException(ErrorCode::BadRequest, 'Unknown case type.');
        }

        return $type;
    }
}
