<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * How soon the receiving office is being asked to act.
 *
 * ADVISORY TO THEM, OPERATIONAL TO US. It sets this office's default follow-up date and orders
 * this office's own queue. It confers no priority the MSWDO can actually grant over another
 * agency's work, and no screen or payload may imply otherwise — telling a family their referral
 * is "urgent" as though that binds a hospital is a promise this office cannot keep.
 */
enum ReferralUrgency: string
{
    case Routine = 'routine';
    case Priority = 'priority';
    case Urgent = 'urgent';

    /**
     * Days before this office chases.
     *
     * **The office's own convention**, carried over from the console with the same caveat it
     * records there: not yet confirmed against a written issuance (gap G-25).
     */
    public function followUpDays(): int
    {
        return match ($this) {
            self::Routine => 14,
            self::Priority => 7,
            self::Urgent => 2,
        };
    }

    /**
     * Queue order: most urgent first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::Priority => 1,
            self::Routine => 2,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $urgency): string => $urgency->value, self::cases());
    }
}
