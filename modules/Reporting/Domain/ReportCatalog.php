<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * What may be exported, and what each export costs to ask for.
 *
 * A CLOSED LIST, not a query builder. An export endpoint that accepted arbitrary columns and
 * filters would be a general-purpose data extraction tool with one permission in front of it —
 * and every report anybody ever wanted would be reachable by whoever could reach the easiest one.
 *
 * THERE IS NO PER-CASEWORKER REPORT, and that is deliberate. The master command says not to use
 * reports to create simplistic employee performance rankings, and this is where such a thing would
 * be added — one entry, one `GROUP BY assigned_to`, and the office has a leaderboard.
 *
 * The objection is not squeamishness. A caseworker's open-case count measures the cases they were
 * given: the worker handed the hardest families has the longest queue and the slowest closures,
 * and a ranking presents that as underperformance. Workload by *team* answers the real question —
 * where does the office need more people — without inviting the wrong one.
 */
enum ReportCatalog: string
{
    case CaseSummary = 'case-summary';
    case ProgramUtilization = 'program-utilization';
    case BarangayReach = 'barangay-reach';
    case ReferralOutcomes = 'referral-outcomes';

    /**
     * The one person-level report: a distribution manifest.
     *
     * It exists because a payout table genuinely needs a printed list of who is expected, and
     * refusing it would mean somebody exports the whole case list instead to build one by hand.
     * It is the narrowest person-level export that does the job, and it costs a permission
     * nothing else does.
     */
    case ReleaseManifest = 'release-manifest';

    /**
     * Who signed up for one event.
     *
     * The list a volunteer carries to a covered court when the network there is unusable — which
     * is why it exists at all: the attendance API is the better path, and an office that cannot
     * reach it will otherwise photograph a screen.
     *
     * PERSON-LEVEL. An event is public; being on the list for a supplementary feeding programme is
     * a fact about a household's circumstances. It carries its own permission rather than
     * `report.export.person-level`, because the two lists concern different offices and folding
     * them together would mean whoever could print a payout manifest could also print this
     * (ADR 0031 §6).
     */
    case EventRegistrants = 'event-registrants';

    /**
     * Whether this report names individuals.
     *
     * Declared here rather than inspected after generation. An aggregate export is a statistic; a
     * person-level one is a copy of a caseload, and the difference decides both the permission
     * required and how long the file lives.
     */
    public function isPersonLevel(): bool
    {
        return $this === self::ReleaseManifest || $this === self::EventRegistrants;
    }

    public function requiredPermission(): Permission
    {
        return match (true) {
            /*
             * NOT `report.export.person-level`. Both are person-level and both get its retention
             * and its audit action, but the permission is the *authority to take this particular
             * copy out of the system*, and a door list and a payout manifest are two different
             * authorities held by two different offices (ADR 0031 §6).
             */
            $this === self::EventRegistrants => Permission::EventExportRegistrants,
            $this->isPersonLevel() => Permission::ReportExportPersonLevel,
            default => Permission::ReportView,
        };
    }

    /**
     * How long the produced file survives.
     *
     * A person-level file is short-lived on purpose: a download link that works for a month is a
     * permanent copy of a caseload behind a URL somebody bookmarked, and none of this system's
     * authorization applies to it once it is on a laptop.
     */
    public function retentionHours(): int
    {
        return $this->isPersonLevel() ? 24 : 168;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $report): string => $report->value, self::cases());
    }
}
