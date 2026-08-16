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
     * The largest upload accepted for this context, in bytes (ADR 0033 §5).
     *
     * PER CONTEXT, because one global ceiling is wrong at both ends. A multi-page scanned PDF of
     * a barangay certificate genuinely needs several megabytes and refusing it sends a resident
     * back to a photocopier; an advisory image for the public feed needs a fraction of that, and
     * a generous limit there is an invitation to put a 10 MB photograph on a page that people
     * open on mobile data.
     *
     * **Every value must stay below the reverse proxy's `client_max_body_size`.** If nginx
     * rejects the body first it answers 413 without running PHP — and therefore without CORS
     * headers — so a browser sees a network failure with status 0 rather than a message anybody
     * can act on. The runbook carries the required nginx value; these constants are the ones that
     * should win.
     */
    public function maxBytes(): int
    {
        return match ($this) {
            // Published to the feed and re-encoded on the way out, so the original need not be
            // large. It is also the only class anybody outside the office ever downloads.
            self::PublicReference => 4 * 1024 * 1024,
            self::Operational => 8 * 1024 * 1024,
            // The generous one, and deliberately: this is where a resident's multi-page scan
            // lands, and a rejection here is a trip back to a photocopier.
            self::Personal => 10 * 1024 * 1024,
            self::Sensitive => 10 * 1024 * 1024,
        };
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
