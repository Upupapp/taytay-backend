<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Modules\Shared\Application\ActorContext;
use Modules\Welfare\Infrastructure\Eloquent\CaseEvent;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The material history of a case (ADR 0016 §5).
 *
 * Two audiences, one table, and the difference is decided **at write time**. The code raising
 * an event is the only code that knows whether its summary contains staff deliberation, so it
 * says so then — not later, at render, where the next endpoint to list events will have
 * forgotten the rule.
 *
 * The published entry point for other modules. A field visit (TAB 17), a satisfied requirement
 * (TAB 12) or a confirmed release (TAB 18) each land here through `record()`, so no module
 * writes another's table and every timeline row has passed the same visibility decision.
 */
final class CaseTimeline
{
    /**
     * Adds an event.
     *
     * @param  string|null  $citizenMessage  what the applicant is told; null keeps it staff-only
     * @param  bool  $visibleToCitizen  ignored unless a citizen message was written
     */
    public function record(
        WelfareCase $case,
        string $eventType,
        string $summary,
        ?string $citizenMessage,
        bool $visibleToCitizen,
        ActorContext $actor,
    ): CaseEvent {
        $event = CaseEvent::query()->create([
            'welfare_case_id' => $case->id,
            'event_type' => $eventType,
            'summary' => $summary,
            'citizen_message' => $citizenMessage,
            /*
             * Both conditions, always. An event flagged visible with no citizen message would
             * otherwise fall back to the operator summary at render time — which is precisely
             * the staff-deliberation leak this whole split exists to prevent.
             */
            'is_citizen_visible' => $visibleToCitizen && $citizenMessage !== null,
            'actor_subject_id' => $actor->subjectId,
            'occurred_at' => now(),
        ]);

        // A timeline entry is by definition activity, so the case's own clock follows it.
        // "Nothing has happened to this file in three weeks" then stays an indexed query
        // rather than a scan of this table.
        $case->forceFill(['last_activity_at' => now()])->save();

        return $event;
    }

    /**
     * The full operational timeline, newest first.
     *
     * @return Collection<int, CaseEvent>
     */
    public function forStaff(WelfareCase $case): Collection
    {
        return CaseEvent::query()
            ->where('welfare_case_id', $case->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * The applicant's view.
     *
     * Filtered **at the query**, not after fetching. Loading every event and trimming in PHP
     * would put staff deliberation in the process memory of a citizen request, one careless
     * `dd()` or exception serialiser away from the response — and the count would be wrong
     * for pagination besides.
     *
     * @return Collection<int, CaseEvent>
     */
    public function forCitizen(WelfareCase $case): Collection
    {
        return CaseEvent::query()
            ->where('welfare_case_id', $case->id)
            ->where('is_citizen_visible', true)
            ->whereNotNull('citizen_message')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }
}
