<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Infrastructure\Eloquent\SafeguardingConcern;

/**
 * The restricted tier (ADR 0022 §4).
 *
 * THE ACCEPTANCE CRITERION: **safeguarding detail is not returned to generic list endpoints.**
 *
 * That is held by there being no method here that a list endpoint could call and get detail from.
 * The two read paths are deliberately unequal:
 *
 *  * {@see advisoryFor()} answers "is there something the person attending this address needs to
 *    know for their own safety" — one sentence, no history, no category, available to anyone who
 *    may make the visit;
 *  * {@see detailFor()} answers "why is this family being watched" — the whole record, and only
 *    for a holder of `safeguarding.view`.
 *
 * The split exists because they are genuinely different questions. A worker being sent to a house
 * is entitled to know there is a risk to *them* without being told a family's protection history;
 * withholding both would send somebody into a situation the office knew about, and disclosing
 * both would put a protection record in front of everybody who drives a van.
 */
final class SafeguardingRegistry
{
    public function __construct(private readonly WelfareAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function raise(array $attributes, ActorContext $actor): SafeguardingConcern
    {
        if (trim((string) ($attributes['detail'] ?? '')) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'A safeguarding concern must say what the concern is.',
            );
        }

        /** @var SafeguardingConcern $concern */
        $concern = SafeguardingConcern::query()->create([
            'resident_id' => (string) $attributes['resident_id'],
            'welfare_case_id' => $attributes['welfare_case_id'] ?? null,
            'category' => (string) $attributes['category'],
            'status' => 'open',
            'detail' => (string) $attributes['detail'],
            'worker_safety_advisory' => $attributes['worker_safety_advisory'] ?? null,
            'raised_by' => $actor->subjectId,
            'raised_at' => now(),
        ]);

        /*
         * Audited by identifier only. The summary names the act, never the category — an audit
         * trail reading "child-protection concern raised" against a case id would become a
         * second, less-guarded copy of exactly the thing this table restricts, and the audit log
         * is read by operators investigating something else entirely.
         */
        $this->audit->record(
            $actor->subjectId,
            'safeguarding.raised',
            'Safeguarding concern raised',
            $attributes['case_uuid'] ?? null,
        );

        return $concern;
    }

    public function close(SafeguardingConcern $concern, string $reason, ActorContext $actor): SafeguardingConcern
    {
        if (! $concern->isActive()) {
            throw new ApiException(ErrorCode::Conflict, 'That concern is already closed.');
        }

        /*
         * Deciding a family no longer needs watching is as consequential as deciding they do, and
         * an unexplained closure is indistinguishable from somebody tidying a queue.
         */
        if (trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why this concern is being closed.');
        }

        $concern->forceFill([
            'status' => 'closed',
            'closure_reason' => $reason,
            'closed_by' => $actor->subjectId,
            'closed_at' => now(),
        ])->save();

        $this->audit->record($actor->subjectId, 'safeguarding.closed', 'Safeguarding concern closed', null);

        return $concern->refresh();
    }

    /**
     * One sentence for whoever is attending, or null.
     *
     * NO CATEGORY, NO DETAIL, NO COUNT, NO HISTORY. It is deliberately not "there are 2 concerns"
     * — a number is a judgement about a family that travels further than the sentence does, and
     * "2 safeguarding concerns" read off a screen in front of a household is a disclosure the
     * office cannot take back.
     */
    public function advisoryFor(string $residentUuid): ?string
    {
        $advisory = SafeguardingConcern::query()
            ->where('resident_id', $residentUuid)
            ->where('status', '!=', 'closed')
            ->whereNotNull('worker_safety_advisory')
            ->orderByDesc('raised_at')
            ->value('worker_safety_advisory');

        return $advisory === null ? null : (string) $advisory;
    }

    /**
     * Whether an active concern exists at all.
     *
     * Used on a **case detail** view and never on a list. Someone opening a file is entitled to
     * know there is a restricted record they cannot read — otherwise they read the file as
     * complete and act as though nothing is there. Someone scrolling a queue is not: a marker in
     * a list marks the family to every person who scrolls past.
     */
    public function hasActiveConcern(string $residentUuid): bool
    {
        return SafeguardingConcern::query()
            ->where('resident_id', $residentUuid)
            ->where('status', '!=', 'closed')
            ->exists();
    }

    /**
     * The full record. Callers must hold `safeguarding.view`; this class does not check.
     *
     * @return Collection<int, SafeguardingConcern>
     */
    public function detailFor(string $residentUuid): Collection
    {
        return SafeguardingConcern::query()
            ->where('resident_id', $residentUuid)
            ->orderByDesc('raised_at')
            ->get();
    }
}
