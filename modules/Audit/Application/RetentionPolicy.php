<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Illuminate\Support\Carbon;

/**
 * Whether a record may be destroyed yet (ADR 0034 §5).
 *
 * **IT REFUSES EVERYTHING UNTIL THE DPO APPROVES THE SCHEDULE**, and that is the design rather
 * than an unfinished state.
 *
 * The master command is explicit: do not hardcode legal retention periods without Taytay
 * DPO/legal approval. The tempting reading is "so leave retention unimplemented" — but then the
 * first person to need it writes a `deleteWhere('created_at', '<', ...)` somewhere, and the
 * schedule that governs destruction of residents' welfare records is a literal in a job file.
 *
 * So the machinery exists, the categories are in one reviewable config file, and the switch is
 * off. `PRIVACY_RETENTION_APPROVED=true` is a deliberate act by somebody entitled to perform it,
 * and until it happens `mayPurge()` returns false for everything and says why.
 *
 * The asymmetry is the whole point: **deletion is the one operation this system cannot undo.** A
 * record kept too long can still be destroyed tomorrow; a record destroyed on an unapproved
 * timetable is gone, and the family whose assistance history it held cannot get it back.
 */
final class RetentionPolicy
{
    public function __construct(private readonly GovernanceRegistry $governance) {}

    /**
     * Whether the LGU has approved the schedule at all.
     */
    public function isApproved(): bool
    {
        return config('privacy.retention.approved', false) === true;
    }

    /**
     * The retention period for a category, in days, or null if the category is unknown.
     *
     * Unknown returns NULL rather than a default. A default would mean a category somebody forgot
     * to add silently inherits a number nobody chose for it — and the direction of that mistake is
     * deletion.
     */
    public function daysFor(string $category): ?int
    {
        $days = config('privacy.retention.categories.'.$category);

        return is_int($days) && $days > 0 ? $days : null;
    }

    /**
     * The decision, with its reason.
     *
     * Returns a reason on refusal rather than a bare false, because "why is this record still
     * here" is asked by a reviewer, and "the sweeper said no" is not an answer.
     *
     * @return array{0: bool, 1: string}
     */
    public function mayPurge(
        string $category,
        Carbon $closedAt,
        string $entityType,
        ?string $entityId = null,
        ?string $subjectId = null,
    ): array {
        if (! $this->isApproved()) {
            return [false, 'The retention schedule has not been approved by the LGU’s Data Protection Officer.'];
        }

        $days = $this->daysFor($category);

        if ($days === null) {
            return [false, sprintf('No approved retention period exists for the category [%s].', $category)];
        }

        /*
         * THE HOLD OUTRANKS THE SCHEDULE, in one direction only. A hold can prevent a deletion and
         * can never cause one — checked here so that every purge path inherits it by construction
         * rather than by each one remembering.
         */
        if ($this->governance->isUnderHold($entityType, $entityId, $subjectId)) {
            return [false, 'This record is under a legal hold.'];
        }

        $due = $closedAt->copy()->addDays($days);

        if ($due->isFuture()) {
            return [false, sprintf('Retention runs until %s.', $due->toDateString())];
        }

        return [true, sprintf('Retention of %d days elapsed on %s.', $days, $due->toDateString())];
    }

    /**
     * The whole schedule, as a reviewer reads it.
     *
     * Surfaced through the API so the DPO can check what the system believes without reading a
     * config file over somebody's shoulder — and so "is this approved?" has an answer that comes
     * from the running system rather than from a document.
     *
     * @return array<string, mixed>
     */
    public function schedule(): array
    {
        return [
            'approved' => $this->isApproved(),
            'approved_by' => (string) config('privacy.retention.approved_by', ''),
            'approved_on' => (string) config('privacy.retention.approved_on', ''),
            'categories' => (array) config('privacy.retention.categories', []),
            'legal_bases' => (array) config('privacy.legal_bases', []),
            /*
             * Stated in the payload, not just in a docblock. A console rendering this table must
             * be able to say, in the interface, that nothing here is law yet.
             */
            'notice' => 'These values are placeholders pending review under RA 10173 and current '
                .'National Privacy Commission issuances. While `approved` is false, no scheduled '
                .'deletion occurs anywhere in this system.',
        ];
    }
}
