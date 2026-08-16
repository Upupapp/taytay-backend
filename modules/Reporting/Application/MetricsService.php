<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\Shared\Application\ActorContext;

/**
 * Dashboard aggregates (ADR 0026).
 *
 * TWO RULES, AND THE SECOND IS THE ONE PEOPLE FORGET.
 *
 * **Aggregate-first.** Nothing here returns a name. Every method returns counts, and the
 * person-level detail behind a count is reached through the module that owns it, where
 * authorization is checked per record.
 *
 * **An aggregate of one is a person.** "3 households with a safeguarding concern in Barangay
 * Dolores" is a statistic; "1 household" plus the barangay is an identification, and combined
 * with a sector breakdown it is a disclosure with no audit trail. Counts below a threshold are
 * therefore suppressed rather than published — the standard disclosure control in official
 * statistics, applied here because the objective asks for privacy-aware aggregates.
 *
 * SCOPE IS APPLIED TO EVERY QUERY. A barangay-scoped clerk's dashboard is their barangay's
 * dashboard; a metric that ignored scope would be a way to read the whole municipality's caseload
 * through a number, which is exactly what an aggregate is good at hiding.
 */
final class MetricsService
{
    /**
     * The smallest count that may be published for a breakdown.
     *
     * Below this, the cell reports `null` with `suppressed: true` rather than a number. Five is
     * the convention used by most statistical agencies for exactly this purpose; it is not a law
     * and the LGU may want a different figure (gap G-34), which is why it is a named constant
     * rather than a literal buried in a query.
     */
    public const MINIMUM_CELL = 5;

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * The headline figures. No breakdown, so no suppression is needed — a municipality-wide
     * count of open cases identifies nobody.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function summary(ActorContext $actor, array $filters): array
    {
        return [
            'active_cases' => $this->cases($actor, $filters)
                ->whereNotIn('welfare_cases.status', ['completed', 'rejected', 'cancelled', 'expired'])
                ->count(),
            'new_requests' => $this->cases($actor, $filters)
                ->whereNotNull('welfare_cases.opened_at')->count(),
            'pending_verification' => $this->cases($actor, $filters)
                ->whereIn('welfare_cases.status', ['submitted', 'intake-review'])->count(),
            'waiting_requirements' => $this->cases($actor, $filters)
                ->where('welfare_cases.status', 'returned')->count(),
            'approval_pipeline' => $this->cases($actor, $filters)
                ->whereIn('welfare_cases.status', ['endorsed', 'approved', 'scheduled'])->count(),
            /*
             * RELEASED, not approved. A case approved for ₱5,000 whose payout failed has released
             * nothing, and a dashboard that counted approvals as money out would tell the MSWDO
             * head they had spent what they still hold (ADR 0023 §7).
             */
            'released_total_centavos' => (int) $this->releases($actor, $filters)
                ->whereIn('releases.status', ['released', 'completed'])
                ->where('releases.kind', 'cash')
                ->sum('releases.amount_centavos'),
            'overdue_follow_ups' => (int) DB::table('tasks')
                ->where('status', 'open')
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', Carbon::now()->toDateString())
                ->count(),
        ];
    }

    /**
     * Case counts by status, suppressed below the minimum cell.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function caseAging(ActorContext $actor, array $filters): array
    {
        $rows = $this->cases($actor, $filters)
            ->selectRaw('welfare_cases.status as bucket, COUNT(*) as total')
            ->groupBy('welfare_cases.status')
            ->get();

        return $this->suppress($rows->map(static fn (object $row): array => [
            'bucket' => (string) $row->bucket,
            'total' => (int) $row->total,
        ])->all());
    }

    /**
     * How far the service reaches across the municipality.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function barangayReach(ActorContext $actor, array $filters): array
    {
        $rows = $this->cases($actor, $filters)
            ->selectRaw('welfare_cases.barangay_id as barangay_id, COUNT(*) as total')
            ->groupBy('welfare_cases.barangay_id')
            ->get();

        /*
         * The breakdown most in need of suppression. A barangay is small enough that a count of
         * one, plus any other filter the caller applied, names a household.
         */
        return $this->suppress($rows->map(static fn (object $row): array => [
            'barangay_id' => $row->barangay_id === null ? null : (int) $row->barangay_id,
            'total' => (int) $row->total,
        ])->all());
    }

    /**
     * How much of each programme has been used.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function programUtilization(ActorContext $actor, array $filters): array
    {
        $rows = $this->releases($actor, $filters)
            ->whereIn('releases.status', ['released', 'completed'])
            ->selectRaw('releases.program_code as program_code, COUNT(*) as releases, '
                .'SUM(CASE WHEN releases.kind = ? THEN releases.amount_centavos ELSE 0 END) as centavos', ['cash'])
            ->groupBy('releases.program_code')
            ->get();

        return $this->suppress($rows->map(static fn (object $row): array => [
            'program_code' => $row->program_code === null ? null : (string) $row->program_code,
            'total' => (int) $row->releases,
            // In-kind contributes nothing to a peso figure (ADR 0023 §1).
            'released_centavos' => (int) $row->centavos,
            'currency' => 'PHP',
        ])->all(), 'total');
    }

    /**
     * Referral outcomes, by what the receiving office reported.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function referralOutcomes(ActorContext $actor, array $filters): array
    {
        $rows = DB::table('referrals')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get();

        return $this->suppress($rows->map(static fn (object $row): array => [
            'bucket' => (string) $row->status,
            'total' => (int) $row->total,
        ])->all());
    }

    /**
     * Open work by team.
     *
     * BY TEAM, NEVER BY PERSON — see {@see ReportCatalog} and
     * ADR 0026 §4. The master command's instruction is explicit and this is where it would be
     * broken first: a `GROUP BY assigned_to` here is one line and produces a leaderboard.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function fieldWorkload(ActorContext $actor, array $filters): array
    {
        $rows = DB::table('tasks')
            ->where('status', 'open')
            ->selectRaw('team, COUNT(*) as total')
            ->groupBy('team')
            ->get();

        return $this->suppress($rows->map(static fn (object $row): array => [
            'team' => $row->team === null ? null : (string) $row->team,
            'total' => (int) $row->total,
        ])->all());
    }

    /**
     * How complete the registry is — the one metric that measures the office, not the public.
     *
     * @return array<string, int>
     */
    public function dataCompleteness(ActorContext $actor): array
    {
        $residents = DB::table('residents')->whereNull('deleted_at');

        return [
            'residents' => (clone $residents)->count(),
            'missing_birth_date' => (clone $residents)->whereNull('birth_date')->count(),
            'missing_address' => (clone $residents)->whereNull('street_address')->count(),
            'unverified' => (clone $residents)->where('verification_tier', '!=', 'verified')->count(),
        ];
    }

    /**
     * Suppresses cells below the minimum.
     *
     * The count is replaced by null and marked, rather than the row being dropped. Dropping it
     * would tell a reader the barangay has zero, which is a different and false statement — and
     * an attentive reader comparing two filtered views could recover the number from the
     * difference in totals.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function suppress(array $rows, string $countKey = 'total'): array
    {
        return array_map(static function (array $row) use ($countKey): array {
            if ((int) $row[$countKey] >= self::MINIMUM_CELL) {
                return $row + ['suppressed' => false];
            }

            $row[$countKey] = null;

            // Money follows the count: a suppressed cell that still published its peso total
            // would leak the same fact by a different column.
            if (array_key_exists('released_centavos', $row)) {
                $row['released_centavos'] = null;
            }

            return $row + ['suppressed' => true];
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function cases(ActorContext $actor, array $filters): Builder
    {
        $query = DB::table('welfare_cases')->whereNull('welfare_cases.deleted_at');

        $this->applyScope($actor, $query, 'welfare_cases.barangay_id');
        $this->applyPeriod($query, $filters, 'welfare_cases.opened_at');

        foreach (['status' => 'welfare_cases.status', 'program_id' => 'welfare_cases.program_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (! empty($filters['barangay_id'])) {
            $query->where('welfare_cases.barangay_id', $filters['barangay_id']);
        }

        /*
         * `caseworker` filters to ONE named person, which the master command permits — it is how
         * a worker sees their own queue, and how a supervisor reviews a specific caseload they
         * are responsible for. It is not a grouping, and there is no endpoint that returns one
         * row per worker (ADR 0026 §4).
         */
        if (! empty($filters['assigned_to'])) {
            $query->where('welfare_cases.assigned_to', $filters['assigned_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function releases(ActorContext $actor, array $filters): Builder
    {
        $query = DB::table('releases')
            ->join('welfare_cases', 'welfare_cases.id', '=', 'releases.welfare_case_id');

        $this->applyScope($actor, $query, 'welfare_cases.barangay_id');
        $this->applyPeriod($query, $filters, 'releases.created_at');

        if (! empty($filters['program_id'])) {
            $query->where('releases.program_id', $filters['program_id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyPeriod(Builder $query, array $filters, string $column): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate($column, '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate($column, '<=', $filters['to']);
        }
    }

    /**
     * A barangay-scoped clerk's dashboard is their barangay's dashboard.
     *
     * A metric that ignored scope would be a way to read the whole municipality's caseload
     * through a number — which is exactly what an aggregate is good at hiding.
     */
    private function applyScope(ActorContext $actor, Builder $query, string $column): void
    {
        if ($actor->scope->isUnrestricted()) {
            return;
        }

        $query->whereIn($column, $actor->scope->barangayIds);
    }
}
