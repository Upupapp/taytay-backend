<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

/**
 * How closely an audited act needs to be watched.
 *
 * TWO BANDS, NOT FIVE. The question a band answers is operational: does this entry get network
 * identifiers attached, does it survive the longest retention, and does it appear in the digest
 * somebody actually reads. Five bands means three of them are indistinguishable in practice and
 * every author guesses.
 */
enum AuditRisk: string
{
    /** The ordinary work of the office. Recorded, counted, rarely read individually. */
    case Routine = 'routine';

    /**
     * The acts the master command names, and the ones a privacy complaint asks about.
     *
     * Security events, resident merges, verification decisions, sensitive document downloads,
     * case status changes, assessments, approvals and releases, PII exports, role changes,
     * newsfeed moderation and publication, event exports and attendance.
     */
    case High = 'high';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $risk): string => $risk->value, self::cases());
    }
}
