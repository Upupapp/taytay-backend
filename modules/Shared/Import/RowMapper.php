<?php

declare(strict_types=1);

namespace Modules\Shared\Import;

/**
 * How one kind of legacy row becomes something this system understands (ADR 0040 §3).
 *
 * **THERE IS NO IMPLEMENTATION OF THIS INTERFACE, AND THAT IS THE POINT.** The master command is
 * explicit: build the framework, and do not write one-off migration code against imaginary legacy
 * columns. Taytay has not supplied an export, so any mapping written now would encode a guess
 * about column names, date formats and encodings — and a guess in a mapping is worse than no
 * mapping, because it looks like knowledge.
 *
 * When a real file arrives, a mapper is a single small class: name the target, validate a row,
 * derive its identity. Nothing in the staging tables, the dry run, the rejection report or the
 * commit changes.
 *
 * ── THE THREE METHODS, AND WHY EACH IS SEPARATE ───────────────────────────────────────
 *
 * `validate()` and `importKey()` are deliberately not one method. Validity is about whether a row
 * is *usable*; identity is about whether it is *already here*. A row can be perfectly valid and a
 * duplicate, and conflating them means a re-run reports thousands of "errors" that are simply
 * records the system already holds.
 */
interface RowMapper
{
    /**
     * The `target` this mapper handles — `resident`, `household`, and so on.
     *
     * Matched against `import_batches.target`, so one batch is handled by exactly one mapper and a
     * file cannot be silently interpreted as the wrong kind of thing.
     */
    public function target(): string;

    /**
     * Every reason this row cannot be imported, or an empty list if it can.
     *
     * **ALL the reasons, not the first.** An operator fixing a spreadsheet of four thousand rows
     * needs to know everything wrong with a row in one pass; returning the first failure turns one
     * correction into three round trips through a validation run that takes minutes.
     *
     * @param  array<string, mixed>  $row  the source row, verbatim
     * @return list<string> human-readable reasons, written for whoever holds the spreadsheet
     */
    public function validate(array $row): array;

    /**
     * A stable identity for this row, or null if it has none.
     *
     * Used to recognise a row that has already been imported — by an earlier batch, or by an
     * earlier run of this one. Null means "cannot tell", which is treated as *not* a duplicate:
     * refusing an unidentifiable row would reject legitimate data, and the alternative failure
     * (importing it twice) is caught by the reviewer looking at the duplicate report.
     *
     * @param  array<string, mixed>  $row
     */
    public function importKey(array $row): ?string;
}
