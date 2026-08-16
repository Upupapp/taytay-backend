<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

/**
 * How closely a stored object must be held.
 *
 * The master command asks a file record to carry a classification. This is that column, and it
 * is a *decision input*, not a label: retention length, whether a copy may be shared outward and
 * how loudly a read is audited all read from it.
 *
 * Assigned by the module that owns the upload, because only that module knows what the file is.
 * The store refuses to guess — a default of "operational" applied to a safeguarding photograph
 * would be a quiet declassification, and the mistake would be invisible.
 */
enum FileClassification: string
{
    /**
     * No personal data. A programme's blank form, a public advisory image.
     *
     * The only class that could in principle be public, and it still is not: everything lives on
     * the private disk, because a bucket with mixed visibility is one misfiled object away from
     * being a leak (Article 8.5).
     */
    case PublicReference = 'public-reference';

    /** Internal working material naming no resident. */
    case Operational = 'operational';

    /** Identifies a person. Most uploads: identity documents, certificates, proofs of residence. */
    case Personal = 'personal';

    /**
     * RA 9262 / RA 9344 material, health records, biometrics.
     *
     * Never leaves the office by any route this system offers, and every read is audited
     * individually rather than in aggregate.
     */
    case Sensitive = 'sensitive';

    public function isPersonalData(): bool
    {
        return $this === self::Personal || $this === self::Sensitive;
    }

    /**
     * Whether a grant may be issued for sharing a copy outside the office.
     */
    public function mayBeSharedOutward(): bool
    {
        return $this !== self::Sensitive;
    }

    /**
     * How long the object is kept after the record it belongs to closes, in days.
     *
     * Placeholder pending the LGU's approved retention schedule (gap G-25). Deliberately
     * expressed here as one reviewable table rather than scattered across jobs, so approving it
     * is a single small act — the same shape as the vulnerability ruleset in ADR 0015.
     */
    public function retentionDays(): int
    {
        return match ($this) {
            self::PublicReference => 3650,
            self::Operational => 1825,
            self::Personal => 1825,
            // Shortest, deliberately. Holding safeguarding material longer than the case needs
            // is itself the risk.
            self::Sensitive => 1095,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $class): string => $class->value, self::cases());
    }
}
