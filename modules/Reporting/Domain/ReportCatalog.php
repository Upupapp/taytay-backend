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
     * `report.export-person-level`, because the two lists concern different offices and folding
     * them together would mean whoever could print a payout manifest could also print this
     * (ADR 0031 §6).
     */
    case EventRegistrants = 'event-registrants';

    /*
     * ── added in TAB 07, for the synchronous run ─────────────────────────────────────
     *
     * These three were already computed for the dashboard and had no catalogue entry, so they
     * could be seen and not asked for. Adding them here rather than starting a second catalogue
     * beside this enum is the point: a report's id, its permission, whether it names people and
     * how long its file may be kept are one fact each, and this is where they live.
     */
    case CaseAging = 'case-aging';
    case FieldWorkload = 'field-workload';
    case DataCompleteness = 'data-completeness';

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
             * NOT `report.export-person-level`. Both are person-level and both get its retention
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
    /**
     * The dashboard metric that answers this report, or `null` if it has no synchronous form.
     *
     * A person-level report has none by design: naming people is an **export**, which carries a
     * retention window, an audit entry and a warning the caller has already seen. A synchronous
     * endpoint that returned the same rows would route around all three.
     */
    public function metric(): ?string
    {
        return match ($this) {
            self::CaseSummary => 'summary',
            self::CaseAging => 'caseAging',
            self::BarangayReach => 'barangayReach',
            self::ProgramUtilization => 'programUtilization',
            self::ReferralOutcomes => 'referralOutcomes',
            self::FieldWorkload => 'fieldWorkload',
            self::DataCompleteness => 'dataCompleteness',
            self::ReleaseManifest, self::EventRegistrants => null,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::CaseSummary => 'Social welfare caseload',
            self::CaseAging => 'Case aging',
            self::BarangayReach => 'Reach by barangay',
            self::ProgramUtilization => 'Programme utilisation',
            self::ReferralOutcomes => 'Referral outcomes',
            self::FieldWorkload => 'Field visit workload',
            self::DataCompleteness => 'Data completeness',
            self::ReleaseManifest => 'Payout manifest',
            self::EventRegistrants => 'Event registrants',
        };
    }

    /**
     * The question the report answers, in the words somebody would ask it.
     *
     * Published because a report title is not a question — "Reach by barangay" does not tell a
     * reader whether a blank row means nobody applied or nobody was served, and that difference is
     * the finding.
     */
    public function question(): string
    {
        return match ($this) {
            self::CaseSummary => 'How many cases is the office carrying, and in what state?',
            self::CaseAging => 'How long have open cases been open?',
            self::BarangayReach => 'Which barangays is the office reaching, and which is it not?',
            self::ProgramUtilization => 'Is each programme reaching anybody, and how much has gone out?',
            self::ReferralOutcomes => 'What happened to the people this office referred elsewhere?',
            self::FieldWorkload => 'How much field work is the office carrying, and in what state?',
            self::DataCompleteness => 'Which records are missing the facts the office needs to decide?',
            self::ReleaseManifest => 'Who is on this payout list, and what is each person owed?',
            self::EventRegistrants => 'Who registered for this event?',
        };
    }

    public function area(): string
    {
        return match ($this) {
            self::CaseSummary, self::CaseAging, self::FieldWorkload => 'operations',
            self::ProgramUtilization, self::ReferralOutcomes, self::ReleaseManifest => 'delivery',
            self::BarangayReach, self::EventRegistrants => 'population',
            self::DataCompleteness => 'quality',
        };
    }

    /**
     * Which filters this report accepts.
     *
     * **`caseworker` is false everywhere, on every report, deliberately.** The command:
     * *"No grouping by caseworker — filtering to one named worker is permitted, a leaderboard is
     * not."* Sorting a workload table by volume is what turns it into a league table, and the
     * office's own doctrine reaches the same conclusion. The runner refuses the parameter as well,
     * so this is a description of a rule rather than the rule itself.
     *
     * @return array<string, bool>
     */
    public function filters(): array
    {
        return match ($this) {
            self::DataCompleteness => ['period' => false, 'barangay' => false, 'program' => false, 'status' => false, 'caseworker' => false],
            self::BarangayReach, self::ProgramUtilization => ['period' => true, 'barangay' => true, 'program' => true, 'status' => false, 'caseworker' => false],
            default => ['period' => true, 'barangay' => true, 'program' => false, 'status' => true, 'caseworker' => false],
        };
    }

    public static function values(): array
    {
        return array_map(static fn (self $report): string => $report->value, self::cases());
    }
}
