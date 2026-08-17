<?php

declare(strict_types=1);

namespace Modules\Shared\Import;

/**
 * What a validation or import pass found (ADR 0040 §3).
 *
 * Carries counts and a bounded sample of rejections rather than every rejected row. A report that
 * returned four thousand rejections is a report nobody reads, and the first twenty are almost
 * always the same handful of problems repeated — the operator fixes those, re-runs, and the
 * remaining ones surface.
 */
final readonly class ImportOutcome
{
    /**
     * @param  list<array{line: int, reasons: list<string>}>  $rejections
     */
    public function __construct(
        public int $total,
        public int $valid,
        public int $rejected,
        public int $duplicates,
        public int $imported,
        public array $rejections,
        /** True when nothing was written. A dry run is always true. */
        public bool $wasDryRun,
    ) {}

    /**
     * Whether this batch is safe to commit.
     *
     * A batch with ANY rejection is not. That is stricter than it needs to be and it is the right
     * default for a resident registry: importing the good rows and leaving the bad ones produces a
     * partial registry that looks complete, and the missing people are the ones whose data was
     * messiest — which correlates with the households that need the office most.
     */
    public function isCommittable(): bool
    {
        /*
         * `valid > 0`, not `total > 0` — a distinction the tests caught in this class's first
         * version. A batch whose every row is a DUPLICATE has no rejections and plenty of rows,
         * and committing it would walk the whole file to write nothing. Reporting it as
         * committable would send an operator looking for records that were never going to appear.
         */
        return $this->rejected === 0 && $this->valid > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->total,
            'valid_rows' => $this->valid,
            'rejected_rows' => $this->rejected,
            'duplicate_rows' => $this->duplicates,
            'imported_rows' => $this->imported,
            'was_dry_run' => $this->wasDryRun,
            'committable' => $this->isCommittable(),
            'rejections' => $this->rejections,
        ];
    }
}
