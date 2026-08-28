<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

use Illuminate\Support\Facades\DB;

/**
 * The municipality's barangays, as a stable code for an internal key.
 *
 * ── WHY THIS IS IN `Shared` ──────────────────────────────────────────────────────────
 *
 * `barangays` is municipal reference data with **no owning module**. Five modules already read it
 * directly — `ResidentProfile` publishes the public directory, `AccessControl` scopes staff to it,
 * `Reporting`, `Welfare` and `Search` group by it — and the dependency graph forbids most of them
 * asking each other: `AccessControl` may not call `ResidentProfile`, because the arrow runs the
 * other way and a cycle is what `ModuleBoundaryTest` exists to refuse.
 *
 * So the choice was between duplicating a batched lookup in six modules or putting one where
 * everything may already reach. This is the second. It follows {@see IdempotencyService}, which is
 * also cross-cutting infrastructure in `Shared` that touches persistence, and it imports no module,
 * which is the constraint Article 2.3 actually states and the architecture test actually enforces.
 *
 * **THE CLEANER ANSWER IS A `Reference` MODULE THAT OWNS THIS TABLE**, with the directory
 * controller moved into it. That is a registry entry, a boundary-map row and an ADR, and it is not
 * this change. Recorded in ADR 0045 rather than left as a silent decision.
 *
 * ── WHY THE WHOLE MAP, AND NOT A LOOKUP PER ROW ──────────────────────────────────────
 *
 * A municipality has tens of barangays, not thousands, and a page of residents can reference many
 * of them. One query for the whole table costs less than a `whereIn` per page and — the part that
 * matters — its cost does not move when the page gets longer. `QueryBudgetTest` measures exactly
 * that slope, and a per-row lookup here would light it up on `/admin/residents` immediately.
 *
 * Memoised per request via a `scoped` binding, so several projections in one response share the
 * single query. Deliberately NOT a `singleton`: container-lifetime bindings survive between
 * requests on a long-lived worker, and a barangay added after boot would then be invisible until
 * the process restarted. The provider docblock records the same trap for `RequestContext`, where
 * the consequence is worse.
 */
final class BarangayCodes
{
    /** @var array<int, string>|null */
    private ?array $map = null;

    /**
     * The stable code for a barangay id, or null when there is no such barangay.
     *
     * NULL IS A REAL ANSWER AND MUST STAY ONE. A row can reference a barangay that has since been
     * removed, and the honest response is that we cannot currently say which barangay it was.
     * Callers emit null rather than falling back to the integer — a fallback would put the
     * auto-increment key back into exactly the payloads this exists to keep it out of.
     */
    public function codeFor(mixed $barangayId): ?string
    {
        if ($barangayId === null) {
            return null;
        }

        return $this->map()[(int) $barangayId] ?? null;
    }

    /** @return array<int, string> */
    public function map(): array
    {
        if ($this->map === null) {
            $this->map = DB::table('barangays')
                ->pluck('code', 'id')
                ->map(static fn (mixed $code): string => (string) $code)
                ->all();
        }

        return $this->map;
    }
}
